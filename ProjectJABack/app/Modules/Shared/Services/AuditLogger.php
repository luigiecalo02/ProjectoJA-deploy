<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class AuditLogger
{
    public function log(
        string $module,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Model $subject = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'module' => $module,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
