<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourseEnrollment;
use App\Models\ExamAttempt;
use App\Services\AssessmentRules;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function start(Request $request, CourseEnrollment $enrollment, AssessmentService $service): RedirectResponse
    {
        $data = $request->validate(['exam_type' => ['required', Rule::in(['PRETEST', 'POSTTEST'])]]);
        $attempt = $service->start($request, $enrollment, $data['exam_type']);

        return redirect()->route('assessment.show', $attempt);
    }

    public function show(Request $request, ExamAttempt $attempt, AssessmentService $service): Response
    {
        $attempt = $service->update($request, $attempt);
        $enrollment = CourseEnrollment::with('session.version.course')->findOrFail($attempt->enrollment_id);

        return Inertia::render('Assessment/Exam', [...$service->present($attempt), 'enrollment' => $enrollment, 'summary' => $service->summary($enrollment)]);
    }

    public function save(Request $request, ExamAttempt $attempt, AssessmentService $service): JsonResponse
    {
        $data = $this->answers($request);
        $attempt = $service->update($request, $attempt, $data['answers'], $data['revision']);

        return response()->json(['attempt' => $attempt]);
    }

    public function submit(Request $request, ExamAttempt $attempt, AssessmentService $service): RedirectResponse
    {
        $data = $this->answers($request);
        $service->update($request, $attempt, $data['answers'], $data['revision'], true);

        return redirect()->route('assessment.show', $attempt)->with('message', 'บันทึกผลสอบแล้ว');
    }

    /** @return array{revision:int, answers:list<array{question_id:int, selected_answer:string|null}>} */
    private function answers(Request $request): array
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'answers' => ['required', 'array', 'max:1000'], 'answers.*.question_id' => ['required', 'integer', 'distinct'], 'answers.*.selected_answer' => ['present', 'nullable', Rule::in(AssessmentRules::CHOICE_KEYS)]]);

        return ['revision' => (int) $data['revision'], 'answers' => array_map(fn ($answer): array => ['question_id' => (int) $answer['question_id'], 'selected_answer' => $answer['selected_answer']], array_values($data['answers']))];
    }
}
