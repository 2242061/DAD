<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AccountSecurity
{
    public function record(?User $actor, User $target, string $action, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => $actor?->id,
            'target_id' => $target->id,
            'action' => $action,
            'details' => $details,
        ]);
    }

    public function revokeAccess(User $user, ?string $exceptSessionId = null): void
    {
        DB::table('sessions')->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)->delete();
        Password::broker()->deleteToken($user);
        $user->increment('auth_version');
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
