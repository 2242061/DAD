<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\AccountSecurity;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class AccountController extends Controller
{
    public function update(UpdateProfileRequest $request, AccountSecurity $security): JsonResponse
    {
        $user = $request->user();
        $emailChanged = $user->email !== $request->validated('email');
        DB::transaction(function () use ($request, $security, $user, $emailChanged) {
            if ($emailChanged) {
                Password::broker()->deleteToken($user);
            }
            $user->fill($request->safe()->only(['name', 'email']));
            $fields = array_keys($user->getDirty());
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();
            if ($emailChanged) {
                $security->revokeAccess($user, $request->session()->getId());
                $request->session()->put('auth_version', $user->auth_version);
            }
            if ($fields) {
                $security->record($user, $user, 'profile.updated', ['fields' => $fields]);
            }
        });
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
            $request->session()->regenerate();
        }

        return (new UserResource($user))->response()->setStatusCode(200);
    }

    public function password(ChangePasswordRequest $request, AccountSecurity $security): Response
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $security, $user) {
            $user->password = $request->validated('password');
            $user->save();
            $security->revokeAccess($user);
            $security->record($user, $user, 'password.changed');
        });
        $this->endSession($request);

        return response()->noContent();
    }

    public function destroy(Request $request, AccountSecurity $security): Response
    {
        abort_if($request->user()->role === UserRole::Admin, 403, 'Um administrador não pode eliminar a própria conta.');
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'confirmation' => ['required', 'in:ELIMINAR'],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($security, $user) {
            $security->revokeAccess($user);
            $security->record($user, $user, 'account.deleted');
            $user->delete();
        });
        $this->endSession($request);

        return response()->noContent();
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
