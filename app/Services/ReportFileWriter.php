<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ReportFileWriter
{
    public const TYPES = ['individual' => 'รายงานรายบุคคล', 'course' => 'รายงานหลักสูตร', 'department' => 'รายงานแผนก', 'question' => 'วิเคราะห์ข้อสอบ'];

    public const STATES = ['COMPLETED' => 'เรียนจบ', 'CANCELLED' => 'ยกเลิก', 'NOT_STARTED' => 'ยังไม่เริ่ม', 'ASSIGNED' => 'มอบหมายแล้ว', 'PRETEST_COMPLETED' => 'ทำ Pretest แล้ว', 'LEARNING' => 'กำลังเรียน', 'CONTENT_COMPLETED' => 'รอ Posttest'];

    /** @param array<string,string> $columns
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    public function write(string $format, string $path, string $type, array $columns, Collection $rows): void
    {
        if ($format === 'xlsx') {
            $writer = new Writer;
            try {
                $writer->openToFile($path);
                $writer->addRow(new Row(array_map(fn ($value): Cell => new StringCell($value), array_values($columns))));
                foreach ($rows as $record) {
                    $values = $this->values($columns, $record);
                    $writer->addRow(new Row(array_map(fn ($value): Cell => is_string($value) ? new StringCell($value) : Cell::fromValue($value), $values)));
                }
            } finally {
                $writer->close();
            }

            return;
        }
        if ($format === 'csv') {
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open report output.');
            }
            try {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, array_values($columns), ',', '"', '', "\r\n");
                foreach ($rows as $record) {
                    $values = array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) ? "'".$value : $value, $this->values($columns, $record));
                    if (isset($columns['employee_code']) && is_string($values[0]) && preg_match('/^0\d+$/', $values[0])) {
                        $values[0] = "'".$values[0];
                    }
                    fputcsv($handle, $values, ',', '"', '', "\r\n");
                }
            } finally {
                fclose($handle);
            }

            return;
        }
        if ($format !== 'pdf') {
            throw new \InvalidArgumentException('Unsupported export format.');
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A3-L', 'default_font' => 'garuda', 'default_font_size' => 10, 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 14, 'tempDir' => storage_path('app/private/mpdf')]);
        $pdf->SetTitle(self::TYPES[$type]);
        $pdf->SetAuthor(config('identity.product_name'));
        $pdf->SetHTMLFooter('<div style="text-align:right;font-size:9pt">หน้า {PAGENO} / {nbpg}</div>');
        $escape = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<style>body{font-family:garuda;color:#0f172a}h1{font-size:18pt;color:#0b725a}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #cfe1dc;padding:4px;vertical-align:top}th{background:#e8f7f3;font-weight:bold}tbody tr:nth-child(even){background:#f6fbf9}</style><h1>'.$escape(self::TYPES[$type]).'</h1><p>'.$escape(app(FoundationSettings::class)->organizationName()).' · '.$escape(now()->format('d/m/Y H:i')).' · '.$rows->count().' รายการ</p><p>แผนก/ตำแหน่ง ณ สมัครเรียน · Posttest คะแนนสูงสุด · ไม่มีคะแนนแสดง — · เวลาเป็นช่วงรับชมที่ระบบบันทึก</p><table><thead><tr>'.implode('', array_map(fn ($label): string => '<th>'.$escape($label).'</th>', array_values($columns))).'</tr></thead><tbody>';
        foreach ($rows as $record) {
            $html .= '<tr>'.implode('', array_map(fn ($value): string => '<td>'.$escape($value ?? '—').'</td>', $this->values($columns, $record))).'</tr>';
        }
        if ($rows->isEmpty()) {
            $html .= '<tr><td colspan="'.count($columns).'">ไม่พบข้อมูลตามตัวกรอง</td></tr>';
        }
        $pdf->WriteHTML($html.'</tbody></table>');
        $pdf->Output($path, Destination::FILE);
    }

    /** @param array<string,string> $columns
     * @param  array<string,mixed>  $record
     * @return list<string|int|float|null>
     */
    private function values(array $columns, array $record): array
    {
        return array_map(fn ($key) => $key === 'status' ? (self::STATES[$record[$key]] ?? $record[$key]) : ($record[$key] ?? null), array_keys($columns));
    }
}
