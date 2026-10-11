<?php

namespace App\Support;

/**
 * Staff roles of the CRM. Names are shown in the UI, so they stay in Spanish.
 */
final class Roles
{
    public const ADMIN = 'Admin';

    public const REGISTRATION_ADMIN = 'Admin registro';

    public const REGISTRATION = 'Registro';

    public const TRIP_REGISTRATION = 'Registro salidas';

    public const ANALYST = 'Analista';

    public const MARKETING = 'Marketing';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN,
            self::REGISTRATION_ADMIN,
            self::REGISTRATION,
            self::TRIP_REGISTRATION,
            self::ANALYST,
            self::MARKETING,
        ];
    }
}
