<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    /** @param array<string, mixed>|null $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(Request $request, string $action, string $type, ?int $id = null, ?array $old = null, ?array $new = null, ?string $reason = null): AuditLog
    {
        $actor = $request->user();

        return AuditLog::create([
            'user_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'old_values' => $old === null ? null : $this->redact($old),
            'new_values' => $new === null ? null : $this->redact($new),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr($request->userAgent() ?? '', 0, 255),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|username|subject|citizen|coupon_code|\bpid\b/i', $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
