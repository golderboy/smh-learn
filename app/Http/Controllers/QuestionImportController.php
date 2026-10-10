<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourseVersion;
use App\Models\QuestionImport;
use App\Services\QuestionImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuestionImportController extends Controller
{
    public function clear(Request $request, CourseVersion $version, QuestionImportService $service): RedirectResponse
    {
        Gate::authorize('manage', $version->course);
        $request->validate(['confirmation' => ['required', 'in:CLEAR']]);
        $service->clear($request, $version);

        return back()->with('message', 'ล้างข้อสอบของเวอร์ชันร่างแล้ว สามารถนำเข้าไฟล์ใหม่ได้ ประวัติสอบของเวอร์ชันเดิมยังอยู่ครบ');
    }

    public function index(Request $request, CourseVersion $version): Response
    {
        Gate::authorize('manage', $version->course);
        $preview = QuestionImport::where('course_version_id', $version->id)->where('created_by', $request->user()->id)->whereNull('committed_at')->where('expires_at', '>', now())->latest('id')->first();
        $questions = DB::table('questions')->where('course_id', $version->course_id)->where('is_active', true)->orderBy('id')->paginate(20);
        $choices = DB::table('question_choices')->whereIn('question_id', $questions->getCollection()->pluck('id'))->orderBy('sort_order')->get()->groupBy('question_id');
        $questions->getCollection()->transform(function ($question) use ($choices) {
            $question->choices = $choices->get($question->id, collect())->pluck('choice_text', 'choice_key');
            $question->correct_answer = $choices->get($question->id, collect())->firstWhere('is_correct', 1)?->choice_key;

            return $question;
        });

        return Inertia::render('Assessment/Import', ['version' => $version, 'course' => $version->course, 'preview' => $preview, 'questions' => $questions, 'exams' => $version->exams()->get()->map(fn ($exam) => ['id' => $exam->id, 'type' => $exam->exam_type, 'count' => DB::table('exam_questions')->where('exam_id', $exam->id)->count(), 'status' => $exam->status, 'question_count' => $exam->question_count, 'time_limit_minutes' => $exam->time_limit_minutes, 'randomize_questions' => $exam->randomize_questions, 'randomize_choices' => $exam->randomize_choices, 'has_attempts' => DB::table('exam_attempts')->where('exam_id', $exam->id)->exists()])]);
    }

    public function preview(Request $request, CourseVersion $version, QuestionImportService $service): RedirectResponse
    {
        Gate::authorize('manage', $version->course);
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:10240']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $service->preview($request, $version, $file->getPathname(), $file->getClientOriginalName());

        return back()->with('message', 'ตรวจไฟล์แล้ว กรุณาตรวจตัวอย่างและยืนยันนำเข้า');
    }

    public function commit(Request $request, CourseVersion $version, QuestionImportService $service): RedirectResponse
    {
        Gate::authorize('manage', $version->course);
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $service->commit($request, $version, $data['token']);

        return back()->with('message', 'นำเข้าข้อสอบปรนัยและเฉลยแล้ว ตรวจชุดสอบและเปลี่ยนสถานะเป็นเปิดให้สอบก่อนใช้งาน');
    }

    public function template(CourseVersion $version): BinaryFileResponse
    {
        Gate::authorize('manage', $version->course);

        return response()->download(base_path('docs/templates/objective-exam-template.xlsx'), 'SMH-Objective-Exam-Template.xlsx');
    }
}
