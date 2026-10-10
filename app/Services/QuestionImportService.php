<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Exam;
use App\Models\QuestionImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

class QuestionImportService
{
    public const HEADERS = ['รหัสข้อ', 'ใช้กับ', 'คำถาม', 'ตัวเลือก A', 'ตัวเลือก B', 'ตัวเลือก C', 'ตัวเลือก D', 'ตัวเลือก E', 'ตัวเลือก F', 'คำตอบที่ถูก', 'คะแนนเต็ม', 'หมวดข้อสอบ', 'คำอธิบาย'];

    public const LEGACY_HEADERS = ['รหัสข้อ', 'ใช้กับ', 'คำถาม', 'ตัวเลือก A', 'ตัวเลือก B', 'ตัวเลือก C', 'ตัวเลือก D', 'คำตอบที่ถูก', 'คะแนนเต็ม', 'หมวดข้อสอบ', 'คำอธิบาย'];

    public function __construct(private readonly CourseEditorService $editor) {}

    /** @return list<array<string, mixed>> */
    public function parse(string $path): array
    {
        $this->checkArchive($path);
        $reader = new Reader;
        $rows = [];
        $codes = [];
        $found = false;
        $headers = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Questions') {
                    continue;
                }
                $found = true;
                foreach ($sheet->getRowIterator() as $line => $row) {
                    foreach ($row->cells as $cell) {
                        if ($cell instanceof FormulaCell) {
                            $this->invalid("แถว {$line}: ไม่อนุญาตสูตร Excel กรุณาใช้ข้อความหรือค่าตัวเลข");
                        }
                    }
                    $values = array_values($row->toArray());
                    if ($line === 1) {
                        if ($values !== self::HEADERS && $values !== self::LEGACY_HEADERS) {
                            $this->invalid('ชื่อคอลัมน์ไม่ตรงแม่แบบ กรุณาใช้ไฟล์แม่แบบของระบบ');
                        }
                        $headers = $values;

                        continue;
                    }
                    if ($row->isEmpty()) {
                        continue;
                    }
                    if (count($rows) >= config('learning.excel_max_rows') || count($values) > count($headers)) {
                        $this->invalid('รองรับไม่เกิน 1,000 ข้อและจำนวนคอลัมน์ตามแม่แบบ (ใหม่ 13 คอลัมน์ หรือเดิม 11 คอลัมน์)');
                    }
                    $cells = array_combine($headers, array_pad($values, count($headers), null));
                    $code = $cells['รหัสข้อ'];
                    $usage = $cells['ใช้กับ'];
                    $question = $cells['คำถาม'];
                    $correct = $cells['คำตอบที่ถูก'];
                    if (is_string($correct)) {
                        $correct = array_flip(AssessmentRules::CHOICE_LABELS)[trim($correct)] ?? strtoupper(trim($correct));
                    }
                    $score = $cells['คะแนนเต็ม'];
                    $category = $cells['หมวดข้อสอบ'];
                    $explanation = $cells['คำอธิบาย'];
                    if (! is_string($code) || ! preg_match('/^[A-Za-z0-9_-]{1,50}$/', $code) || isset($codes[$code])) {
                        $this->invalid("แถว {$line}: รหัสข้อต้องเป็นข้อความอักษรอังกฤษ/ตัวเลข/_/- ไม่เกิน 50 ตัวและไม่ซ้ำ");
                    }
                    if (! in_array($usage, ['PRETEST', 'POSTTEST', 'BOTH'], true) || ! is_string($question) || trim($question) === '' || mb_strlen($question) > 10000 || ! in_array($correct, AssessmentRules::CHOICE_KEYS, true)) {
                        $this->invalid("แถว {$line}: ระบุ PRETEST/POSTTEST/BOTH พร้อมคำถาม และคำตอบที่ถูกเป็น A–F หรือ ก ข ค ง จ ฉ เพียงหนึ่งตัว");
                    }
                    $choices = [];
                    $emptyChoice = false;
                    foreach (AssessmentRules::CHOICE_KEYS as $key) {
                        $choice = $cells['ตัวเลือก '.$key] ?? null;
                        if (in_array($key, ['E', 'F'], true) && ($choice === null || (is_string($choice) && trim($choice) === ''))) {
                            $emptyChoice = true;

                            continue;
                        }
                        if ($emptyChoice) {
                            $this->invalid("แถว {$line}: หากมีตัวเลือก F (ฉ) ต้องระบุตัวเลือก E (จ) ด้วย");
                        }
                        if (! is_string($choice) || trim($choice) === '' || mb_strlen($choice) > 5000) {
                            $this->invalid("แถว {$line}: ตัวเลือก A–D ต้องครบ ส่วน E/F เพิ่มได้ตามลำดับ ทุกตัวเลือกต้องเป็นข้อความไม่เกิน 5,000 ตัวอักษร");
                        }
                        $choices[$key] = $choice;
                    }
                    if (count(array_unique(array_map('trim', $choices))) !== count($choices)) {
                        $this->invalid("แถว {$line}: ข้อความตัวเลือกต้องไม่ซ้ำกัน");
                    }
                    if (! array_key_exists($correct, $choices)) {
                        $this->invalid("แถว {$line}: เฉลยต้องตรงกับตัวเลือกที่มีข้อความในข้อนี้");
                    }
                    if ((! is_int($score) && ! is_float($score)) || ! is_finite((float) $score) || $score <= 0 || $score > 10000 || abs(round($score, 2) - $score) > 0.000001) {
                        $this->invalid("แถว {$line}: คะแนนเต็มต้องเป็นตัวเลขมากกว่า 0 ถึง 10,000 และทศนิยมไม่เกิน 2 ตำแหน่ง");
                    }
                    if (($category !== null && (! is_string($category) || mb_strlen($category) > 150)) || ($explanation !== null && (! is_string($explanation) || mb_strlen($explanation) > 10000))) {
                        $this->invalid("แถว {$line}: หมวดข้อสอบหรือคำอธิบายไม่ถูกต้อง");
                    }
                    $codes[$code] = true;
                    $rows[] = ['line' => $line, 'code' => $code, 'usage' => $usage, 'question_type' => 'SINGLE_CHOICE', 'question_text' => $question, 'choices' => $choices, 'correct_answer' => $correct, 'max_score' => $score, 'category' => $category ?: 'ทั่วไป', 'explanation' => $explanation];
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->invalid('อ่านไฟล์ Excel ไม่ได้ กรุณาบันทึกเป็น .xlsx ตามแม่แบบ');
        } finally {
            $reader->close();
        }
        if (! $found || $rows === []) {
            $this->invalid('ต้องมีชีต Questions และข้อสอบอย่างน้อย 1 ข้อ');
        }

