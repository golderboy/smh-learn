<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\MasterImport;
use App\Models\Position;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WelfareStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

class MasterImportService
{
    public const INITIAL_PASSWORD = 'learn123456';

    public const DEFINITIONS = [
        'departments' => ['name' => 'แผนก', 'permission' => 'organization.manage', 'headers' => ['รหัสแผนก', 'ชื่อแผนก'], 'fields' => ['code', 'name']],
        'positions' => ['name' => 'ตำแหน่ง', 'permission' => 'organization.manage', 'headers' => ['รหัสตำแหน่ง', 'ชื่อตำแหน่ง'], 'fields' => ['code', 'name']],
        'categories' => ['name' => 'หมวดหลักสูตร', 'permission' => 'categories.manage', 'headers' => ['รหัสหมวด', 'ชื่อหมวด', 'คำอธิบาย'], 'fields' => ['code', 'name', 'description']],
        'users' => ['name' => 'ผู้ใช้งาน', 'permission' => 'users.manage', 'headers' => ['หมายเลขประจำตัว', 'รหัสบุคลากร', 'ชื่อ', 'นามสกุล', 'รหัสแผนก', 'รหัสตำแหน่ง', 'อีเมล', 'โทรศัพท์', 'ประเภทบุคลากร'], 'fields' => ['username', 'employee_code', 'first_name', 'last_name', 'department_code', 'position_code', 'email', 'phone', 'employment_status']],
        'stores' => ['name' => 'ร้านค้าสวัสดิการ', 'permission' => 'welfare.manage', 'headers' => ['รหัสร้าน', 'ชื่อร้าน', 'ข้อมูลติดต่อ'], 'fields' => ['code', 'name', 'contact']],
    ];

    public function __construct(private readonly AuditService $audit, private readonly QuestionImportService $excel) {}

    public function authorize(Request $request, string $kind): User
    {
        abort_unless(isset(self::DEFINITIONS[$kind]), 404);
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasRole('administrator') && $actor->hasPermission('master.import') && $actor->hasPermission(self::DEFINITIONS[$kind]['permission']), 403);

