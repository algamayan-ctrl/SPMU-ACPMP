<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
    public function record(string $action, Model|string $record, ?int $recordId = null, ?string $reason = null, mixed $before = null, mixed $after = null, ?string $correlationId = null): AuditEvent
    {
        return AuditEvent::query()->create([
            'actor_user_id' => auth()->id(),
            'action_code' => $action,
            'record_type' => $record instanceof Model ? $record::class : $record,
            'record_id' => $record instanceof Model ? $record->getKey() : $recordId,
            'occurred_at' => now(),
            'origin_ip' => request()?->ip(),
            'reason' => $reason,
            'before_json' => $before,
            'after_json' => $after,
            'correlation_id' => $correlationId ?: (string) Str::uuid(),
        ]);
    }
}
