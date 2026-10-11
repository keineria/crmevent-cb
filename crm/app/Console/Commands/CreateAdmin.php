<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'crm:create-admin {email} {name}';

    protected $description = 'Crea (o promueve) un usuario con el rol Admin';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->argument('name');
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => $password,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(Roles::ADMIN);

        $this->info("Admin listo: {$email}");

        return self::SUCCESS;
    }
}
