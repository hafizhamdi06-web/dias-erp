<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * Pencatat aktivitas. Tidak pernah melempar exception - kegagalan log diabaikan
 * supaya tidak mengganggu alur utama.
 *
 * Pakai: app(ActivityLogger::class)->record('update', 'admin/user', $id, 'Ubah user ANDREAS');
 * atau helper: activity_log('update', 'admin/user', $id, '...');
 */
class ActivityLogger
{
    public function record(string $action, ?string $module = null, ?string $entityId = null, ?string $description = null): void
    {
        try {
            $user = Auth::user();

            ActivityLog::create([
                'user_id'     => $user?->UID,
                'user_label'  => $user?->displayName(),
                'action'      => $action,
                'module'      => $module,
                'entity_id'   => $entityId !== null ? (string) $entityId : null,
                'description' => $description,
                'ip'          => request()->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // sengaja diabaikan
        }
    }
}
