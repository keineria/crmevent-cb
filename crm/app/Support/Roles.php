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
        self::REGISTRATION_ADMIN => 'Inscripciones, contactos, check-in de entrada y salidas, envío de QR, certificados, y cambiar, retirar o agregar actividades.',
        self::REGISTRATION => 'Check-in en la entrada (conferencias, talleres y otras actividades), inscripciones y corregir datos básicos.',
        self::TRIP_REGISTRATION => 'Check-in en el punto de encuentro de las salidas y corregir datos básicos.',
        self::ANALYST => 'Ver inscripciones, actividades, contactos, reportes y finanzas.',
        self::MARKETING => 'Contactos, importación de Excel, plantillas, campañas de correo y certificados.',
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

    /** Roles that correct a participant's contact details at the desk (written to the plugin). */
    public const PARTICIPANT_EDITORS = [self::ADMIN, self::REGISTRATION_ADMIN, self::REGISTRATION, self::TRIP_REGISTRATION];

    /** Roles that change, remove or add a participant's activities (written to the plugin). */
    public const ACTIVITY_EDITORS = [self::ADMIN, self::REGISTRATION_ADMIN];

    /** Roles that send participants their QR and see the check-in totals. */
    public const CHECKIN_MANAGERS = [self::ADMIN, self::REGISTRATION_ADMIN];

    /** Roles that edit the email that carries the check-in QR. */
    public const QR_MAIL_EDITORS = [self::ADMIN, self::REGISTRATION_ADMIN, self::MARKETING];

    /** Roles that see campaigns and their results. */
    public const CAMPAIGN_VIEWERS = [self::ADMIN, self::MARKETING, self::ANALYST];

    /** Roles that write templates and send campaigns. */
    public const CAMPAIGN_EDITORS = [self::ADMIN, self::MARKETING];

    /** Roles that see the edition reports (read-only). */
    public const REPORT_VIEWERS = [self::ADMIN, self::REGISTRATION_ADMIN, self::ANALYST];

    /** Roles that see sales, costs and profit. */
    public const FINANCE_VIEWERS = [self::ADMIN, self::ANALYST];

    /** Roles that record and change costs. */
    public const FINANCE_EDITORS = [self::ADMIN];

    /** Roles that design attendance certificates, tie them to activities and send them. */
    public const CERTIFICATE_MANAGERS = [self::ADMIN, self::REGISTRATION_ADMIN, self::MARKETING];

    /** Roles that add contacts one by one and edit them (preferred product, profile, Activo / Desinscrito). */
    public const CONTACT_EDITORS = [self::ADMIN, self::REGISTRATION_ADMIN, self::MARKETING];

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
