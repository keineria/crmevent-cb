<?php

namespace App\Services\Passes;

use App\Models\RegistrationParticipant;

/**
 * Public links to a participant's calendar and wallet passes. Each link carries a token tied to the participant and
 * to what it opens, so the calendar link shown in the portal can't be turned into the wallet link (which has the QR).
 */
class PassLinks
{
    public const CALENDAR = 'calendar';

    public const WALLET = 'wallet';

    public static function token(RegistrationParticipant $participant, string $kind): string
    {
        return substr(hash_hmac('sha256', "{$kind}|{$participant->id}", (string) config('app.key')), 0, 32);
    }

    public static function valid(RegistrationParticipant $participant, string $kind, string $token): bool
    {
        return hash_equals(self::token($participant, $kind), $token);
    }

    public static function calendar(RegistrationParticipant $participant): string
    {
        return route('passes.calendar', [$participant, self::token($participant, self::CALENDAR)]);
    }

    public static function apple(RegistrationParticipant $participant): ?string
    {
        return AppleWalletPass::configured() ? route('passes.apple', [$participant, self::token($participant, self::WALLET)]) : null;
    }

    public static function google(RegistrationParticipant $participant): ?string
    {
        return GoogleWalletPass::configured() ? route('passes.google', [$participant, self::token($participant, self::WALLET)]) : null;
    }
}
