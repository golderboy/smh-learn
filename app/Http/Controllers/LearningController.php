<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourseEnrollment;
use App\Models\LearningProgress;
use App\Models\LessonContent;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\LearningOutline;
use App\Services\LearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LearningController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Learning/Index', ['enrollments' => CourseEnrollment::where('user_id', $user->id)->with('session.version.course')->latest('id')->paginate(20)]);
    }

    public function show(Request $request, CourseEnrollment $enrollment, AssessmentService $assessment, LearningOutline $outline): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $enrollment->user_id === $user->id, 403);
        $enrollment->load('session.version.course.category');
        $modules = $outline->modules($enrollment->session->version);
        $states = $outline->states($enrollment, $modules);
        $canLearn = $enrollment->cancelled_at === null && $enrollment->session->isOpen() && (! $enrollment->session->pretest_required || $enrollment->pretest_completed_at !== null);
        $selected = $request->integer('lesson');
        if ($selected) {
            abort_unless(isset($states[$selected]), 404);
            abort_unless($canLearn && $states[$selected]['unlocked'], 409, 'ต้องเรียนบทก่อนหน้าให้ถึงเกณฑ์ก่อนเปิดบทนี้');
        } else {
            $selected = collect($states)->search(fn ($state): bool => $state['unlocked'] && ! $state['complete']) ?: array_key_first($states);
        }
        // Only return playable content for unlocked lessons; metadata remains visible in the outline.
        foreach ($modules as $module) {
            foreach ($module->lessons as $lesson) {
                if (! $canLearn || ! $states[$lesson->id]['unlocked']) {
                    $lesson->setRelation('contents', $lesson->contents->map(fn ($content) => tap(clone $content, function ($copy): void {
                        $copy->body = null;
                        $copy->external_url = null;
                        $copy->video_id = null;
                    })));
                    $lesson->setRelation('documents', $lesson->documents->map(fn ($content) => tap(clone $content, function ($copy): void {
                        $copy->body = null;
                        $copy->external_url = null;
                    })));
                }
            }
        }

        return Inertia::render('Learning/Show', ['enrollment' => $enrollment, 'modules' => $modules, 'progress' => LearningProgress::where('enrollment_id', $enrollment->id)->get(), 'canLearn' => $canLearn, 'selectedLessonId' => $selected, 'assessment' => $assessment->summary($enrollment)]);
    }

    public function file(Request $request, LessonContent $content, LearningService $learning): HttpResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $course = $content->lesson->module->version->course;
        if ($request->has('enrollment') || ! Gate::allows('manage', $course)) {
            $id = $request->validate(['enrollment' => ['required', 'integer']])['enrollment'];
            $enrollment = CourseEnrollment::findOrFail($id);
            $download = $request->boolean('download') && in_array($content->content_type, ['document', 'image'], true);
            $learning->assertAccess($user, $enrollment, $content, ! $download);
            if (! $download && in_array($content->content_type, ['document', 'image'], true)) {
                $learning->openDocument($request, $enrollment, $content);
            }
        }
        abort_unless(in_array($content->content_type, ['video', 'document', 'image'], true), 404);
        $path = $content->storage_path;
        abort_unless(is_string($path) && preg_match('#^media/[a-f0-9]{2}/[a-f0-9]{64}\.(mp4|pdf|jpg|jpeg|png|webp)$#', $path) === 1 && Storage::disk('learning')->exists($path), 404);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $headers = [
            'Content-Type' => $content->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $content->original_name, 'learning-file.'.pathinfo($path, PATHINFO_EXTENSION)),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // PHP handles authorization; Nginx serves bytes and HTTP Range after the internal redirect.
        return response('', 200, [...$headers, 'X-Accel-Redirect' => '/_protected_media/'.$path]);
    }

    public function open(Request $request, CourseEnrollment $enrollment, LessonContent $content, LearningService $learning): JsonResponse
    {
        abort_unless(in_array($content->content_type, ['article', 'link'], true), 422);

        return response()->json(['progress' => $learning->openDocument($request, $enrollment, $content)]);
    }

    public function confirm(Request $request, CourseEnrollment $enrollment, LessonContent $content, LearningService $learning): RedirectResponse
    {
        $learning->confirm($request, $enrollment, $content);

        return back()->with('message', 'ยืนยันการอ่านและบันทึกความก้าวหน้าแล้ว');
    }

    public function heartbeat(Request $request, CourseEnrollment $enrollment, LessonContent $content, LearningService $learning): JsonResponse
    {
        $data = $request->validate(['position' => ['required', 'numeric', 'min:0'], 'key' => ['nullable', 'uuid'], 'begin' => ['required', 'boolean'], 'duration' => ['nullable', 'numeric', 'min:0.01', 'max:86400'], 'ended' => ['sometimes', 'boolean']]);

        return response()->json($learning->heartbeat($request, $enrollment, $content, (float) $data['position'], $data['key'] ?? null, (bool) $data['begin'], isset($data['duration']) ? (float) $data['duration'] : null, (bool) ($data['ended'] ?? false)));
    }
}
