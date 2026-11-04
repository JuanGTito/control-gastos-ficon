<?php

namespace App\Modules\Audit\Actions;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;

class RecordAudit
{
    public function record(User $actor, string $type, int $id, string $action, ?array $before, array $after): AuditEntry
    {
        return AuditEntry::create([
            'actor_id' => $actor->id, 'subject_type' => $type, 'subject_id' => $id,
            'action' => $action, 'before' => $before, 'after' => $after, 'created_at' => now(),
        ]);
    }
}
