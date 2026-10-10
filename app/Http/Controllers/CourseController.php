<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Position;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Services\AuditService;
use App\Services\CourseEditorService;
use App\Services\ExternalVideo;
use App\Services\LearningHtml;
use App\Services\LearningMediaService;
use App\Services\LearningService;
use App\Services\PointService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $manage = $request->routeIs('courses.manage');
        $query = Course::query()->with('category:id,name')->withCount('versions');
        if ($manage) {
            abort_unless($actor->hasRole('administrator') || $actor->hasPermission('courses.manage.assigned'), 403);
            if (! $actor->hasRole('administrator')) {
                $query->whereIn('category_id', $actor->supervisedCategories()->pluck('course_categories.id'));
            }
        } else {
            $query->where('status', 'published')->whereHas('category', fn ($q) => $q->where('is_active', true));
        }
        $categories = CourseCategory::query()->where('is_active', true);
        if ($manage && ! $actor->hasRole('administrator')) {
            $categories->whereIn('id', $actor->supervisedCategories()->pluck('course_categories.id'));
        }

        return Inertia::render('Courses/Index', ['courses' => $query->orderBy('title')->paginate(20), 'categories' => $categories->get(['id', 'name']), 'manage' => $manage, 'canManage' => $actor->hasRole('administrator') || $actor->hasPermission('courses.manage.assigned')]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer', Rule::exists('course_categories', 'id')->where('is_active', true)], 'code' => ['required', 'string', 'max:50', Rule::unique('courses')], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000']]);
        $category = CourseCategory::findOrFail($data['category_id']);
        abort_unless((new CoursePolicy)->inCategory($this->actor($request), $category), 403);
        $course = DB::transaction(function () use ($request, $data, $audit): Course {
            $course = Course::create([...$data, 'created_by' => $this->actor($request)->id]);
            $course->versions()->create(['version_number' => 1, 'version_name' => 'เวอร์ชัน 1']);
            $audit->record($request, 'course.created', 'course', $course->id, new: $data);

            return $course;
        });

        return redirect()->route('courses.edit', $course)->with('message', 'สร้างหลักสูตรฉบับร่างแล้ว');
    }

    public function edit(Request $request, Course $course): Response
    {
        Gate::authorize('manage', $course);
        $versions = $course->versions()->with(['modules.lessons.contents', 'modules.lessons.documents', 'sessions.enrollments.user:id,display_name'])->orderByDesc('version_number')->get();

        return Inertia::render('Courses/Edit', ['course' => $course->load('category:id,name'), 'versions' => $versions, 'departments' => Department::where('is_active', true)->get(['id', 'name']), 'positions' => Position::where('is_active', true)->get(['id', 'name']), 'users' => User::where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']), 'today' => now()->toDateString(), 'fiscalYear' => now()->year + 543 + (now()->month >= 10 ? 1 : 0)]);
    }

    public function update(Request $request, Course $course, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['required', Rule::in(['draft', 'published', 'archived'])]]);
        $editor->mutate($request, $course, 'course.updated', function (Course $locked) use ($data): void {
            if ($data['status'] === 'published' && ! $locked->versions()->whereNotNull('published_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'เผยแพร่เวอร์ชันบทเรียนก่อนเปิดหลักสูตร']);
            }
            $locked->update($data);
        });

        return back()->with('message', 'บันทึกหลักสูตรแล้ว');
    }

    public function version(Request $request, CourseVersion $version, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['version_name' => ['required', 'string', 'max:150']]);
        $editor->mutate($request, $version->course, 'course.version_created', fn (Course $course) => $editor->cloneVersion($course, $version, $data['version_name']));

        return back()->with('message', 'สร้างเวอร์ชันใหม่จากเนื้อหาเดิมแล้ว');
    }

    public function publish(Request $request, CourseVersion $version, CourseEditorService $editor): RedirectResponse
    {
        $editor->mutate($request, $version->course, 'course.version_published', fn () => $editor->publish($version->fresh()));

        return back()->with('message', 'เผยแพร่เวอร์ชันและตรึงเนื้อหาแล้ว');
    }

    public function module(Request $request, CourseVersion $version, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'sort_order' => ['required', 'integer', 'min:1', 'max:10000'], 'module_id' => ['nullable', 'integer']]);
        $editor->mutate($request, $version->course, 'course.module_saved', function () use ($editor, $version, $data): void {
            $editor->draft($version->fresh());
            $id = $data['module_id'] ?? null;
            unset($data['module_id']);
            $id === null ? $version->modules()->create($data) : $version->modules()->findOrFail($id)->update($data);
        });

        return back()->with('message', 'บันทึกหมวดบทเรียนแล้ว');
    }

    public function lesson(Request $request, CourseModule $module, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['required', 'integer', 'min:1', 'max:10000'], 'is_required' => ['required', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'lesson_id' => ['nullable', 'integer']]);
        $editor->mutate($request, $module->version->course, 'course.lesson_saved', function () use ($editor, $module, $data): void {
            $editor->draft($module->version->fresh());
            $id = $data['lesson_id'] ?? null;
            unset($data['lesson_id']);
            $id === null ? $module->lessons()->create($data) : $module->lessons()->findOrFail($id)->update($data);
        });

        return back()->with('message', 'บันทึกบทเรียนแล้ว');
    }

    public function content(Request $request, Lesson $lesson, CourseEditorService $editor, LearningMediaService $media): RedirectResponse
    {
        Gate::authorize('manage', $lesson->module->version->course);
        $editor->draft($lesson->module->version);
        $request->mergeIfMissing(['video_source' => 'upload']);
        $external = $request->input('content_type') === 'video' && $request->input('video_source') !== 'upload';
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'content_type' => ['required', Rule::in(['video', 'document', 'image', 'article', 'link'])], 'video_source' => ['required', Rule::in(['upload', 'youtube', 'remote'])], 'duration_seconds' => [Rule::requiredIf($external), 'nullable', 'integer', 'min:1', 'max:86400'], 'is_required' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:1', 'max:10000'], 'file' => [Rule::requiredIf(in_array($request->input('content_type'), ['document', 'image'], true) || ($request->input('content_type') === 'video' && ! $external)), 'nullable', 'file', 'max:102400'], 'body' => ['required_if:content_type,article', 'nullable', 'string', 'max:50000'], 'external_url' => [Rule::requiredIf($external || $request->input('content_type') === 'link'), 'nullable', 'url:https', 'max:2000']]);
        $file = $request->file('file');
        unset($data['file']);
        if ($external) {
            $data = [...$data, ...app(ExternalVideo::class)->parse($data['external_url'], $data['video_source'])];
            unset($data['body']);
        } elseif (in_array($data['content_type'], ['video', 'document', 'image'], true)) {
            abort_unless($file instanceof UploadedFile, 422);
            $data = [...$data, ...$media->store($file->getPathname(), $file->getClientOriginalName(), $data['content_type'])];
            unset($data['body'], $data['external_url']);
        } elseif ($data['content_type'] === 'article') {
            unset($data['external_url']);
            $data['body'] = app(LearningHtml::class)->clean($data['body']);
        } else {
            unset($data['body']);
        }
        if ($data['content_type'] !== 'video') {
            unset($data['video_source'], $data['duration_seconds']);
        }
        $editor->mutate($request, $lesson->module->version->course, 'course.content_added', function () use ($editor, $lesson, $data): void {
            $editor->draft($lesson->module->version->fresh());
            $lesson->contents()->create($data);
        });

        return back()->with('message', 'แนบเนื้อหาประกอบบทเรียนแล้ว');
    }

    public function removeContent(Request $request, LessonContent $content, CourseEditorService $editor): RedirectResponse
    {
        $editor->mutate($request, $content->lesson->module->version->course, 'course.content_removed', function () use ($editor, $content): void {
            $editor->draft($content->lesson->module->version->fresh());
            DB::table('lesson_document_links')->where('content_id', $content->id)->delete();
            $content->delete(); // Physical objects are retained for cloned versions and history.
        });

        return back()->with('message', 'นำเนื้อหาออกจากฉบับร่างแล้ว');
    }

    public function removeLesson(Request $request, Lesson $lesson, CourseEditorService $editor): RedirectResponse
    {
        $editor->mutate($request, $lesson->module->version->course, 'course.lesson_removed', function () use ($editor, $lesson): void {
            $editor->draft($lesson->module->version->fresh());
            DB::table('lesson_document_links')->where('lesson_id', $lesson->id)->orWhereIn('content_id', $lesson->contents()->pluck('id'))->delete();
            $lesson->contents()->delete();
            $lesson->delete();
        });

        return back()->with('message', 'นำบทเรียนออกจากฉบับร่างแล้ว');
    }

    public function session(Request $request, CourseVersion $version, CourseEditorService $editor, PointService $points): RedirectResponse
    {
        $request->mergeIfMissing(['max_attempts' => 1, 'posttest_required' => true]);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'fiscal_year' => ['required', 'integer', 'min:2400', 'max:3000'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'enrollment_mode' => ['required', Rule::in(['optional', 'required', 'mandatory'])], 'passing_score' => ['required', 'numeric', 'min:0', 'max:100'], 'max_attempts' => ['required', 'integer', 'min:1', 'max:100'], 'required_progress_percent' => ['required', 'numeric', 'min:1', 'max:100'], 'required_video_watch_percent' => ['required', 'numeric', 'min:1', 'max:100'], 'posttest_required' => ['required', 'boolean']]);
        $editor->mutate($request, $version->course, 'course.session_created', function () use ($version, $data, $points): void {
            $version->sessions()->create([...$data, 'pretest_required' => true, 'posttest_required' => true, 'is_mandatory' => $data['enrollment_mode'] === 'mandatory', 'points_enabled' => true, 'point_rules_snapshot' => $points->rules(), 'points_activated_at' => now()]);
        });

        return back()->with('message', 'สร้างรอบเรียนฉบับร่างแล้ว');
    }

    public function sessionStatus(Request $request, CourseSession $session, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $editor->mutate($request, $session->version->course, 'course.session_status', function () use ($session, $data): void {
            if ($data['status'] === 'open' && ($session->version->published_at === null || ! $session->version->is_active)) {
                throw ValidationException::withMessages(['session' => 'เผยแพร่เวอร์ชันที่ใช้ในรอบเรียนก่อน']);
            }
            if ($data['status'] === 'open' && ($session->pretest_required || $session->posttest_required) && $session->version->exams()->where('status', 'published')->whereIn('exam_type', ['PRETEST', 'POSTTEST'])->count() !== 2) {
                throw ValidationException::withMessages(['session' => 'นำเข้าและเปิดชุด Pretest/Posttest ทั้งสองชุดก่อนเปิดรอบเรียน']);
            }
            $session->update($data);
        });

        return back()->with('message', 'เปลี่ยนสถานะรอบเรียนแล้ว');
    }

    public function show(Request $request, Course $course): Response
    {
        abort_unless($course->status === 'published' && $course->category->is_active, 404);
        $sessions = $course->versions()->whereNotNull('published_at')->with('sessions')->get()->flatMap->sessions->filter(fn (CourseSession $session): bool => $session->isOpen());
        $enrollments = CourseEnrollment::where('user_id', $this->actor($request)->id)->whereIn('course_session_id', $sessions->pluck('id'))->get();
        $documents = [];
        foreach ($enrollments as $enrollment) {
            if ($enrollment->cancelled_at !== null || ($enrollment->session->pretest_required && $enrollment->pretest_completed_at === null)) {
                continue;
            }
            $documents[$enrollment->id] = LessonContent::whereIn('content_type', ['document', 'image', 'link'])
                ->whereHas('lesson', fn ($query) => $query->where('status', 'active')->whereHas('module', fn ($module) => $module->where('course_version_id', $enrollment->session->course_version_id)))
                ->with('lesson:id,title')->orderBy('lesson_id')->orderBy('sort_order')->get()->map(fn ($content): array => [
                    'id' => $content->id, 'title' => $content->title, 'lesson' => $content->lesson->title, 'type' => $content->content_type,
                    'url' => $content->content_type === 'link' ? $content->external_url : '/media/'.$content->id.'?enrollment='.$enrollment->id.'&download=1',
                ])->values();
        }

        return Inertia::render('Courses/Show', ['course' => $course->load('category:id,name'), 'sessions' => $sessions->values(), 'enrollments' => $enrollments, 'documents' => $documents]);
    }

    public function enroll(Request $request, CourseSession $session, LearningService $learning, AuditService $audit): RedirectResponse
    {
        $enrollment = DB::transaction(function () use ($request, $session, $learning, $audit): CourseEnrollment {
            $locked = CourseSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_unless($locked->isOpen() && $locked->enrollment_mode === 'optional', 403);
            $enrollment = $learning->enroll($this->actor($request), $locked);
            abort_if($enrollment->cancelled_at !== null, 403);
            if ($enrollment->wasRecentlyCreated) {
                $audit->record($request, 'learning.enrolled', 'course_enrollment', $enrollment->id);
            }

            return $enrollment;
        });

        return redirect()->route('learning.show', $enrollment)->with('message', 'ลงทะเบียนเรียนแล้ว');
    }

    public function assign(Request $request, CourseSession $session, CourseEditorService $editor, LearningService $learning): RedirectResponse
    {
        $data = $request->validate(['assignment_type' => ['required', Rule::in(['all', 'department', 'position', 'user'])], 'target_id' => ['nullable', 'integer', 'min:1'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$session->start_date->format('Y-m-d'), 'before_or_equal:'.$session->end_date->format('Y-m-d')]]);
        $editor->mutate($request, $session->version->course, 'course.assigned', function () use ($request, $session, $data, $learning): void {
            $locked = CourseSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'open' || $locked->version->published_at === null) {
                throw ValidationException::withMessages(['assignment' => 'เปิดรอบเรียนก่อนมอบหมาย']);
            }
            $query = User::query()->where('is_active', true);
            $target = (int) ($data['target_id'] ?? 0);
            if ($data['assignment_type'] === 'all') {
                $target = 0;
            }
            if ($data['assignment_type'] !== 'all') {
                $model = match ($data['assignment_type']) {
                    'department' => Department::class, 'position' => Position::class, default => User::class
                };
                abort_unless($target > 0 && $model::query()->whereKey($target)->where('is_active', true)->exists(), 422);
                $field = match ($data['assignment_type']) {
                    'department' => 'department_id', 'position' => 'position_id', default => 'id'
                };
                $query->where($field, $target);
            }
            $assignment = DB::table('course_assignments')->insertGetId(['course_session_id' => $locked->id, 'assignment_type' => $data['assignment_type'], 'target_id' => $target, 'due_date' => $data['due_date'] ?? null, 'assigned_by' => $this->actor($request)->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($query->with(['department', 'position'])->get() as $user) {
                $learning->enroll($user, $locked, $assignment, $data['due_date'] ?? null);
            }
        });

        return back()->with('message', 'มอบหมายให้บุคลากรที่ตรงเงื่อนไขในขณะนี้แล้ว; รายการเดิมยังคงประวัติเดิม');
    }

    public function cancelEnrollment(Request $request, CourseEnrollment $enrollment, CourseEditorService $editor): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $editor->mutate($request, $enrollment->session->version->course, 'learning.enrollment_cancelled', function () use ($enrollment): void {
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $locked->update(['cancelled_at' => now(), 'state' => 'CANCELLED']);
        });

        return back()->with('message', 'ยกเลิกการเรียนโดยเก็บประวัติเดิมแล้ว');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
