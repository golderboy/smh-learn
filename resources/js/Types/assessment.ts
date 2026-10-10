export const choiceLabels: Record<string, string> = {
    A: 'ก',
    B: 'ข',
    C: 'ค',
    D: 'ง',
    E: 'จ',
    F: 'ฉ',
};

export interface AttemptRecord {
    id: number;
    exam_id: number;
    exam_type?: 'PRETEST' | 'POSTTEST';
    enrollment_id: number;
    attempt_number: number;
    status: string;
    revision: number;
    expires_at: string | null;
    submitted_at: string | null;
    score: string | null;
    max_score: string | null;
    percentage: string | null;
    passed: boolean | null;
}
export interface AssessmentSummary {
    pretestReady: boolean;
    posttestReady: boolean;
    usedPosttestAttempts: number;
    maxPosttestAttempts: number;
    bestPosttest: AttemptRecord | null;
    attempts: AttemptRecord[];
}
export interface ExamSettingsRecord {
    id: number;
    type: string;
    count: number;
    status: string;
    question_count: number | null;
    time_limit_minutes: number | null;
    randomize_questions: boolean;
    randomize_choices: boolean;
    has_attempts: boolean;
}