        return $actor;
    }

    /** @return list<array{line: int, data: array<string, mixed>, skip: bool}> */
    public function parse(string $kind, string $path): array
    {
        $this->excel->checkArchive($path);
        $definition = self::DEFINITIONS[$kind];
        $reader = new Reader;
        $rows = [];
        $found = false;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Data') {
                    continue;
                }
                $found = true;
                foreach ($sheet->getRowIterator() as $line => $row) {
                    foreach ($row->cells as $cell) {
                        if ($cell instanceof FormulaCell) {
                            $this->invalid("แถว {$line}: ไม่อนุญาตสูตร Excel");
                        }
                    }
                    $values = array_values($row->toArray());
                    if ($line === 1) {
                        if ($values !== $definition['headers']) {
                            $this->invalid('ชื่อคอลัมน์ไม่ตรงกับหัวข้อที่เลือก กรุณาใช้เทมเพลตของระบบ');
                        }

                        continue;
                    }
                    if ($row->isEmpty()) {
                        continue;
                    }
                    if (count($rows) >= 1000 || count($values) > count($definition['fields'])) {
                        $this->invalid('รองรับไม่เกิน 1,000 รายการและจำนวนคอลัมน์ตามเทมเพลต');
                    }
                    $values = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, array_pad($values, count($definition['fields']), null));
                    if (count(array_filter($values, fn ($value) => $value !== null)) === 0) {
                        continue;
                    }
                    $data = array_combine($definition['fields'], $values);
                    if ($kind === 'users') {
                        $data['employee_code'] ??= $data['username'];
                    }
                    $rows[] = ['line' => (int) $line, 'data' => $data, 'skip' => false];
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->invalid('อ่าน Excel ไม่ได้ กรุณาบันทึกเป็น .xlsx ตามเทมเพลต');
        } finally {
            $reader->close();
        }
        if (! $found || $rows === []) {
            $this->invalid('ต้องมีชีต Data และข้อมูลอย่างน้อย 1 แถว');
        }
        $rows = $this->classifyRows($kind, $rows);
        $this->validateRows($kind, $rows);

        return $rows;
    }

    /** @param list<array{line: int, data: array<string, mixed>, skip: bool}> $rows
     * @return list<array{line: int, data: array<string, mixed>, skip: bool}>
     */
    private function classifyRows(string $kind, array $rows): array
    {
        if ($kind !== 'users') {
            return $rows;
        }
        $seen = [];
        foreach ($rows as &$row) {
            $username = $row['data']['username'];
            if (is_string($username) && preg_match('/\A[0-9]{1,15}\z/', $username)) {
                $row['skip'] = isset($seen[$username]) || User::where('username', $username)->exists();
                $seen[$username] = true;
            } else {
                $row['skip'] = false;
            }
        }
        unset($row);

        return $rows;
    }

    /** @param list<array{line: int, data: array<string, mixed>, skip: bool}> $rows */
    private function validateRows(string $kind, array $rows): void
    {
        $table = match ($kind) {
            'users' => 'users', 'categories' => 'course_categories', 'stores' => 'welfare_stores', default => $kind
        };
        $rules = $kind === 'users' ? [
            'username' => ['required', 'string', 'regex:/\A[0-9]{1,15}\z/', Rule::unique('users', 'username')],
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('users', 'employee_code')],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'department_code' => ['nullable', 'string', 'max:50', Rule::exists('departments', 'code')->where('is_active', true)],
            'position_code' => ['nullable', 'string', 'max:50', Rule::exists('positions', 'code')->where('is_active', true)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'], 'employment_status' => ['nullable', 'string', 'max:100'],
        ] : [
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]+\z/', 'max:'.($kind === 'stores' ? 40 : 50), Rule::unique($table, 'code')],
            'name' => ['required', 'string', 'max:'.($kind === 'stores' ? 200 : 150)],
            'description' => ['nullable', 'string', 'max:5000'], 'contact' => ['nullable', 'string', 'max:250'],
        ];
        $seen = [];
        $errors = [];
        $labels = array_combine(self::DEFINITIONS[$kind]['fields'], self::DEFINITIONS[$kind]['headers']);
        foreach ($rows as $row) {
            if ($row['skip']) {
                continue;
            }
            $validator = Validator::make($row['data'], $rules, [
                'required' => ':attribute ต้องไม่ว่าง', 'string' => ':attribute ต้องเป็นข้อความ (ตั้งรูปแบบเซลล์เป็น Text เพื่อรักษาเลขศูนย์นำหน้า)',
                'regex' => ':attribute มีรูปแบบไม่ถูกต้อง', 'unique' => ':attribute มีอยู่ในระบบแล้ว', 'exists' => ':attribute ไม่พบหรือปิดใช้งาน กรุณานำเข้าข้อมูลอ้างอิงก่อน',
                'max' => ':attribute ยาวเกินจำนวนที่กำหนด', 'email' => ':attribute ไม่ใช่อีเมลที่ถูกต้อง',
            ], $labels);
            foreach ($validator->errors()->all() as $message) {
                $errors[] = "แถว {$row['line']}: {$message}";
            }
            foreach ($kind === 'users' ? ['username', 'employee_code', 'email'] : ['code'] as $field) {
                $value = $row['data'][$field] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $key = $field.':'.mb_strtolower($value);
                if (isset($seen[$key])) {
                    $errors[] = "แถว {$row['line']}: {$labels[$field]} ซ้ำกับแถว {$seen[$key]}";
                }
                $seen[$key] = $row['line'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 100)]);
        }
    }

    public function preview(Request $request, string $kind, string $path, string $name): MasterImport
    {
        $actor = $this->authorize($request, $kind);
        $rows = $this->parse($kind, $path);
        MasterImport::where('created_by', $actor->id)->whereNull('committed_at')->where('kind', $kind)->delete();

        return MasterImport::create(['token' => (string) Str::uuid(), 'kind' => $kind, 'created_by' => $actor->id, 'file_name' => mb_substr(basename($name), 0, 255), 'sha256' => hash_file('sha256', $path), 'rows' => $rows, 'expires_at' => now()->addMinutes(30)]);
    }

    public function commit(Request $request, string $kind, string $token): int
    {
        return DB::transaction(function () use ($request, $kind, $token): int {
            SystemSetting::where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($request, $kind);
            $import = MasterImport::where('token', $token)->where('kind', $kind)->where('created_by', $actor->id)->lockForUpdate()->firstOrFail();
            if ($import->committed_at !== null) {
                return 0;
            }
            if ($import->expires_at->isPast()) {
                $this->invalid('ตัวอย่างหมดอายุ กรุณาตรวจไฟล์ใหม่');
            }
            $rows = $this->classifyRows($kind, $import->rows);
            $this->validateRows($kind, $rows); // Recheck duplicates/references under the same lock used by administration.
            $count = 0;
            // All new accounts use the same mandatory temporary password. Hash it once per
            // import; their permanent passwords receive independent hashes on first sign-in.
            $initialHash = $kind === 'users' && collect($rows)->contains(fn ($row) => ! $row['skip']) ? Hash::make(self::INITIAL_PASSWORD) : null;
            $learnerId = $kind === 'users' ? Role::where('code', 'learner')->firstOrFail()->id : null;
            foreach ($rows as $row) {
                if ($row['skip']) {
                    continue;
                }
                $data = $row['data'];
                if ($kind === 'users') {
                    $data['department_id'] = $data['department_code'] === null ? null : Department::where('code', $data['department_code'])->value('id');
                    $data['position_id'] = $data['position_code'] === null ? null : Position::where('code', $data['position_code'])->value('id');
                    unset($data['department_code'], $data['position_code']);
                    $user = User::create([...$data, 'display_name' => $data['first_name'].' '.$data['last_name'], 'password_hash' => $initialHash, 'must_change_password' => true, 'is_active' => true, 'is_demo' => false]);
                    $user->roles()->attach($learnerId);
                } else {
                    $record = match ($kind) {
                        'departments' => new Department, 'positions' => new Position, 'categories' => new CourseCategory, 'stores' => new WelfareStore,
                        default => throw new \LogicException('Unsupported master import kind.'),
                    };
                    if (in_array($kind, ['categories', 'stores'], true)) {
                        $data['created_by'] = $actor->id;
                    }
                    $record->fill($data)->save();
                }
                $count++;
            }
            $import->update(['committed_at' => now(), 'rows' => []]);
            $this->audit->record($request, 'master_data.imported', 'master_import', $import->id, new: ['kind' => $kind, 'count' => $count, 'skipped' => count($rows) - $count, 'sha256' => $import->sha256]);

            return $count;
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
