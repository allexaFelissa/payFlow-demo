<?php

namespace App\Services\Shared;

use App\Models\AuditLog;

class AuditService
{
    public function record(string $event, array $attributes = []): AuditLog
    {
        return AuditLog::create(['event' => $event, 'user_id' => auth()->id(), ...$attributes, 'ip_address' => request()?->ip()]);
    }
}
