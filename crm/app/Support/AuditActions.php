<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Expense;
use App\Models\MailSetting;
use App\Models\MailTemplate;
use App\Models\PluginConnection;
use App\Models\RegistrationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Names of the actions written to the audit log, in the words admins read on the Auditoría page.
 */
final class AuditActions
{
    /** Module => label, in the order of the filter. */
    public const MODULES = [
        'session' => 'Accesos',
        'user' => 'Usuarios',
        'checkin' => 'Check-in',
        'participant' => 'Participantes',
        'campaign' => 'Campañas',
        'contacts' => 'Contactos',
        'finance' => 'Finanzas',
        'reports' => 'Reportes',
        'settings' => 'Configuración',
    ];

    /** Action => [module, label]. */
    public const ACTIONS = [
        'auth.login' => ['session', 'Inició sesión'],
        'auth.logout' => ['session', 'Cerró sesión'],
        'auth.failed' => ['session', 'Intento de acceso fallido'],
        'auth.lockout' => ['session', 'Acceso bloqueado por intentos'],
        'auth.blocked' => ['session', 'Intentó entrar con un usuario desactivado'],
        'auth.password_reset' => ['session', 'Restableció su contraseña'],

        'user.created' => ['user', 'Creó un usuario'],
        'user.updated' => ['user', 'Editó un usuario'],
        'user.activated' => ['user', 'Activó un usuario'],
        'user.deactivated' => ['user', 'Desactivó un usuario'],
        'user.deleted' => ['user', 'Borró un usuario'],
        'profile.updated' => ['user', 'Cambió su perfil'],
        'profile.deleted' => ['user', 'Borró su propia cuenta'],
        'password.updated' => ['user', 'Cambió su contraseña'],

        'checkin.created' => ['checkin', 'Registró un ingreso'],
        'checkin.undone' => ['checkin', 'Deshizo un ingreso'],
        'checkin.qr_sent' => ['checkin', 'Envió el QR a una persona'],
        'checkin.qr_bulk' => ['checkin', 'Envió los QR pendientes'],

        'participant.updated' => ['participant', 'Corrigió datos de un participante'],
        'activity.replaced' => ['participant', 'Cambió una actividad'],
        'activity.cancelled' => ['participant', 'Retiró una actividad'],
        'activity.added' => ['participant', 'Agregó actividades'],

        'campaign.created' => ['campaign', 'Creó una campaña'],
        'campaign.updated' => ['campaign', 'Editó una campaña'],
        'campaign.deleted' => ['campaign', 'Borró un borrador de campaña'],
        'campaign.duplicated' => ['campaign', 'Duplicó una campaña'],
        'campaign.tested' => ['campaign', 'Envió una prueba de campaña'],
        'campaign.launched' => ['campaign', 'Lanzó una campaña'],
        'campaign.scheduled' => ['campaign', 'Programó una campaña'],
        'campaign.paused' => ['campaign', 'Pausó una campaña'],
        'campaign.resumed' => ['campaign', 'Reanudó una campaña'],
        'campaign.unscheduled' => ['campaign', 'Quitó la programación de una campaña'],
        'campaign.cancelled' => ['campaign', 'Canceló una campaña'],
        'campaign.retried' => ['campaign', 'Reintentó correos fallidos'],
        'template.created' => ['campaign', 'Creó una plantilla'],
        'template.updated' => ['campaign', 'Editó una plantilla'],
        'template.deleted' => ['campaign', 'Borró una plantilla'],

        'contacts.uploaded' => ['contacts', 'Subió un Excel de contactos'],
        'contacts.imported' => ['contacts', 'Importó contactos'],
        'contacts.template' => ['contacts', 'Descargó la plantilla de contactos'],
        'contacts.created' => ['contacts', 'Creó un contacto'],
        'contacts.updated' => ['contacts', 'Editó un contacto'],

        'expense.created' => ['finance', 'Registró un costo'],
        'expense.updated' => ['finance', 'Editó un costo'],
        'expense.deleted' => ['finance', 'Borró un costo'],

        'report.exported' => ['reports', 'Descargó un CSV de reportes'],

        'audit.exported' => ['settings', 'Descargó la auditoría'],
        'mail_setting.updated' => ['settings', 'Cambió el correo de envíos'],
        'mail_setting.test_failed' => ['settings', 'Falló la prueba del correo de envíos'],
        'mail_setting.activated' => ['settings', 'Verificó y activó el correo de envíos'],
        'plugin-connection.updated' => ['settings', 'Cambió la conexión con el plugin'],
        'plugin-connection.tested' => ['settings', 'Probó la conexión con el plugin'],
        'plugin-connection.synced' => ['settings', 'Sincronizó inscripciones del plugin'],
    ];

    public static function label(string $action): string
    {
        return self::ACTIONS[$action][1] ?? $action;
    }

    public static function module(string $action): ?string
    {
        return self::ACTIONS[$action][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function inModule(string $module): array
    {
        return array_keys(array_filter(self::ACTIONS, fn ($a) => $a[0] === $module));
    }

    /** Readable name of what an action was done to, kept with the entry. */
    public static function subjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => "{$subject->name} <{$subject->email}>",
            $subject instanceof RegistrationParticipant => trim("{$subject->full_name} · {$subject->document_number}", ' ·'),
            $subject instanceof Campaign, $subject instanceof MailTemplate => (string) $subject->name,
            $subject instanceof Expense => (string) $subject->concept,
            $subject instanceof ContactImport => (string) $subject->original_name,
            $subject instanceof Contact => trim($subject->fullName().' · '.($subject->email ?? $subject->document_number ?? ''), ' ·') ?: "Contacto #{$subject->id}",
            $subject instanceof MailSetting => "Perfil {$subject->profile}",
            $subject instanceof PluginConnection => 'Plugin de inscripciones',
            default => class_basename($subject)." #{$subject->getKey()}",
        };
    }
}
