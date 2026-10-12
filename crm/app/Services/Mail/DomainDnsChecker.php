<?php

namespace App\Services\Mail;

use Closure;

/**
 * Checks the sender domain's SPF, DKIM and DMARC records so the admin can fix deliverability before sending.
 */
class DomainDnsChecker
{
    /** @var Closure(string, int): array<int, array<string, mixed>> */
    private Closure $resolver;

    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host, int $type): array => @dns_get_record($host, $type) ?: [];
    }

    /**
     * `dkim` is null when no selector was given.
     *
     * @return array{domain: string, spf: bool, dkim: bool|null, dmarc: bool}
     */
    public function check(string $domain, ?string $dkimSelector = null): array
    {
        $domain = strtolower(trim($domain));

        return [
            'domain' => $domain,
            'spf' => $this->hasTxt($domain, 'v=spf1'),
            'dkim' => $dkimSelector ? $this->hasDkim("{$dkimSelector}._domainkey.{$domain}") : null,
            'dmarc' => $this->hasTxt("_dmarc.{$domain}", 'v=DMARC1'),
        ];
    }

    private function hasTxt(string $host, string $prefix): bool
    {
        foreach (($this->resolver)($host, DNS_TXT) as $record) {
            if (str_starts_with(strtolower(trim($record['txt'] ?? '')), strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    private function hasDkim(string $host): bool
    {
        // Providers publish DKIM either as a TXT key (Brevo, Resend) or a CNAME to their key (SES).
        foreach (($this->resolver)($host, DNS_TXT | DNS_CNAME) as $record) {
            if (($record['type'] ?? '') === 'CNAME' || str_contains(strtolower($record['txt'] ?? ''), 'p=')) {
                return true;
            }
        }

        return false;
    }
}
