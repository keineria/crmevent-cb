<?php

namespace App\Http\Requests\Settings;

use App\Models\MailSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->route('profile'), MailSetting::PROFILES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $broadcast = $this->route('profile') === MailSetting::PROFILE_BROADCAST;

        return [
            'provider' => ['required', Rule::in(MailSetting::PROVIDERS)],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', Rule::in([25, 465, 587, 2465, 2587])],
            'encryption' => ['required', Rule::in(['tls', 'ssl'])],
            'aws_region' => ['nullable', 'required_if:provider,ses', 'string', 'max:30', 'regex:/^[a-z]{2}-[a-z]+-\d$/'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_name' => ['required', 'string', 'max:100'],
            'from_address' => ['required', 'email', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'dkim_selector' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9._-]+$/'],
            'hourly_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'track_opens' => ['boolean'],
            'track_clicks' => ['boolean'],
            // Bulk mail must carry the legal footer (Habeas Data + unsubscribe).
            'footer_text' => [$broadcast ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => 'proveedor',
            'host' => 'servidor',
            'port' => 'puerto',
            'encryption' => 'cifrado',
            'aws_region' => 'región de AWS',
            'username' => 'usuario',
            'password' => 'contraseña',
            'from_name' => 'nombre del remitente',
            'from_address' => 'correo del remitente',
            'reply_to' => 'responder a',
            'dkim_selector' => 'selector DKIM',
            'hourly_limit' => 'límite por hora',
            'daily_limit' => 'límite por día',
            'footer_text' => 'pie legal',
        ];
    }
}
