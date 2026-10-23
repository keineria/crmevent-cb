<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class PasswordResetLink extends Command
{
    protected $signature = 'crm:reset-link {email}';

    protected $description = 'Muestra un enlace para restablecer la contraseña de un usuario, sin enviar correo';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if (! $user) {
            $this->error('No hay ningún usuario con ese correo.');

            return self::FAILURE;
        }

        $token = Password::broker()->createToken($user);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        $this->info("Abre este enlace en los próximos {$minutes} minutos para crear la contraseña nueva:");
        $this->line(route('password.reset', ['token' => $token, 'email' => $user->email]));

        return self::SUCCESS;
    }
}
