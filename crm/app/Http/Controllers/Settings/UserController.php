<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff accounts: who can log in and with which roles. Desk staff for check-in are created here.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::with('roles:id,name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->pluck('name'),
                'is_me' => $u->id === $request->user()->id,
            ]),
            'roles' => Roles::all(),
            'roleHints' => Roles::HINTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', Password::min(10)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(Roles::all())],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles($data['roles']);
        AuditLog::record('user.created', $user, ['roles' => $data['roles']]);

        return back()->with('success', "Usuario creado: {$user->email}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['nullable', Password::min(10)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(Roles::all())],
        ]);

        if ($user->is($request->user()) && ! in_array(Roles::ADMIN, $data['roles'], true)) {
            return back()->with('error', 'No puedes quitarte el rol Admin a ti mismo.');
        }

        $user->name = $data['name'];
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles($data['roles']);
        AuditLog::record('user.updated', $user, ['roles' => $data['roles'], 'password_changed' => filled($data['password'] ?? null)]);

        return back()->with('success', "Usuario actualizado: {$user->email}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'No puedes borrar tu propio usuario.');
        }

        AuditLog::record('user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        return back()->with('success', "Usuario borrado: {$user->email}.");
    }
}
