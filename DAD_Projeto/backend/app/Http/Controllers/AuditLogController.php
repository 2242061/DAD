<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $logs = AuditLog::with(['actor', 'target'])->latest('id')->paginate(15);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name ?? 'Sistema',
                'target' => $log->target?->name ?? 'Conta indisponível',
                'details' => $log->details,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'pagination' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ])->header('Cache-Control', 'no-store, private');
    }
}
