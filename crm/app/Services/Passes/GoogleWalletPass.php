<?php

namespace App\Services\Passes;

use App\Models\RegistrationParticipant;
use App\Support\Branding;
use RuntimeException;

/**
 * "Save to Google Wallet" link for the participant's check-in QR.
 *
 * The link carries a JWT signed with the service account key. It holds both the event ticket class (created by
 * Google on first save) and the participant's ticket, so nothing has to be registered through the API beforehand.
 */
class GoogleWalletPass
{
    public function __construct(private ParticipantSchedule $schedule) {}

    public static function configured(): bool
    {
        $c = config('crm.wallet.google');

        return filled($c['issuer_id']) && filled($c['service_account']) && is_readable($c['service_account']);
    }

    public function saveUrl(RegistrationParticipant $participant): string
    {
        if (! self::configured()) {
            throw new RuntimeException('Google Wallet no está configurado.');
        }

        $account = json_decode((string) file_get_contents(config('crm.wallet.google.service_account')), true);
        if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new RuntimeException('El archivo de la cuenta de servicio de Google Wallet no es válido.');
        }

        $claims = [
            'iss' => $account['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => now()->getTimestamp(),
            'origins' => [rtrim((string) config('app.url'), '/')],
            'payload' => [
                'eventTicketClasses' => [$this->ticketClass()],
                'eventTicketObjects' => [$this->ticket($participant)],
            ],
        ];

        return 'https://pay.google.com/gp/v/save/'.$this->jwt($claims, $account['private_key']);
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketClass(): array
    {
        $config = config('crm.wallet');
        $class = [
            'id' => $this->classId(),
            'issuerName' => $config['organization'],
            'eventName' => self::text($config['event_name']),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => $config['background'],
        ];

        $logo = Branding::url(Branding::EMAIL);
        if ($logo && str_starts_with($logo, 'https://')) {
            $class['logo'] = ['sourceUri' => ['uri' => $logo]];
        }

        return $class;
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(RegistrationParticipant $participant): array
    {
        $entries = $this->schedule->for($participant);
        $ticket = [
            'id' => config('crm.wallet.google.issuer_id').'.'.$participant->checkin_code,
            'classId' => $this->classId(),
            'state' => 'ACTIVE',
            'ticketHolderName' => $participant->full_name,
            'ticketNumber' => $participant->checkin_code,
            'hexBackgroundColor' => config('crm.wallet.background'),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $participant->checkin_code,
                'alternateText' => $participant->checkin_code,
            ],
            'textModulesData' => [[
                'id' => 'actividades',
                'header' => 'Tus actividades',
                'body' => $entries->isEmpty()
                    ? 'Aún no tienes actividades con fecha.'
                    : $entries->map(fn (array $e) => $e['name'].' — '.$e['start']->locale('es')->translatedFormat($e['all_day'] ? 'D j M' : 'D j M H:i'))->implode("\n"),
            ]],
        ];

        if ($entries->isNotEmpty()) {
            $ticket['validTimeInterval'] = [
                'start' => ['date' => $entries->first()['start']->toIso8601String()],
                'end' => ['date' => $entries->max(fn (array $e) => $e['end'])->toIso8601String()],
            ];
        }

        return $ticket;
    }

    private function classId(): string
    {
        $suffix = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) config('crm.wallet.google.class_suffix'));

        return config('crm.wallet.google.issuer_id').'.'.$suffix;
    }

    /**
     * @return array{defaultValue: array{language: string, value: string}}
     */
    private static function text(string $value): array
    {
        return ['defaultValue' => ['language' => 'es', 'value' => $value]];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims, string $privateKey): string
    {
        $segments = [
            self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el pase de Google Wallet.');
        }
        $segments[] = self::base64url($signature);

        return implode('.', $segments);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
