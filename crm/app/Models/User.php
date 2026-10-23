<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use App\Services\Mail\CrmMailer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** A deactivated account keeps its history but can no longer sign in. */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Reset links go out through the account saved in Configuración › Correo (transactional first,
     * then the broadcast one), not the server's default mailer.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (! $this->isActive()) {
            return;
        }

        $url = route('password.reset', ['token' => $token, 'email' => $this->email]);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        app(CrmMailer::class)
            ->firstActive(MailSetting::PROFILE_TRANSACTIONAL, MailSetting::PROFILE_BROADCAST)
            ->to($this->email)
            ->send(new PasswordResetMail($this->name, $url, $minutes));
    }
}