        return $rows;
    }

    public function preview(Request $request, CourseVersion $version, string $path, string $name): QuestionImport
    {
        $rows = $this->parse($path);

        return $this->editor->mutate($request, $version->course, 'assessment.import_previewed', function (Course $course) use ($request, $version, $path, $name, $rows): QuestionImport {
            $this->assertDraftExams($version);
            $this->assertCodesAvailable($course->id, $rows);
            $sha = hash_file('sha256', $path);
            $existing = QuestionImport::where('course_version_id', $version->id)->where('sha256', $sha)->first();
            if ($existing !== null) {
                if ($existing->committed_at === null && $existing->created_by === $request->user()->id && $existing->expires_at->isPast()) {
                    $existing->update(['token' => (string) Str::uuid(), 'rows' => $rows, 'expires_at' => now()->addMinutes(30)]);

                    return $existing;
                }
                $this->invalid('ไฟล์นี้เคยสร้างรายการนำเข้าแล้ว กรุณายืนยันรายการเดิมหรือใช้ไฟล์ใหม่');
            }

            return QuestionImport::create(['token' => (string) Str::uuid(), 'course_version_id' => $version->id, 'created_by' => $request->user()->id, 'file_name' => mb_substr(basename($name), 0, 255), 'sha256' => $sha, 'rows' => $rows, 'expires_at' => now()->addMinutes(30)]);
        });
    }

    public function commit(Request $request, CourseVersion $version, string $token): void
    {
        $this->editor->mutate($request, $version->course, 'assessment.questions_imported', function (Course $course) use ($request, $version, $token): void {
            $this->assertDraftExams($version);
            $import = QuestionImport::where('token', $token)->where('course_version_id', $version->id)->where('created_by', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($import->committed_at !== null) {
                return; // Safe repeat of the same confirm request.
            }
            if ($import->expires_at->isPast()) {
                $this->invalid('รายการนำเข้าหมดอายุ กรุณาอัปโหลดไฟล์ใหม่');
            }
            $rows = $import->rows;
            $this->assertCodesAvailable($course->id, $rows);
            foreach ($rows as $row) {
                $category = DB::table('question_categories')->where('course_id', $course->id)->where('name', $row['category'])->first();
                $categoryId = $category === null ? DB::table('question_categories')->insertGetId(['course_id' => $course->id, 'name' => $row['category'], 'created_at' => now(), 'updated_at' => now()]) : $category->id;
                $questionId = DB::table('questions')->insertGetId(['course_id' => $course->id, 'category_id' => $categoryId, 'code' => $row['code'], 'question_type' => 'SINGLE_CHOICE', 'usage' => $row['usage'], 'question_text' => $row['question_text'], 'explanation' => $row['explanation'], 'max_score' => $row['max_score'], 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $order = 1;
                foreach ($row['choices'] as $key => $choice) {
                    DB::table('question_choices')->insert(['question_id' => $questionId, 'choice_key' => $key, 'choice_text' => $choice, 'is_correct' => $key === $row['correct_answer'], 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()]);
                }
                $types = $row['usage'] === 'BOTH' ? ['PRETEST', 'POSTTEST'] : [$row['usage']];
                foreach ($types as $type) {
                    $exam = Exam::firstOrCreate(['course_version_id' => $version->id, 'exam_type' => $type], ['grading_mode' => 'SINGLE_CHOICE', 'status' => 'draft']);
                    DB::table('exam_questions')->insert(['exam_id' => $exam->id, 'question_id' => $questionId, 'sort_order' => DB::table('exam_questions')->where('exam_id', $exam->id)->count() + 1, 'question_snapshot' => json_encode([...$row, 'grading_mode' => 'SINGLE_CHOICE'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                }
            }
            $import->update(['committed_at' => now()]);
        });
    }

    private function assertDraftExams(CourseVersion $version): void
    {
        if ($version->exams()->where('status', '!=', 'draft')->exists() || DB::table('exam_attempts')->whereIn('exam_id', $version->exams()->pluck('id'))->exists()) {
            $this->invalid('ข้อสอบเวอร์ชันนี้เปิดใช้แล้ว ต้องสร้างเวอร์ชันข้อสอบใหม่ก่อนนำเข้า');
        }
    }

    public function clear(Request $request, CourseVersion $version): void
    {
        $this->editor->mutate($request, $version->course, 'assessment.questions_cleared', function () use ($version): void {
            $fresh = CourseVersion::findOrFail($version->id);
            $this->editor->draft($fresh);
            $this->assertDraftExams($fresh);
            $examIds = $fresh->exams()->pluck('id');
            $questionIds = DB::table('exam_questions')->whereIn('exam_id', $examIds)->pluck('question_id');
            DB::table('exam_questions')->whereIn('exam_id', $examIds)->delete();
            DB::table('questions')->whereIn('id', $questionIds)->update(['is_active' => false, 'updated_at' => now()]);
            $fresh->exams()->update(['question_count' => null]);
            QuestionImport::where('course_version_id', $fresh->id)->delete();
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertCodesAvailable(int $courseId, array $rows): void
    {
        $duplicates = DB::table('questions')->where('course_id', $courseId)->where('is_active', true)->whereIn('code', array_column($rows, 'code'))->pluck('code');
        if ($duplicates->isNotEmpty()) {
            $this->invalid('รหัสข้อซ้ำกับคลังข้อสอบ: '.$duplicates->take(10)->implode(', '));
        }
    }

    public function checkArchive(string $path): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('ไฟล์ต้องเป็น Excel .xlsx');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 1000 || $zip->locateName('xl/workbook.xml') === false) {
                $this->invalid('โครงสร้าง Excel ไม่ถูกต้อง');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                $total += $stat['size'];
                if ($total > 50 * 1024 * 1024 || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/vbaProject|externalLinks/i', $name)) {
                    $this->invalid('ไฟล์มีข้อมูลเกินขนาดหรือมี Macro/ลิงก์ภายนอกที่ไม่รองรับ');
                }
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $xml = $zip->getFromIndex($index);
                    if (preg_match('/<!DOCTYPE|<!ENTITY|TargetMode\s*=\s*["\x27]External/i', $xml)) {
                        $this->invalid('ไฟล์มีการอ้างอิงภายนอกที่ไม่รองรับ');
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
