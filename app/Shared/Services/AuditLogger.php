<?php

namespace App\Shared\Services;

use App\Models\AuditLog;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request as RequestFacade;

class AuditLogger implements AuditLoggerContract
{
    public function log(
        ?Model $actor,
        Model $subject,
        string $action,
        array $oldValues = [],
        array $newValues = [],
    ): void {
        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => RequestFacade::ip(),
            'user_agent' => RequestFacade::userAgent(),
        ]);
    }
}
