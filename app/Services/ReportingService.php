<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    /** @return array{all:bool, categories:list<int>, department:int|null, personal:bool, user:int, revision:int} */
    public function scope(User $user, bool $personal = false): array
    {
        $all = ! $personal && $user->hasPermission('reports.view.all');
        $categories = ! $personal && $user->hasPermission('reports.view.assigned') ? $user->supervisedCategories()->orderBy('course_categories.id')->pluck('course_categories.id')->all() : [];
        $department = ! $personal && $user->hasPermission('reports.view.department') ? $user->department_id : null;
        $professional = $all || $user->hasPermission('reports.view.assigned') || $user->hasPermission('reports.view.department');

        return ['all' => $all, 'categories' => $categories, 'department' => $department, 'personal' => $personal || ! $professional, 'user' => $user->id, 'revision' => $user->session_version];
    }

    /** @param array<string, mixed> $filters */
    public function fingerprint(User $user, array $filters): string
    {
        return hash('sha256', json_encode($this->scope($user, ($filters['audience'] ?? '') === 'mine'), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $filters
     * @return Builder<CourseEnrollment>
     */
    public function query(User $user, array $filters = []): Builder
    {
        $scope = $this->scope($user, ($filters['audience'] ?? '') === 'mine');
        $query = CourseEnrollment::query();
        if (! $scope['all']) {
            $query->where(function (Builder $allowed) use ($scope): void {
                if ($scope['personal']) {
                    $allowed->where('user_id', $scope['user']);
                } else {
                    $allowed->whereRaw('1 = 0');
                    if ($scope['department'] !== null) {
                        $allowed->orWhere('department_id', $scope['department']);
                    }
                    if ($scope['categories'] !== []) {
                        $allowed->orWhereHas('session.version.course', fn ($course) => $course->whereIn('category_id', $scope['categories']));
                    }
                }
            });
        }
        foreach (['department_id', 'position_id', 'user_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $query->whereHas('session', function (Builder $session) use ($filters): void {
            if (! empty($filters['fiscal_year'])) {
                $session->where('fiscal_year', $filters['fiscal_year']);
            }
            if (! empty($filters['course_id'])) {
                $session->whereHas('version', fn ($version) => $version->where('course_id', $filters['course_id']));
            }
            if (! empty($filters['category_id'])) {
                $session->whereHas('version.course', fn ($course) => $course->where('category_id', $filters['category_id']));
            }
        });
        if (! empty($filters['date_from'])) {
            $query->where('enrolled_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $query->where('enrolled_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }
        if (! empty($filters['status'])) {
            match ($filters['status']) {
                'COMPLETED' => $query->whereNotNull('completed_at')->whereNull('cancelled_at'),
                'CANCELLED' => $query->whereNotNull('cancelled_at'),
                'NOT_STARTED' => $query->whereNull('started_at')->whereNull('completed_at')->whereNull('cancelled_at'),
                'IN_PROGRESS' => $query->whereNotNull('started_at')->whereNull('completed_at')->whereNull('cancelled_at'),
                'OVERDUE' => $query->whereNull('completed_at')->whereNull('cancelled_at')->whereDate('due_date', '<', now()->toDateString()),
                default => null,
            };
        }

        return $query;
    }

    /** Reference filters do not need to reload attempts, watch times or point aggregates.
     * @return array<string, mixed>
     */
    public function referenceOptions(User $user, bool $personal = false): array
    {
        $enrollments = $this->query($user, $personal ? ['audience' => 'mine'] : [])
            ->select(['id', 'user_id', 'course_session_id', 'department_id', 'department_name', 'position_id', 'position_name'])
            ->with(['user:id,display_name', 'session.version.course.category'])->orderBy('id')->get();
        $rows = $enrollments->map(fn (CourseEnrollment $item): array => [
            'user_id' => $item->user_id, 'name' => $item->user->display_name,
            'department_id' => $item->department_id, 'department' => $item->department_name ?? 'ไม่ระบุแผนก',
            'position_id' => $item->position_id, 'position' => $item->position_name ?? 'ไม่ระบุตำแหน่ง',
            'course_id' => $item->session->version->course_id, 'course' => $item->session->version->course->title,
            'category_id' => $item->session->version->course->category_id, 'category' => $item->session->version->course->category->name,
            'fiscal_year' => $item->session->fiscal_year,
        ]);
        $choices = fn (string $id, string $name): array => $rows->filter(fn ($row): bool => $row[$id] !== null)
            ->unique($id)->map(fn ($row): array => ['id' => $row[$id], 'name' => $row[$name]])->values()->all();

        return ['courses' => $choices('course_id', 'course'), 'categories' => $choices('category_id', 'category'),
            'departments' => $choices('department_id', 'department'), 'positions' => $choices('position_id', 'position'),
            'people' => $choices('user_id', 'name'), 'years' => $rows->pluck('fiscal_year')->unique()->sortDesc()->values()];
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function individuals(User $user, array $filters = []): Collection
    {
        $enrollments = $this->query($user, $filters)->with(['user:id,employee_code,display_name', 'session.version.course.category'])->orderBy('id')->get();
        $ids = $enrollments->pluck('id');
        $attempts = ExamAttempt::whereIn('enrollment_id', $ids)->orderBy('id')->get()->groupBy('enrollment_id');
        $times = DB::table('learning_progress')->whereIn('enrollment_id', $ids)->groupBy('enrollment_id')->selectRaw('enrollment_id, SUM(watched_seconds) AS seconds')->pluck('seconds', 'enrollment_id');
        $points = DB::table('point_transactions')->whereIn('enrollment_id', $ids)->groupBy('enrollment_id')->selectRaw('enrollment_id, SUM(points) AS points')->pluck('points', 'enrollment_id');

        /** @var Collection<int,array<string,mixed>> $rows */
        $rows = $enrollments->map(function (CourseEnrollment $item) use ($attempts, $times, $points): array {
            $history = $attempts->get($item->id, collect());
            $pre = $history->filter(fn ($attempt): bool => $attempt->graded_at !== null && $attempt->rules_snapshot['exam_type'] === 'PRETEST')->first();
            $posts = $history->filter(fn ($attempt): bool => $attempt->rules_snapshot['exam_type'] === 'POSTTEST');
            $best = $posts->whereNotNull('graded_at')->sortByDesc('percentage')->first();
            $preScore = $pre === null ? null : (float) $pre->percentage;
            $postScore = $best === null ? null : (float) $best->percentage;

            return [
                'enrollment_id' => $item->id, 'user_id' => $item->user_id, 'employee_code' => $item->user->employee_code,
                'name' => $item->user->display_name, 'department_id' => $item->department_id, 'department' => $item->department_name ?? 'ไม่ระบุแผนก',
                'position_id' => $item->position_id, 'position' => $item->position_name ?? 'ไม่ระบุตำแหน่ง',
                'course_id' => $item->session->version->course_id, 'course' => $item->session->version->course->title,
                'category_id' => $item->session->version->course->category_id, 'category' => $item->session->version->course->category->name,
                'session_id' => $item->course_session_id, 'session' => $item->session->name, 'fiscal_year' => $item->session->fiscal_year,
                'version' => $item->session->version->version_number, 'pretest' => $preScore, 'posttest' => $postScore,
                'gain' => $preScore !== null && $postScore !== null ? round($postScore - $preScore, 2) : null,
                'passed' => $best?->passed, 'attempts' => $posts->count(), 'graded_attempts' => $posts->whereNotNull('graded_at')->count(),
                'status' => $item->cancelled_at !== null ? 'CANCELLED' : ($item->completed_at !== null ? 'COMPLETED' : $item->state),
                'assigned' => $item->assignment_id !== null, 'mandatory' => $item->session->is_mandatory,
                'started' => $item->started_at !== null, 'completed' => $item->completed_at !== null,
                'cancelled' => $item->cancelled_at !== null, 'overdue' => $item->cancelled_at === null && $item->completed_at === null && $item->due_date?->startOfDay()->isPast() && $item->due_date->toDateString() !== now()->toDateString(),
                'due_date' => $item->due_date?->toDateString(), 'completion_date' => $item->completed_at?->format('Y-m-d H:i:s'),
                'learning_minutes' => round((float) $times->get($item->id, 0) / 60, 2), 'points' => (int) $points->get($item->id, 0),
            ];
        });

        return $rows;
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        $active = $rows->where('cancelled', false);
        $pre = $active->whereNotNull('pretest');
        $post = $active->whereNotNull('posttest');
        $paired = $active->whereNotNull('gain');
        $completed = $active->where('completed', true)->count();
        $passed = $post->where('passed', true)->count();
        $average = fn (Collection $values, string $key): ?float => $values->isEmpty() ? null : round((float) $values->avg($key), 2);

        return [
            'total' => $active->count(), 'staff' => $active->pluck('user_id')->unique()->count(), 'assigned' => $active->where('assigned', true)->count(),
            'started' => $active->where('started', true)->count(), 'completed' => $completed, 'passed' => $passed,
            'failed' => $post->where('passed', false)->count(), 'not_started' => $active->where('started', false)->where('completed', false)->count(),
            'overdue' => $active->where('overdue', true)->count(), 'cancelled' => $rows->where('cancelled', true)->count(),
            'pretest_average' => $average($pre, 'pretest'), 'posttest_average' => $average($post, 'posttest'), 'gain_average' => $average($paired, 'gain'),
            'pretest_count' => $pre->count(), 'posttest_count' => $post->count(), 'paired_count' => $paired->count(),
            'pass_rate' => $post->isEmpty() ? null : round($passed / $post->count() * 100, 2),
            'completion_rate' => $active->isEmpty() ? null : round($completed / $active->count() * 100, 2),
            'average_attempts' => $post->isEmpty() ? null : round((float) $post->avg('attempts'), 2),
            'average_learning_minutes' => $average($active, 'learning_minutes'), 'points' => (int) $active->sum('points'),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{columns:array<string,string>, rows:Collection<int,array<string,mixed>>, summary:array<string,mixed>}
     */
    public function report(User $user, string $type, array $filters = []): array
    {
        abort_unless(in_array($type, ['individual', 'course', 'department', 'question'], true), 422);
        $individuals = $this->individuals($user, $filters);
        $summary = $this->summary($individuals);
        if ($type === 'individual') {
            return ['columns' => ['employee_code' => 'รหัสบุคลากร', 'name' => 'ชื่อ', 'department' => 'แผนก ณ สมัคร', 'position' => 'ตำแหน่ง ณ สมัคร', 'course' => 'หลักสูตร', 'session' => 'รอบเรียน', 'fiscal_year' => 'ปีงบประมาณ', 'pretest' => 'Pretest (%)', 'posttest' => 'Posttest สูงสุด (%)', 'gain' => 'เพิ่มขึ้น (จุดร้อยละ)', 'status' => 'สถานะ', 'attempts' => 'ครั้ง Posttest', 'completion_date' => 'วันที่จบ', 'learning_minutes' => 'รับชมสะสม (นาที)', 'points' => 'แต้มจากรอบ'], 'rows' => $individuals, 'summary' => $summary];
        }
        if ($type === 'question') {
            abort_if($this->scope($user, ($filters['audience'] ?? '') === 'mine')['personal'], 403);

            return ['columns' => ['question' => 'คำถาม', 'category' => 'หมวดข้อสอบ ณ สอบ', 'exam_type' => 'ประเภท', 'answered' => 'ตอบแล้ว', 'correct' => 'ตอบถูก', 'incorrect' => 'ตอบผิด', 'unanswered' => 'ไม่ตอบ', 'correct_percent' => 'ตอบถูก (%)'], 'rows' => $this->questions($user, $filters), 'summary' => $summary];
        }
        /** @var Collection<int,array<string,mixed>> $rows */
        $rows = $individuals->groupBy($type === 'course' ? 'session_id' : fn ($row): string => ($row['department_id'] ?? 'none').':'.$row['department'])->map(function (Collection $items) use ($type): array {
            $first = $items->first();
            $group = $this->summary($items);

            return [$type => $first[$type], 'session' => $type === 'course' ? $first['session'] : '', 'fiscal_year' => $type === 'course' ? $first['fiscal_year'] : '', ...$group];
        })->values();
        $columns = [$type => $type === 'course' ? 'หลักสูตร' : 'แผนก ณ สมัคร'];
        if ($type === 'course') {
            $columns += ['session' => 'รอบเรียน', 'fiscal_year' => 'ปีงบประมาณ'];
        }
        $columns += ['staff' => 'บุคลากรในรายงาน', 'total' => 'รายการเรียน', 'assigned' => 'มอบหมาย', 'started' => 'เริ่มแล้ว', 'completed' => 'จบแล้ว', 'passed' => 'ผ่าน', 'failed' => 'ไม่ผ่าน', 'not_started' => 'ยังไม่เริ่ม', 'overdue' => 'เกินกำหนด', 'pretest_average' => 'Pretest เฉลี่ย (%)', 'posttest_average' => 'Posttest เฉลี่ย (%)', 'gain_average' => 'เพิ่มขึ้นเฉลี่ย', 'completion_rate' => 'จบ (%)', 'pass_rate' => 'ผ่าน (%)', 'average_attempts' => 'ครั้งสอบเฉลี่ย', 'average_learning_minutes' => 'รับชมเฉลี่ย (นาที)'];

        return ['columns' => $columns, 'rows' => $rows, 'summary' => $summary];
    }

    /** @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function questions(User $user, array $filters): Collection
    {
        $query = DB::table('exam_answers')->join('exam_attempts', 'exam_attempts.id', '=', 'exam_answers.attempt_id')
            ->whereIn('exam_attempts.enrollment_id', $this->query($user, $filters)->whereNull('cancelled_at')->select('course_enrollments.id'))
            ->whereNotNull('exam_attempts.graded_at');
        if (! empty($filters['exam_type'])) {
            $query->where('exam_attempts.rules_snapshot->exam_type', $filters['exam_type']);
        }
        // Group snapshot variants separately. Question text/category never comes from a later edited bank.
        $groups = [];
        foreach ($query->select(['exam_answers.id', 'exam_answers.question_id', 'exam_answers.question_snapshot', 'exam_answers.selected_answer', 'exam_answers.is_correct', 'exam_attempts.rules_snapshot'])->orderBy('exam_answers.id')->cursor() as $answer) {
            $snapshot = json_decode($answer->question_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $rules = json_decode($answer->rules_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $key = hash('sha256', json_encode([$answer->question_id, $snapshot['question_text'], $snapshot['category'] ?? 'ทั่วไป', $snapshot['correct_answer'], $rules['exam_type']], JSON_THROW_ON_ERROR));
            $groups[$key] ??= ['question' => $snapshot['question_text'], 'category' => $snapshot['category'] ?? 'ทั่วไป', 'exam_type' => $rules['exam_type'], 'answered' => 0, 'correct' => 0, 'incorrect' => 0, 'unanswered' => 0, 'correct_percent' => null];
            if ($answer->selected_answer === null) {
                $groups[$key]['unanswered']++;
            } else {
                $groups[$key]['answered']++;
                $groups[$key][$answer->is_correct ? 'correct' : 'incorrect']++;
            }
        }
        foreach ($groups as &$group) {
            $group['correct_percent'] = $group['answered'] > 0 ? round($group['correct'] / $group['answered'] * 100, 2) : null;
        }
        unset($group);

        /** @var Collection<int,array<string,mixed>> $rows */
        $rows = collect(array_values($groups));

        return $rows;
    }
}
