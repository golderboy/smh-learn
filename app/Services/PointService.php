<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\ExamAttempt;
use App\Models\PointRule;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class PointService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array<string, array{points:int, threshold_percent:float|null}> */
    public function rules(): array
    {
        return PointRule::where('is_active', true)->orderBy('id')->get()->mapWithKeys(fn ($rule): array => [$rule->code => ['points' => $rule->points, 'threshold_percent' => $rule->threshold_percent === null ? null : (float) $rule->threshold_percent]])->all();
    }

    public function balance(User $user): int
    {
        return (int) PointTransaction::where('user_id', $user->id)->sum('points');
    }

    public function award(Request $request, CourseEnrollment $enrollment): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Completion and point award must share a transaction.');
        }
        if ($enrollment->completed_at === null || $enrollment->cancelled_at !== null || $enrollment->points_ineligible || ! $enrollment->session->points_enabled || ($enrollment->session->points_activated_at !== null && $enrollment->completed_at->lessThan($enrollment->session->points_activated_at))) {
            return;
        }
        $rules = $enrollment->session->point_rules_snapshot ?? [];
        User::whereKey($enrollment->user_id)->lockForUpdate()->firstOrFail();
        $best = ExamAttempt::where('enrollment_id', $enrollment->id)->whereNotNull('graded_at')
            ->whereJsonContains('rules_snapshot->exam_type', 'POSTTEST')->orderByDesc('percentage')->orderBy('id')->first();
        foreach ($rules as $code => $rule) {
            $eligible = match ($code) {
                'COMPLETE_COURSE' => true,
                'PASS_POSTTEST' => $best?->passed === true,
                'HIGH_SCORE' => $best !== null && (float) $best->percentage >= (float) $rule['threshold_percent'],
                default => false,
            };
            if (! $eligible || (int) $rule['points'] <= 0) {
                continue;
            }
            $entry = PointTransaction::firstOrCreate(['idempotency_key' => "enrollment:{$enrollment->id}:{$code}"], [
                'user_id' => $enrollment->user_id, 'enrollment_id' => $enrollment->id, 'transaction_type' => $code,
                'reference_type' => 'course_enrollment', 'reference_id' => $enrollment->id,
                'points' => (int) $rule['points'], 'description' => $enrollment->session->version->course->title.' · '.$enrollment->session->name,
                'rule_snapshot' => $rule, 'created_by' => null, 'created_at' => now(),
            ]);
            if ($entry->wasRecentlyCreated) {
                $this->audit->record($request, 'points.awarded', 'point_transaction', $entry->id, new: ['user_id' => $enrollment->user_id, 'enrollment_id' => $enrollment->id, 'type' => $code, 'points' => $entry->points]);
            }
        }
    }

    public function adjust(Request $request, User $target, int $points, string $reason, string $requestKey): PointTransaction
    {
        return DB::transaction(function () use ($request, $target, $points, $reason, $requestKey): PointTransaction {
            User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $key = "adjust:{$target->id}:{$requestKey}";
            $existing = PointTransaction::where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->points === $points && $existing->description === $reason && $existing->created_by === $request->user()?->id, 409, 'คำขอนี้เคยใช้กับการปรับแต้มรายการอื่นแล้ว');

                return $existing;
            }
            $entry = PointTransaction::create(['user_id' => $target->id, 'enrollment_id' => null, 'transaction_type' => 'ADMIN_ADJUSTMENT', 'reference_type' => 'user', 'reference_id' => $target->id, 'idempotency_key' => $key, 'points' => $points, 'description' => $reason, 'created_by' => $request->user()?->id, 'created_at' => now()]);
            $this->audit->record($request, 'points.adjusted', 'point_transaction', $entry->id, new: ['user_id' => $target->id, 'points' => $points, 'balance' => $this->balance($target)], reason: $reason);

            return $entry;
        });
    }
}
