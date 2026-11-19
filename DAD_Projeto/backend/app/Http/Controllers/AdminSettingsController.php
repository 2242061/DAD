<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Services\AccountSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PlatformSetting::values()])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, AccountSecurity $audit): JsonResponse
    {
        $data = $request->validate([
            'online_turn_seconds' => ['required', 'integer', 'between:15,180'],
            'tournament_commission_percent' => ['required', 'integer', 'between:0,30'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);
        unset($data['current_password']);

        DB::transaction(function () use ($data, $request, $audit): void {
            $rows = [];
            foreach ($data as $key => $value) {
                $rows[] = ['key' => $key, 'value' => $value];
            }
            PlatformSetting::upsert($rows, ['key'], ['value']);
            $audit->record($request->user(), $request->user(), 'platform_settings_updated', $data);
        });

        return $this->show();
    }
}
