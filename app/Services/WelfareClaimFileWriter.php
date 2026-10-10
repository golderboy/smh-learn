<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WelfareClaim;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

class WelfareClaimFileWriter
{
    public function download(WelfareClaim $claim, string $format): Response
    {
        $filename = 'welfare-claim-'.$claim->claim_id.'.'.$format;
        if ($format === 'pdf') {
            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'garuda', 'default_font_size' => 10, 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 16, 'tempDir' => storage_path('app/private/mpdf')]);
            $pdf->SetTitle('ใบสรุปคูปองเพื่อส่งเบิก');
            $pdf->SetHTMLFooter('<div style="text-align:right">หน้า {PAGENO} / {nbpg}</div>');
            $pdf->WriteHTML(view('welfare.claim', ['claim' => $claim])->render());
            $bytes = $pdf->Output('', Destination::STRING_RETURN);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
        }
        abort_unless($format === 'csv', 404);

        return response()->streamDownload(function () use ($claim): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open claim output.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            $safe = fn ($value) => preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', (string) $value) ? "'".$value : $value;
            $row = static function (array $values) use ($stream, $safe): void {
                fputcsv($stream, array_map($safe, $values), ',', '"', '', "\r\n");
            };
            $row(['ใบสรุปส่งเบิก', $claim->claim_id, 'ยังไม่ใช่หลักฐานการจ่ายเงินจริง']);
            $row(['หน่วยงาน', $claim->organization_name, 'ร้าน', $claim->store_name]);
            $row(['ช่วงวันที่', $claim->start_date, $claim->end_date]);
            $row(['จำนวนคูปอง', $claim->coupon_count, 'รวมบาท', number_format($claim->total_satang / 100, 2, '.', '')]);
            $row(['ลำดับ', 'เลขอ้างอิงคูปอง (ไม่ใช่รหัสใช้คูปอง)', 'รายการ', 'ผู้ถือคูปอง', 'รับคูปองเมื่อ', 'มูลค่าบาท']);
            foreach ($claim->items as $index => $item) {
                $row([$index + 1, $item->coupon_id, $item->reward_name, $item->holder_name, $item->received_at->format('d/m/Y H:i'), number_format($item->discount_satang / 100, 2, '.', '')]);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
