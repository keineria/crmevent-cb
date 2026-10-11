<?php

namespace App\Services\Mail;

use App\Models\MailSetting;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Builds mailers from the settings saved in Configuración › Correo.
 */
class CrmMailer
{
    /**
     * Mailer for a profile, using only its last verified configuration.
     */
    public function for(string $profile): Mailer
    {
        $setting = MailSetting::where('profile', $profile)->first();

        if (! $setting?->isActive()) {
            throw new RuntimeException("El perfil de correo «{$profile}» no tiene una configuración verificada.");
        }

        return $this->build($setting->active_config);
    }

    /**
     * @param  array<string, mixed>  $config  Output of MailSetting::transportConfig().
     */
    public function build(array $config): Mailer
    {
        $mailer = Mail::build($config);

        if (! empty($config['from']['address'])) {
            $mailer->alwaysFrom($config['from']['address'], $config['from']['name'] ?? null);
        }

        if (! empty($config['reply_to'])) {
            $mailer->alwaysReplyTo($config['reply_to']);
        }

        return $mailer;
    }
}
