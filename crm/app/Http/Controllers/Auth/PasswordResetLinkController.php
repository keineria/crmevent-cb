<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = mb_strtolower(trim((string) $request->input('email')));

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el enlace para restablecer la contraseña', ['email' => $email, 'error' => $e->getMessage()]);
            AuditLog::record('auth.password_reset_failed', User::where('email', $email)->first(), [
                'correo' => Str::limit($email, 120),
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            throw ValidationException::withMessages([
                'email' => 'No pudimos enviar el correo en este momento. Revisa que la cuenta de envío de Configuración › Correo esté activa, o pide a un administrador que te asigne una contraseña nueva.',
            ]);
        }

        if ($status === Password::ResetLinkSent) {
            AuditLog::record('auth.password_reset_requested', User::where('email', $email)->first(), ['correo' => Str::limit($email, 120)]);
        }

        // The same answer whether or not the account exists, so the form can't be used to discover accounts.
        return back()->with('status', 'Si el correo pertenece a una cuenta activa, en unos minutos te llegará un enlace para crear una contraseña nueva. Revisa también la carpeta de spam.');
    }
}
