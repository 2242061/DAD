<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountSecurity;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class AdminUserController extends Controller
{
    private function protect(User $actor, User $target, bool $removingAdmin): void
    {
        $currentActor = User::find($actor->id);
        abort_unless($currentActor && $currentActor->role === UserRole::Admin && ! $currentActor->blocked_at, 403, 'O teu acesso de administrador terminou.');
        abort_if($actor->id === $target->id, 403, 'Para alterar os teus dados, usa A minha conta. Não podes bloquear, eliminar ou alterar o teu próprio papel.');
        if ($removingAdmin && $target->role === UserRole::Admin && ! $target->blocked_at && ! $target->trashed()) {
            abort_if(User::where('role', UserRole::Admin)->whereNull('blocked_at')->count() <= 1, 409, 'A plataforma precisa de pelo menos um administrador ativo.');
        }
    }

    public function update(AdminUpdateUserRequest $request, User $user, AccountSecurity $security): UserResource
    {
        $emailChanged = false;
        DB::transaction(function () use ($request, $user, $security, &$emailChanged) {
            $user->refresh();
            $this->protect($request->user(), $user, $request->validated('role') !== 'admin');
            $emailChanged = $user->email !== $request->validated('email');
            if ($emailChanged) {
                Password::broker()->deleteToken($user);
            }
            $user->fill($request->safe()->only(['name', 'email']));
            $user->role = UserRole::from($request->validated('role'));
            $fields = array_keys($user->getDirty());
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();
            if ($emailChanged || in_array('role', $fields, true)) {
                $security->revokeAccess($user);
            }
            if ($fields) {
                $security->record($request->user(), $user, 'user.updated', ['fields' => $fields]);
            }
        });
        if ($emailChanged && ! $user->blocked_at) {
            $user->sendEmailVerificationNotification();
        }

        return new UserResource($user);
    }

    public function block(Request $request, User $user, AccountSecurity $security): JsonResponse
    {
        return $this->moderate($request, $user, $security, 'user.blocked');
    }

    public function unblock(Request $request, User $user, AccountSecurity $security): JsonResponse
    {
        return $this->moderate($request, $user, $security, 'user.unblocked');
    }

    public function destroy(Request $request, User $user, AccountSecurity $security): JsonResponse
    {
        return $this->moderate($request, $user, $security, 'user.deleted');
    }

    public function restore(Request $request, string $user, AccountSecurity $security): JsonResponse
    {
        return $this->moderate($request, User::onlyTrashed()->findOrFail($user), $security, 'user.restored');
    }

    private function moderate(Request $request, User $user, AccountSecurity $security, string $action): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ]);
        DB::transaction(function () use ($request, $user, $security, $action, $data) {
            $user->refresh();
            $this->protect($request->user(), $user, in_array($action, ['user.blocked', 'user.deleted'], true));
            if ($action === 'user.blocked') {
                $user->blocked_at ??= now();
                $user->save();
            } elseif ($action === 'user.unblocked') {
                $user->blocked_at = null;
                $user->save();
            } elseif ($action === 'user.deleted') {
                $user->delete();
            } elseif ($action === 'user.restored') {
                $user->blocked_at = null;
                $user->restore();
            }
            $security->revokeAccess($user);
            $security->record($request->user(), $user, $action, ['reason' => $data['reason']]);
        });

        return response()->json(['message' => 'A alteração foi guardada e registada no histórico.']);
    }
}
