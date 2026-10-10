<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseVersion;
use App\Models\Exam;
use App\Models\QuestionImport;
use App\Models\User;
use App\Services\QuestionImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class QuestionImportTest extends FoundationTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @return array{User, CourseVersion} */
    private function fixture(): array
    {
        $admin = $this->userWithRole('administrator');
        $category = CourseCategory::create(['code' => 'EXCEL-TEST', 'name' => 'ทดสอบ Excel']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'EXCEL', 'title' => 'ทดสอบข้อสอบ', 'created_by' => $admin->id]);

        return [$admin, $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ'])];
    }

    /** @param list<array<int, mixed>> $rows */
    private function excel(array $rows, string $sheet = 'Questions', ?array $headers = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'smh-excel-');
        $this->temporary[] = $path;
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($sheet);
        foreach ([$headers ?? QuestionImportService::LEGACY_HEADERS, ...$rows] as $row) {
            $writer->addRow(new Row(array_map(fn ($value) => Cell::fromValue($value), $row)));
        }
        $writer->close();

        return $path;
    }

    /** @return array<int, mixed> */
    private function row(string $code = '0001', string $usage = 'BOTH'): array
    {
        return [$code, $usage, 'เลือกคำตอบที่ถูกต้อง', 'ตัวเลือกหนึ่ง', 'ตัวเลือกสอง', 'ตัวเลือกสาม', 'ตัวเลือกสี่', 'B', 2.5, 'หมวดทดสอบ', 'คำอธิบายทดสอบ'];
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }

    public function test_delivered_template_parses_three_synthetic_rows_and_preserves_thai_answers(): void
    {
        $rows = app(QuestionImportService::class)->parse(base_path('docs/templates/objective-exam-template.xlsx'));
        $this->assertCount(3, $rows);
        $this->assertSame('DEMO-PRE001', $rows[0]['code']);
        $this->assertSame('A', $rows[0]['correct_answer']);
        $this->assertSame('.xlsx', $rows[0]['choices']['A']);
        $this->assertSame('BOTH', $rows[2]['usage']);
        $this->assertSame(2, $rows[2]['max_score']);
        $this->assertSame([4, 5, 6], array_map(fn ($row) => count($row['choices']), $rows));
        $this->assertSame('E', $rows[1]['correct_answer']);
        $this->assertSame('F', $rows[2]['correct_answer']);
    }

    public function test_preview_then_confirm_is_atomic_idempotent_and_maps_both_types_with_snapshots(): void
    {
        [$admin, $version] = $this->fixture();
        $path = $this->excel([$this->row(), $this->row('P2', 'PRETEST'), $this->row('S3', 'POSTTEST')]);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('questions')->count());
        $import = QuestionImport::firstOrFail();
        $this->assertCount(3, $import->rows);
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('questions')->count());
        $this->assertSame(2, Exam::count());
        $this->assertSame(4, DB::table('exam_questions')->count());
        $this->assertDatabaseHas('questions', ['code' => '0001', 'question_type' => 'SINGLE_CHOICE', 'max_score' => 2.5]);
        $this->assertSame(12, DB::table('question_choices')->count());
        $this->assertSame(3, DB::table('question_choices')->where('is_correct', true)->count());
        foreach (Exam::all() as $exam) {
            $this->assertSame('draft', $exam->status);
            $this->assertSame('SINGLE_CHOICE', $exam->grading_mode);
        }
        $snapshot = json_decode(DB::table('exam_questions')->first()->question_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('B', $snapshot['correct_answer']);
        $this->assertSame('ตัวเลือกสอง', $snapshot['choices']['B']);
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertRedirect();
        $this->assertSame(3, DB::table('questions')->count());
        $this->assertNotNull($import->fresh()->committed_at);
    }

    public function test_clearing_draft_allows_same_file_again_and_preserves_other_version_snapshots(): void
    {
        [$admin, $version] = $this->fixture();
        $path = $this->excel([$this->row('SAME')]);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertSessionHasNoErrors();
        $token = QuestionImport::firstOrFail()->token;
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $token])->assertSessionHasNoErrors();
        $oldQuestionId = DB::table('questions')->value('id');
        $old = $version->course->versions()->create(['version_number' => 2, 'version_name' => 'ประวัติเดิม', 'published_at' => now()]);
        $oldExam = $old->exams()->create(['exam_type' => 'PRETEST', 'status' => 'active', 'grading_mode' => 'SINGLE_CHOICE']);
        $snapshot = DB::table('exam_questions')->first()->question_snapshot;
        DB::table('exam_questions')->insert(['exam_id' => $oldExam->id, 'question_id' => $oldQuestionId, 'sort_order' => 1, 'question_snapshot' => $snapshot]);
        $this->delete('/versions/'.$version->id.'/questions', ['confirmation' => 'CLEAR'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('questions', ['id' => $oldQuestionId, 'code' => 'SAME', 'is_active' => false]);
        $this->assertSame($snapshot, DB::table('exam_questions')->where('exam_id', $oldExam->id)->value('question_snapshot'));
        $this->assertSame(1, DB::table('exam_questions')->count());
        $this->assertDatabaseCount('question_choices', 4);
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $token])->assertNotFound();
        $this->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertSessionHasNoErrors();
        $newToken = QuestionImport::firstOrFail()->token;
        $this->assertNotSame($token, $newToken);
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $newToken])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('questions')->where('code', 'SAME')->count());
        $this->assertSame(1, DB::table('questions')->where('code', 'SAME')->where('is_active', true)->count());
        $this->assertSame($snapshot, DB::table('exam_questions')->where('exam_id', $oldExam->id)->value('question_snapshot'));
    }

    public function test_clear_rejects_published_versions_active_exams_and_unassigned_instructors(): void
    {
        [$admin, $version] = $this->fixture();
        $this->actingAs($this->userWithRole('instructor'))->delete('/versions/'.$version->id.'/questions', ['confirmation' => 'CLEAR'])->assertForbidden();
        $this->actingAs($admin)->delete('/versions/'.$version->id.'/questions', ['confirmation' => 'WRONG'])->assertSessionHasErrors('confirmation');
        $exam = $version->exams()->create(['exam_type' => 'PRETEST', 'status' => 'active']);
        $this->delete('/versions/'.$version->id.'/questions', ['confirmation' => 'CLEAR'])->assertSessionHasErrors('file');
        $exam->update(['status' => 'draft']);
        $version->update(['published_at' => now()]);
        $this->delete('/versions/'.$version->id.'/questions', ['confirmation' => 'CLEAR'])->assertSessionHasErrors('course');
        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
    }

    public function test_extended_template_imports_four_five_and_six_choices_with_thai_or_latin_answer_keys(): void
    {
        [$admin, $version] = $this->fixture();
        $rows = [];
        foreach ([[4, 'ข'], [5, 'E'], [6, 'ฉ']] as [$count, $correct]) {
            $row = $this->row('EXT'.$count);
            array_splice($row, 7, 0, [$count >= 5 ? 'ตัวเลือกห้า' : null, $count === 6 ? 'ตัวเลือกหก' : null]);
            $row[9] = $correct;
            $rows[] = $row;
        }
        $path = $this->excel($rows, headers: QuestionImportService::HEADERS);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertRedirect()->assertSessionHasNoErrors();
        $import = QuestionImport::firstOrFail();
        $this->assertSame([4, 5, 6], array_map(fn ($row) => count($row['choices']), $import->rows));
        $this->assertSame(['B', 'E', 'F'], array_column($import->rows, 'correct_answer'));
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('question_choices', 15);
        $this->assertSame(3, DB::table('question_choices')->where('is_correct', true)->count());
        foreach (DB::table('exam_questions')->get() as $question) {
            $snapshot = json_decode($question->question_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $code = DB::table('questions')->where('id', $question->question_id)->value('code');
            $this->assertCount((int) substr($code, 3), $snapshot['choices']);
            $this->assertArrayHasKey($snapshot['correct_answer'], $snapshot['choices']);
        }
    }

    public function test_extended_choices_reject_gaps_duplicates_invalid_answers_and_missing_answer_choice(): void
    {
        $service = app(QuestionImportService::class);
        foreach (['gap', 'duplicate', 'missing-answer', 'invalid-answer', 'numeric-choice', 'too-long', 'extra-column'] as $kind) {
            $row = $this->row();
            array_splice($row, 7, 0, ['ตัวเลือกห้า', 'ตัวเลือกหก']);
            match ($kind) {
                'gap' => $row[7] = ' ',
                'duplicate' => $row[8] = ' '.$row[3].' ',
                'missing-answer' => [$row[8], $row[9]] = [null, 'ฉ'],
                'invalid-answer' => $row[9] = 'G',
                'numeric-choice' => $row[7] = 123,
                'too-long' => $row[8] = str_repeat('ฉ', 5001),
                'extra-column' => $row[] = 'คอลัมน์เกิน',
            };
            try {
                $service->parse($this->excel([$row], headers: QuestionImportService::HEADERS));
                $this->fail('Invalid extended row was accepted: '.$kind);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $row = $this->row();
        $row[7] = 'E';
        $this->expectException(ValidationException::class);
        $service->parse($this->excel([$row]));
    }

    public function test_invalid_row_or_duplicate_in_workbook_rejects_whole_preview(): void
    {
        [$admin, $version] = $this->fixture();
        $valid = $this->row();
        foreach (['duplicate', 'empty-choice', 'invalid-type', 'invalid-score', 'numeric-code', 'invalid-answer', 'duplicate-choices'] as $kind) {
            $invalid = $this->row('0002');
            match ($kind) {
                'duplicate' => $invalid[0] = '0001', 'empty-choice' => $invalid[6] = '', 'invalid-type' => $invalid[1] = 'POSTEST', 'invalid-score' => $invalid[8] = -1, 'numeric-code' => $invalid[0] = 123, 'invalid-answer' => $invalid[7] = 'A,B', 'duplicate-choices' => $invalid[4] = $invalid[3]
            };
            $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($this->excel([$valid, $invalid]))])->assertSessionHasErrors('file');
            $this->assertSame(0, QuestionImport::count());
            $this->assertSame(0, DB::table('questions')->count());
        }
    }

    public function test_formula_macro_external_entity_wrong_headers_and_missing_sheet_are_rejected(): void
    {
        $service = app(QuestionImportService::class);
        $formula = $this->row();
        $formula[3] = '=1+1';
        $cases = [$this->excel([$formula]), $this->excel([$this->row()], 'WrongSheet'), $this->excel([$this->row()], headers: ['wrong'])];
        $macro = $this->excel([$this->row()]);
        $zip = new \ZipArchive;
        $zip->open($macro);
        $zip->addFromString('xl/vbaProject.bin', 'fake macro');
        $zip->close();
        $cases[] = $macro;
        $external = $this->excel([$this->row()]);
        $zip = new \ZipArchive;
        $zip->open($external);
        $zip->addFromString('xl/fake.xml', '<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><x/>');
        $zip->close();
        $cases[] = $external;
        foreach ($cases as $path) {
            try {
                $service->parse($path);
                $this->fail('Invalid workbook was accepted: '.$path);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
    }

    public function test_codes_that_become_duplicates_before_confirm_abort_every_insert(): void
    {
        [$admin, $version] = $this->fixture();
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($this->excel([$this->row('Q1'), $this->row('Q2')]))])->assertRedirect();
        $import = QuestionImport::firstOrFail();
        DB::table('questions')->insert(['course_id' => $version->course_id, 'code' => 'Q2', 'question_type' => 'SINGLE_CHOICE', 'usage' => 'BOTH', 'question_text' => 'ข้อเดิม', 'max_score' => 1, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertSessionHasErrors('file');
        $this->assertSame(1, DB::table('questions')->count());
        $this->assertDatabaseMissing('questions', ['code' => 'Q1']);
        $this->assertNull($import->fresh()->committed_at);
    }

    public function test_learner_and_instructor_outside_scope_cannot_read_answers_download_template_or_commit(): void
    {
        [$admin, $version] = $this->fixture();
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($this->excel([$this->row()]))])->assertRedirect();
        $token = QuestionImport::firstOrFail()->token;
        foreach (['learner', 'instructor'] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/versions/'.$version->id.'/questions')->assertForbidden();
            $this->get('/versions/'.$version->id.'/questions/template')->assertForbidden();
            $this->post('/versions/'.$version->id.'/questions/commit', ['token' => $token])->assertForbidden();
        }
        $this->assertSame(0, DB::table('questions')->count());
    }

    public function test_preview_belongs_to_its_creator_and_expires_then_can_be_reuploaded(): void
    {
        [$admin, $version] = $this->fixture();
        $path = $this->excel([$this->row()]);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertRedirect();
        $import = QuestionImport::firstOrFail();
        $instructor = $this->userWithRole('instructor');
        $instructor->supervisedCategories()->attach($version->course->category_id);
        $this->actingAs($instructor)->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertNotFound();
        $this->travel(31)->minutes();
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/commit', ['token' => $import->token])->assertSessionHasErrors('file');
        $this->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($path)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame($import->token, $import->fresh()->token);
        $this->assertSame(1, QuestionImport::count());
    }

    public function test_choice_text_with_leading_zero_is_preserved_and_optional_columns_stay_in_place(): void
    {
        $row = $this->row();
        $row[3] = ' 00123 ';
        $row[9] = null;
        $row[10] = 'คำอธิบายท้ายแถว';
        $parsed = app(QuestionImportService::class)->parse($this->excel([$row]));
        $this->assertSame('0001', $parsed[0]['code']);
        $this->assertSame(' 00123 ', $parsed[0]['choices']['A']);
        $this->assertSame('ทั่วไป', $parsed[0]['category']);
        $this->assertSame('คำอธิบายท้ายแถว', $parsed[0]['explanation']);
    }

    public function test_import_stops_when_exam_already_published(): void
    {
        [$admin, $version] = $this->fixture();
        Exam::create(['course_version_id' => $version->id, 'exam_type' => 'POSTTEST', 'status' => 'published']);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => $this->upload($this->excel([$this->row()]))])->assertSessionHasErrors('file');
        $this->assertSame(0, QuestionImport::count());
    }
}
