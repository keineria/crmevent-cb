<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-ins and sign-outs go to the audit log; failed and blocked attempts are written by LoginRequest.
        Event::listen(function (Login $event) {
            if (! $event->user instanceof User) {
                return;
            }
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            AuditLog::record('auth.login', $event->user, $event->remember ? ['recordarme' => true] : [], $event->user);
        });

        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                AuditLog::record('auth.logout', $event->user, [], $event->user);
            }
        });

        Event::listen(function (Lockout $event) {
            $email = mb_strtolower(trim((string) $event->request->input('email')));
            AuditLog::record('auth.lockout', User::where('email', $email)->first(), ['correo' => Str::limit($email, 120)]);
        });

        Event::listen(function (PasswordReset $event) {
            if ($event->user instanceof User) {
                AuditLog::record('auth.password_reset', $event->user, [], $event->user);
            }
        });
    }
}
