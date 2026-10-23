<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Certificate;
use App\Models\CertificateCategory;
use App\Models\CertificateRecipient;
use App\Models\CertificateSigner;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\EventActivity;
use App\Models\Expense;
use App\Models\MailSetting;
use App\Models\MailTemplate;
use App\Models\PluginConnection;
use App\Models\RegistrationParticipant;
use App\Models\StandWorkbook;
use App\Models\Survey;
use App\Models\SurveyForm;
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
        'certificates' => 'Certificados',
        'surveys' => 'Encuestas',
        'contacts' => 'Contactos',
        'finance' => 'Finanzas',
        'reports' => 'Reportes',
        'stands' => 'Stand comercial',
        'settings' => 'Configuración',
    ];

    /** Action => [module, label]. */
    public const ACTIONS = [
        'auth.login' => ['session', 'Inició sesión'],
        'auth.logout' => ['session', 'Cerró sesión'],
        'auth.failed' => ['session', 'Intento de acceso fallido'],
        'auth.lockout' => ['session', 'Acceso bloqueado por intentos'],
        'auth.blocked' => ['session', 'Intentó entrar con un usuario desactivado'],
        'auth.password_reset_requested' => ['session', 'Pidió un enlace para restablecer su contraseña'],
        'auth.password_reset_failed' => ['session', 'No se pudo enviar el enlace para restablecer la contraseña'],
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

        'certificate.category_created' => ['certificates', 'Creó una categoría de certificados'],
        'certificate.category_updated' => ['certificates', 'Editó el diseño de una categoría de certificados'],
        'certificate.category_file' => ['certificates', 'Cambió una imagen de una categoría de certificados'],
        'certificate.category_deleted' => ['certificates', 'Borró una categoría de certificados'],
        'certificate.signer_created' => ['certificates', 'Agregó un firmante de certificados'],
        'certificate.signer_updated' => ['certificates', 'Editó un firmante de certificados'],
        'certificate.signer_deleted' => ['certificates', 'Borró un firmante de certificados'],
        'certificate.created' => ['certificates', 'Asoció un certificado a una actividad'],
        'certificate.updated' => ['certificates', 'Editó un certificado'],
        'certificate.deleted' => ['certificates', 'Quitó un certificado de una actividad'],
        'certificate.tested' => ['certificates', 'Envió una prueba de certificado'],
        'certificate.sent' => ['certificates', 'Envió los certificados de una actividad'],
        'certificate.resent' => ['certificates', 'Reenvió el certificado a una persona'],
        'certificate.downloaded' => ['certificates', 'Descargó el certificado de una persona'],
        'certificate.mail_updated' => ['certificates', 'Editó el correo del certificado'],

        'survey.form_updated' => ['surveys', 'Editó las preguntas de una encuesta'],
        'survey.enabled' => ['surveys', 'Activó la encuesta automática'],
        'survey.disabled' => ['surveys', 'Apagó la encuesta automática'],
        'survey.sent' => ['surveys', 'Envió la encuesta de una actividad'],
        'survey.resent' => ['surveys', 'Reenvió la encuesta a una persona'],
        'survey.exported' => ['surveys', 'Descargó las respuestas de una encuesta'],
        'survey.tested' => ['surveys', 'Envió una prueba del correo de la encuesta'],
        'survey.mail_updated' => ['surveys', 'Editó el correo de la encuesta'],

        'contacts.uploaded' => ['contacts', 'Subió un Excel de contactos'],
        'contacts.imported' => ['contacts', 'Importó contactos'],
        'contacts.template' => ['contacts', 'Descargó la plantilla de contactos'],
        'contacts.created' => ['contacts', 'Creó un contacto'],
        'contacts.updated' => ['contacts', 'Editó un contacto'],

        'expense.created' => ['finance', 'Registró un costo'],
        'expense.updated' => ['finance', 'Editó un costo'],
        'expense.deleted' => ['finance', 'Borró un costo'],

        'report.exported' => ['reports', 'Descargó un CSV de reportes'],

        'stands.uploaded' => ['stands', 'Subió un Excel del stand comercial'],
        'stands.replaced' => ['stands', 'Reemplazó un Excel del stand comercial'],
        'stands.cell_updated' => ['stands', 'Editó una celda del stand comercial'],
        'stands.downloaded' => ['stands', 'Descargó un Excel del stand comercial'],
        'stands.deleted' => ['stands', 'Borró un Excel del stand comercial'],
        'stands.google_key' => ['stands', 'Cambió la clave de Google del stand comercial'],
        'stands.google_connected' => ['stands', 'Conectó una hoja de Google al stand comercial'],
        'stands.purchase_updated' => ['stands', 'Marcó el seguimiento de un stand comprado'],

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
            $subject instanceof CertificateCategory => (string) $subject->name,
            $subject instanceof CertificateSigner => trim("{$subject->name} · {$subject->role}", ' ·'),
            $subject instanceof SurveyForm => 'Encuesta de '.mb_strtolower(EventActivity::TYPE_LABELS[$subject->activity_type] ?? $subject->activity_type),
            $subject instanceof Survey => "Encuesta · {$subject->activity_name}",
            $subject instanceof Certificate => "{$subject->activity_name} · ".($subject->category?->name ?? ''),
            $subject instanceof CertificateRecipient => trim("{$subject->full_name} · {$subject->code}", ' ·'),
            $subject instanceof Expense => (string) $subject->concept,
            $subject instanceof ContactImport => (string) $subject->original_name,
            $subject instanceof Contact => trim($subject->fullName().' · '.($subject->email ?? $subject->document_number ?? ''), ' ·') ?: "Contacto #{$subject->id}",
            $subject instanceof MailSetting => "Perfil {$subject->profile}",
            $subject instanceof PluginConnection => 'Plugin de inscripciones',
            $subject instanceof StandWorkbook => (string) $subject->name,
            default => class_basename($subject)." #{$subject->getKey()}",
        };
    }
}
