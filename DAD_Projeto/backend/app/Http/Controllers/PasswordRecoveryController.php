<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\SafePasswordValue;
use App\Services\AccountSecurity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    private function normalizeEmail(Request $request): void
    {
        $request->merge(['email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email')]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        Password::broker()->sendResetLink($data + ['blocked_at' => null]);

        return response()->json(['message' => 'Se existir uma conta ativa com este email, receberás um link de recuperação.']);
    }

    public function reset(Request $request, AccountSecurity $security): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', new SafePasswordValue, PasswordRule::min(8)->letters()->numbers()],
        ]);
        $status = DB::transaction(function () use ($data, $security) {
            return Password::broker()->reset($data + ['password_confirmation' => request('password_confirmation'), 'blocked_at' => null], function (User $user, string $password) use ($security) {
                $user->password = $password;
                $user->save();
                $security->revokeAccess($user);
                $security->record($user, $user, 'password.reset');
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Este link é inválido, já foi utilizado ou expirou. Pede um novo link.']);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Palavra-passe atualizada. Entra com a nova palavra-passe.']);
    }
}
