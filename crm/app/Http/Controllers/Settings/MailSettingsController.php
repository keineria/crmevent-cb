<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MailSettingRequest;
use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Services\Mail\DomainDnsChecker;
use App\Services\Mail\MailSettingTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = collect(MailSetting::PROFILES)->mapWithKeys(fn (string $profile) => [
            $profile => $this->present(MailSetting::forProfile($profile)),
        ]);

        return Inertia::render('settings/mail', [
            'settings' => $settings,
            'webhookUrl' => url('/api/v1/webhooks/mail'),
        ]);
    }

    public function update(MailSettingRequest $request, string $profile): RedirectResponse
    {
        $setting = MailSetting::forProfile($profile);
        $data = $request->validated();

        // An empty password field means "keep the saved one".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $setting->fill([...$data, 'updated_by' => $request->user()->id]);
        $transportChanged = $setting->isDirty(MailSetting::TRANSPORT_FIELDS);

        $changed = collect($setting->getDirty())
            ->except(['password', 'updated_by'])
            ->keys()
            ->when($setting->isDirty('password'), fn ($keys) => $keys->push('password'))
            ->values()
            ->all();

        if ($transportChanged) {
            // The previous verified snapshot keeps sending until a test passes with the new values.
            $setting->verified_at = null;
        }

        $setting->save();
        AuditLog::record('mail_setting.updated', $setting, ['profile' => $profile, 'fields' => $changed]);

        return back()->with('success', $transportChanged
            ? 'Guardado. Envía un correo de prueba para activar estos datos; mientras tanto se sigue usando la configuración anterior.'
            : 'Guardado.');
    }

    public function test(Request $request, string $profile, MailSettingTester $tester): RedirectResponse
    {
        abort_unless(in_array($profile, MailSetting::PROFILES, true), 404);
        $validated = $request->validate(['to' => ['required', 'email']]);

        $setting = MailSetting::where('profile', $profile)->first();

        if (! $setting || blank($setting->host) || blank($setting->from_address)) {
            return back()->with('error', 'Guarda primero el servidor y el remitente.');
        }

        try {
            $tester->send($setting, $validated['to']);
        } catch (Throwable $e) {
            $setting->forceFill(['last_test_error' => Str::limit($e->getMessage(), 1000)])->save();
            AuditLog::record('mail_setting.test_failed', $setting, ['profile' => $profile]);

            return back()->with('error', 'La prueba falló y la configuración no se activó: '.Str::limit($e->getMessage(), 300));
        }

        $setting->forceFill([
            'active_config' => $setting->transportConfig(),
            'verified_at' => now(),
            'last_test_error' => null,
        ])->save();
        AuditLog::record('mail_setting.activated', $setting, ['profile' => $profile]);

        return back()->with('success', "Correo de prueba enviado a {$validated['to']}. La configuración quedó activa.");
    }

    public function dns(string $profile, DomainDnsChecker $checker): RedirectResponse
    {
        abort_unless(in_array($profile, MailSetting::PROFILES, true), 404);
        $setting = MailSetting::where('profile', $profile)->first();

        if (! $setting?->from_address) {
            return back()->with('error', 'Guarda primero el correo remitente.');
        }

        $domain = Str::after($setting->from_address, '@');

        return back()->with('dns', [$profile => $checker->check($domain, $setting->dkim_selector)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MailSetting $setting): array
    {
        return [
            ...$setting->only([
                'profile', 'provider', 'host', 'port', 'encryption', 'aws_region', 'username',
                'from_name', 'from_address', 'reply_to', 'dkim_selector', 'hourly_limit', 'daily_limit',
                'track_opens', 'track_clicks', 'footer_text', 'last_test_error',
            ]),
            'password_hint' => $setting->maskedPassword(),
            'is_active' => $setting->isActive(),
            'pending_test' => $setting->exists && $setting->verified_at === null,
            'verified_at' => $setting->verified_at?->toIso8601String(),
            'active_from' => $setting->active_config['from']['address'] ?? null,
            'active_host' => $setting->active_config['host'] ?? null,
        ];
    }
}
