import type { Paginated } from './foundation';
export type ReportType = 'individual' | 'course' | 'department' | 'question';
export const reportNames: Record<ReportType, string> = {
    individual: 'รายบุคคล',
    course: 'หลักสูตร',
    department: 'แผนก',
    question: 'วิเคราะห์ข้อสอบ',
};
export interface ExportRecord {
    token: string;
    report_type: ReportType;
    format: string;
    status: string;
    row_count: number | null;
    created_at: string;
    error_message: string | null;
    can_download: boolean;
    expires_at: string | null;
    file_size: number | null;
}
export interface ReportData {
    columns: Record<string, string>;
    rows: Paginated<Record<string, string | number | boolean | null>>;
    summary: Record<string, number | null>;
}
export interface PointEntry {
    id: number;
    user_id: number;
    points: number;
    transaction_type: string;
    description: string;
    created_at: string;
}
export const pointNames: Record<string, string> = {
    COMPLETE_COURSE: 'จบหลักสูตร',
    PASS_POSTTEST: 'Posttest ผ่านเกณฑ์',
    HIGH_SCORE: 'โบนัสคะแนนสูง',
    ADMIN_ADJUSTMENT: 'ผู้ดูแลปรับแต้ม',
    REDEEM_REWARD: 'แลกแต้มเป็นคูปอง',
};
export function numberLabel(value: number | null | undefined): string {
    return value == null ? '—' : value.toLocaleString('th-TH', { maximumFractionDigits: 2 });
}
