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

    /** What each role can do, shown when creating staff accounts. */
    public const HINTS = [
        self::ADMIN => 'Todo, incluida la configuración y los usuarios.',
        self::REGISTRATION_ADMIN => 'Inscripciones, contactos, check-in de entrada y salidas, y envío de QR.',
        self::REGISTRATION => 'Check-in en la entrada (conferencias, talleres y otras actividades) e inscripciones.',
        self::TRIP_REGISTRATION => 'Solo check-in en el punto de encuentro de las salidas.',
        self::ANALYST => 'Ver inscripciones, actividades y contactos.',
        self::MARKETING => 'Contactos e importación de Excel.',
    ];

    /** Roles that can see the contact list. */
    public const CONTACT_VIEWERS = [self::ADMIN, self::REGISTRATION_ADMIN, self::ANALYST, self::MARKETING];

    /** Roles that can see registrations and activities synced from the plugin. */
    public const REGISTRATION_VIEWERS = [self::ADMIN, self::REGISTRATION_ADMIN, self::REGISTRATION, self::ANALYST];

    /** Roles that check people in at the venue entrance (conferences, workshops, other activities). */
    public const ENTRANCE_DESK = [self::ADMIN, self::REGISTRATION_ADMIN, self::REGISTRATION];

    /** Roles that check people in at an outing's meeting point. */
    public const TRIP_DESK = [self::ADMIN, self::REGISTRATION_ADMIN, self::TRIP_REGISTRATION];

    /** Roles that open the check-in page at all. */
    public const CHECKIN_STAFF = [self::ADMIN, self::REGISTRATION_ADMIN, self::REGISTRATION, self::TRIP_REGISTRATION];

    /** Roles that send participants their QR and see the check-in totals. */
    public const CHECKIN_MANAGERS = [self::ADMIN, self::REGISTRATION_ADMIN];

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
