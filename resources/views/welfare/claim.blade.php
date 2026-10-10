<!DOCTYPE html><html lang="th"><head><meta charset="utf-8"><style>
body{font-family:garuda;color:#11264d}h1{font-size:19pt;color:#1743dd}table{border-collapse:collapse;width:100%}th,td{border:1px solid #adc1ed;padding:5px;vertical-align:top}th{background:#dce6ff}small{font-size:8pt}.total{background:#cfddfa;padding:12px;font-size:14pt}
</style></head><body>
<h1>ใบสรุปคูปองเพื่อส่งเบิก</h1>
<p>{{ $claim->organization_name }}<br>ร้าน: {{ $claim->store_name }}<br>เลขที่: {{ $claim->claim_id }}<br>ช่วงวันที่รับคูปอง: {{ $claim->start_date }} ถึง {{ $claim->end_date }}<br>จัดทำเมื่อ: {{ $claim->created_at->format('d/m/Y H:i') }}</p>
<p class="total">{{ number_format($claim->coupon_count) }} ใบ · รวม {{ number_format($claim->total_satang / 100, 2) }} บาท</p>
<p>ใบนี้เป็นรายการคูปองที่ใช้แล้วและเลือกส่งเบิก ยังไม่ใช่หลักฐานการอนุมัติหรือจ่ายเงินจริง</p>
@if($claim->note)<p>หมายเหตุ: {{ $claim->note }}</p>@endif
<table><thead><tr><th>ลำดับ</th><th>เลขอ้างอิง / รายการ</th><th>ผู้ถือคูปอง</th><th>วันที่ใช้</th><th>บาท</th></tr></thead><tbody>
@foreach($claim->items as $index => $item)
<tr><td>{{ $index + 1 }}</td><td>{{ $item->reward_name }}<br><small>{{ $item->coupon_id }}</small></td><td>{{ $item->holder_name }}</td><td>{{ $item->received_at->format('d/m/Y H:i') }}</td><td>{{ number_format($item->discount_satang / 100, 2) }}</td></tr>
@endforeach
</tbody></table>
<p>เลขอ้างอิงในใบนี้ไม่ใช่รหัสสำหรับใช้คูปอง รายการที่อยู่ในใบส่งเบิกแล้วไม่สามารถนำไปสร้างใบใหม่ซ้ำได้</p>
<p>ผู้ส่งเบิก ..................................... วันที่ ............................<br><br>ผู้ตรวจสอบ ..................................... วันที่ ............................</p>
</body></html>
