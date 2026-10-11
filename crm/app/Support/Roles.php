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

    /** Roles that can see the contact list. */
    public const CONTACT_VIEWERS = [self::ADMIN, self::REGISTRATION_ADMIN, self::ANALYST, self::MARKETING];

    /** Roles that can import spreadsheets of contacts. */
    public const CONTACT_IMPORTERS = [self::ADMIN, self::MARKETING];

    /**
     * Value for the `role:` route middleware.
     *
     * @param  list<string>  $roles
     */
    public static function middleware(array $roles): string
    {
        return 'role:'.implode('|', $roles);
    }

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
