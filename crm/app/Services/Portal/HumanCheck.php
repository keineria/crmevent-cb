<?php

namespace App\Services\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Confirms a person, not a script, is asking. Uses Cloudflare Turnstile when its keys are configured;
 * otherwise a small sum the CRM keeps in the session.
 */
class HumanCheck
{
    private const SESSION_KEY = 'portal_challenge';

    public function usesTurnstile(): bool
    {
        return filled(config('crm.turnstile.site_key')) && filled(config('crm.turnstile.secret'));
    }

    /**
     * What the page needs to show the check.
     *
     * @return array{type: string, site_key?: string, question?: string}
     */
    public function challenge(Request $request): array
    {
        if ($this->usesTurnstile()) {
            return ['type' => 'turnstile', 'site_key' => (string) config('crm.turnstile.site_key')];
        }

        $pair = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pair)) {
            $pair = [random_int(2, 9), random_int(2, 9)];
            $request->session()->put(self::SESSION_KEY, $pair);
        }

        return ['type' => 'sum', 'question' => "¿Cuánto es {$pair[0]} + {$pair[1]}?"];
    }

    /**
     * Each sum is good for one try, right or wrong.
     */
    public function passes(Request $request, string $answer): bool
    {
        if ($this->usesTurnstile()) {
            try {
                return (bool) Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('crm.turnstile.secret'),
                    'response' => $answer,
                    'remoteip' => $request->ip(),
                ])->json('success');
            } catch (Throwable) {
                return false;
            }
        }

        $pair = $request->session()->pull(self::SESSION_KEY);

        return is_array($pair) && trim($answer) === (string) ($pair[0] + $pair[1]);
    }
}
