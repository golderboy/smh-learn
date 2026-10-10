export interface ContentRecord {
    id: number;
    title: string;
    content_type: 'video' | 'document' | 'image' | 'article' | 'link';
    video_source?: 'upload' | 'youtube' | 'remote' | null;
    video_id?: string | null;
    original_name: string | null;
    file_size: number | null;
    duration_seconds: number | null;
    body: string | null;
    external_url: string | null;
    is_required: boolean;
    sort_order: number;
}
export interface LessonRecord {
    id: number;
    module_id: number;
    title: string;
    description: string | null;
    sort_order: number;
    is_required: boolean;
    status: string;
    contents: ContentRecord[];
    documents?: ContentRecord[];
}
export interface ModuleRecord {
    id: number;
    title: string;
    sort_order: number;
    lessons: LessonRecord[];
}
export interface CourseRecord {
    id: number;
    code: string;
    title: string;
    description: string | null;
    status: string;
    category: { id: number; name: string };
    versions_count?: number;
}
export interface VersionRecord {
    id: number;
    version_name: string;
    version_number: number;
    published_at: string | null;
    modules: ModuleRecord[];
    sessions: SessionRecord[];
    course?: CourseRecord;
}
export interface SessionRecord {
    id: number;
    name: string;
    fiscal_year: number;
    start_date: string;
    end_date: string;
    enrollment_mode: string;
    status: string;
    max_attempts: number;
    required_video_watch_percent: string;
    required_progress_percent: string;
    passing_score: string;
    posttest_required: boolean;
    pretest_required: boolean;
    points_enabled: boolean;
    point_rules_snapshot: Record<
        string,
        { points: number; threshold_percent: number | null }
    > | null;
    version?: VersionRecord;
    enrollments?: EnrollmentRecord[];
}
export interface EnrollmentRecord {
    id: number;
    course_session_id: number;
    user_id: number;
    state: string;
    progress_percent: string;
    due_date: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    pretest_completed_at: string | null;
    posttest_completed_at: string | null;
    content_completed_at: string | null;
    session: SessionRecord;
    user?: { id: number; display_name: string };
}
export interface ProgressRecord {
    id: number;
    content_id: number;
    last_position_seconds: string;
    watched_seconds: string;
    watch_percent: string;
    opened_at: string | null;
    completed_at: string | null;
}
export const stateLabels: Record<string, string> = {
    ASSIGNED: 'ได้รับมอบหมาย',
    NOT_STARTED: 'ยังไม่เริ่มเรียน',
    PRETEST_COMPLETED: 'ทำ Pretest แล้ว',
    LEARNING: 'กำลังเรียน',
    CONTENT_COMPLETED: 'เรียนครบ รอ Posttest',
    POSTTEST_COMPLETED: 'สอบ Posttest แล้ว',
    FAILED: 'สอบไม่ผ่าน',
    RETAKE_ALLOWED: 'สอบซ้ำได้',
    PASSED: 'สอบผ่าน',
    COMPLETED: 'เรียนจบ',
    CANCELLED: 'ยกเลิกการเรียน',
};
export const dateLabel = (date: string) =>
    new Date(date.slice(0, 10) + 'T00:00:00').toLocaleDateString('th-TH');
