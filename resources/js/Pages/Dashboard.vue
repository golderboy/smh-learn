<script setup lang="ts">
import { useDateTime } from '../date-time';
const dateTimeLabel = useDateTime();
defineOptions({ name: 'LearningDashboard' });
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '../Components/AppIcon.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import ReportMetrics from '../Components/ReportMetrics.vue';
import { numberLabel } from '../Types/reporting';
import type { AuditRecord, CategoryRecord, SharedProps } from '../Types/foundation';
import type { EnrollmentRecord } from '../Types/learning';
import { stateLabels } from '../Types/learning';
const props = defineProps<{
    counts: {
        users: number;
        activeUsers: number;
        departments: number;
        positions: number;
        categories: number;
    } | null;
    supervisedCategories: CategoryRecord[];
    recentAudit: AuditRecord[];
    enrollments: EnrollmentRecord[];
    learningCounts: { notStarted: number; inProgress: number; completed: number; overdue: number };
    personalSummary: Record<string, number | null>;
    reportSummary: Record<string, number | null> | null;
    pointBalance: number;
    recentResults: {
        enrollment_id: number;
        course: string;
        pretest: number | null;
        posttest: number | null;
        gain: number | null;
        mandatory: boolean;
        due_date: string | null;
    }[];
}>();
const page = usePage<SharedProps>();
const view = ref<'mine' | 'work'>('mine');
const nextEnrollment = computed(() =>
    props.enrollments.find((item) => !item.cancelled_at && !item.completed_at),
);
const labels: Record<string, string> = {
    'auth.login': 'เข้าสู่ระบบ',
    'auth.logout': 'ออกจากระบบ',
    'auth.failed': 'เข้าสู่ระบบไม่สำเร็จ',
    'user.password_changed': 'เปลี่ยนรหัสผ่าน',
    'user.updated': 'แก้ไขบัญชี',
    'category.updated': 'กำหนดหมวดและผู้คุม',
    'user.created': 'เพิ่มบัญชี',
};
</script>
<template>
    <AppLayout title="ภาพรวม" subtitle="ข้อมูลบัญชีและพื้นที่การเรียนรู้ของคุณ">
        <template #heading-actions>
            <div
                v-if="reportSummary || counts || supervisedCategories.length"
                class="segmented-control"
                aria-label="มุมมองภาพรวม"
            >
                <button :aria-pressed="view === 'mine'" @click="view = 'mine'">
                    การเรียนของฉัน
                </button>
                <button :aria-pressed="view === 'work'" @click="view = 'work'">
                    งานตามสิทธิ์ของฉัน
                </button>
            </div>
        </template>
        <section
            v-if="
                page.props.auth.user?.permissions.includes('master.import') &&
                page.props.auth.user?.roles.some((role) => role.code === 'administrator')
            "
            class="surface section-pad admin-shortcuts"
            aria-label="เครื่องมือผู้ดูแลระบบ"
        >
            <div>
                <h2>เครื่องมือผู้ดูแลระบบ</h2>
                <p class="muted">นำเข้าข้อมูลพื้นฐานและจัดการบุคลากรขององค์กร</p>
            </div>
            <div class="button-row">
                <Link class="btn btn-primary" href="/admin/imports"
                    ><AppIcon name="document" />นำเข้าข้อมูลพื้นฐาน</Link
                ><Link
                    v-if="page.props.auth.user?.permissions.includes('users.manage')"
                    class="btn btn-secondary"
                    href="/admin/users"
                    >บัญชีบุคลากร</Link
                ><Link
                    v-if="page.props.auth.user?.permissions.includes('department.view')"
                    class="btn btn-secondary"
                    href="/department"
                    >บุคลากรในแผนก</Link
                >
            </div>
        </section>
        <template v-if="view === 'mine'">
            <section class="course-hero" aria-label="หลักสูตรที่เรียนต่อ">
                <div class="course-hero-copy">
                    <p class="hero-context">
                        <AppIcon name="play" />
                        {{
                            nextEnrollment
                                ? stateLabels[nextEnrollment.state]
                                : 'พื้นที่การเรียนรู้ของคุณ'
                        }}
                        <span v-if="nextEnrollment" class="hero-round">{{
                            nextEnrollment.session.name
                        }}</span>
                    </p>
                    <h2>
                        {{ nextEnrollment?.session.version?.course?.title || page.props.app.name }}
                    </h2>
                    <template v-if="nextEnrollment">
                        <div class="hero-progress">
                            <div>
                                <span>ความคืบหน้าในการเรียน</span
                                ><strong>{{ nextEnrollment.progress_percent }}%</strong>
                            </div>
                            <progress
                                :value="Number(nextEnrollment.progress_percent)"
                                max="100"
                                aria-label="ความก้าวหน้าหลักสูตรถัดไป"
                            ></progress>
                        </div>
                        <div class="hero-actions">
                            <Link
                                class="btn btn-primary hero-primary"
                                :href="`/learning/${nextEnrollment.id}`"
                                >{{
                                    !nextEnrollment.pretest_completed_at
                                        ? 'ทำ Pretest ก่อนเรียน'
                                        : nextEnrollment.content_completed_at &&
                                            !nextEnrollment.posttest_completed_at
                                          ? 'ทำ Posttest หลังเรียน'
                                          : 'เรียนต่อ'
                                }}<AppIcon name="arrow"
                            /></Link>
                            <Link class="btn btn-hero-secondary" href="/learning"
                                ><AppIcon name="book" /> การเรียนของฉัน</Link
                            >
                        </div>
                    </template>
                    <template v-else
                        ><p class="hero-session">
                            {{ page.props.app.branding?.login_description }}
                        </p>
                        <Link class="btn btn-primary hero-primary" href="/courses"
                            >เลือกหลักสูตร<AppIcon name="arrow" /></Link
                    ></template>
                </div>
                <div class="course-hero-art">
                    <img
                        :src="page.props.app.branding?.banner_url || '/images/cobalt-hero.webp'"
                        alt=""
                        width="640"
                        height="427"
                    />
                    <p v-if="page.props.app.branding?.banner_text">
                        {{ page.props.app.branding.banner_text }}
                    </p>
                </div>
            </section>
            <div class="personal-kpis" aria-label="สรุปการเรียนของฉัน">
                <Link class="personal-kpi kpi-blue" href="/learning"
                    ><span class="kpi-icon"><AppIcon name="book" :size="30" /></span>
                    <div>
                        <span>กำลังเรียน</span
                        ><strong>{{ learningCounts.inProgress }}<small>หลักสูตร</small></strong>
                    </div>
                    <AppIcon name="chevron"
                /></Link>
                <Link class="personal-kpi kpi-coral" href="/learning"
                    ><span class="kpi-icon"><AppIcon name="check" :size="30" /></span>
                    <div>
                        <span>เรียนจบแล้ว</span
                        ><strong>{{ learningCounts.completed }}<small>หลักสูตร</small></strong>
                    </div>
                    <AppIcon name="chevron"
                /></Link>
                <Link class="personal-kpi kpi-navy" href="/points"
                    ><span class="kpi-icon"><AppIcon name="star" :size="30" /></span>
                    <div>
                        <span>แต้มสะสม</span
                        ><strong>{{ numberLabel(pointBalance) }}<small>แต้ม</small></strong>
                    </div>
                    <AppIcon name="chevron"
                /></Link>
            </div>
            <div class="dashboard-feature-columns">
                <section class="dashboard-panel">
                    <div class="section-heading">
                        <h2 class="section-title"><AppIcon name="book" /> หลักสูตรล่าสุด</h2>
                        <Link class="text-link" href="/learning"
                            >ดูการเรียนทั้งหมด <AppIcon name="chevron" :size="18"
                        /></Link>
                    </div>
                    <div v-if="enrollments.length" class="dashboard-course-list">
                        <Link
                            v-for="item in enrollments.slice(0, 3)"
                            :key="item.id"
                            class="dashboard-course-row"
                            :href="`/learning/${item.id}`"
                            ><span class="course-thumbnail"
                                ><img
                                    :src="'/images/cobalt-hero.webp'"
                                    alt=""
                                    width="100"
                                    height="72"
                                    loading="lazy"
                            /></span>
                            <div>
                                <h3>{{ item.session.version?.course?.title }}</h3>
                                <p>{{ item.session.name }}</p>
                                <div class="course-row-status">
                                    <span class="badge">{{ stateLabels[item.state] }}</span
                                    ><progress
                                        :value="Number(item.progress_percent)"
                                        max="100"
                                        :aria-label="`ความก้าวหน้า ${item.session.version?.course?.title}`"
                                    ></progress
                                    ><strong>{{ item.progress_percent }}%</strong>
                                </div>
                            </div>
                            <AppIcon name="chevron" :size="20"
                        /></Link>
                    </div>
                    <div v-else class="empty-state">
                        <AppIcon name="book" :size="40" /><strong
                            >ยังไม่มีรายการเรียนที่มอบหมาย</strong
                        >
                        <p>เลือกหลักสูตรที่เปิดรับสมัคร หรือรอการมอบหมายจากผู้ดูแล</p>
                        <Link class="btn btn-primary" href="/courses">เลือกหลักสูตร</Link>
                    </div>
                    <p class="dashboard-context">
                        ยังไม่เริ่ม {{ learningCounts.notStarted }} · เกินกำหนด
                        {{ learningCounts.overdue }}
                    </p>
                </section>
                <section class="dashboard-panel">
                    <div class="section-heading">
                        <h2 class="section-title"><AppIcon name="chart" /> ผลการเรียนล่าสุด</h2>
                        <Link class="text-link" href="/reports?audience=mine"
                            >ดูผลทั้งหมด <AppIcon name="chevron" :size="18"
                        /></Link>
                    </div>
                    <div v-if="recentResults.length" class="dashboard-results">
                        <Link
                            v-for="result in recentResults.slice(0, 3)"
                            :key="result.enrollment_id"
                            :href="`/learning/${result.enrollment_id}`"
                            ><span class="result-icon"><AppIcon name="document" /></span>
                            <div>
                                <h3>{{ result.course }}</h3>
                                <p>
                                    Pretest
                                    {{
                                        result.pretest === null
                                            ? 'ยังไม่มีผลสอบ'
                                            : result.pretest + '%'
                                    }}
                                    · Posttest สูงสุด
                                    {{
                                        result.posttest === null
                                            ? 'ยังไม่มีผลสอบ'
                                            : result.posttest + '%'
                                    }}
                                </p>
                                <small>กำหนดเรียน {{ result.due_date || 'ไม่ระบุ' }}</small>
                            </div>
                            <AppIcon name="chevron" :size="18"
                        /></Link>
                    </div>
                    <div v-else class="empty-state">
                        <AppIcon name="chart" :size="40" /><strong>ยังไม่มีผลสอบ</strong>
                        <p>เมื่อทำแบบทดสอบแล้ว ผลการเรียนจะแสดงที่นี่</p>
                    </div>
                    <div class="dashboard-summary">
                        <h3>ผลการเรียนของฉัน</h3>
                        <ReportMetrics :summary="personalSummary" />
                    </div>
                </section>
            </div>
        </template>
        <template v-else>
            <section class="work-hero">
                <div>
                    <p class="hero-context"><AppIcon name="shield" /> งานตามสิทธิ์ของฉัน</p>
                    <h2>{{ page.props.app.organization }}</h2>
                    <p>ติดตามผลการเรียนและจัดการพื้นที่ที่คุณรับผิดชอบ</p>
                    <Link class="btn btn-primary" href="/reports"
                        >ดูรายงาน <AppIcon name="arrow"
                    /></Link>
                </div>
                <img
                    :src="page.props.app.branding?.banner_url || '/images/cobalt-hero.webp'"
                    alt=""
                    width="360"
                    height="240"
                />
            </section>
            <section v-if="reportSummary" class="surface section-pad">
                <div class="section-heading">
                    <h2 class="section-title">
                        <AppIcon name="chart" /> ผลการเรียนตามขอบเขตสิทธิ์
                    </h2>
                    <Link class="btn btn-secondary" href="/reports">ดูรายงาน</Link>
                </div>
                <ReportMetrics :summary="reportSummary" />
            </section>
            <section v-if="counts" class="metrics-row" aria-label="ข้อมูลภาพรวมองค์กร">
                <div>
                    <span>บุคลากรทั้งหมด</span><strong>{{ counts.users }}</strong
                    ><small>เปิดใช้งาน {{ counts.activeUsers }} คน</small>
                </div>
                <div>
                    <span>แผนก</span><strong>{{ counts.departments }}</strong
                    ><small>โครงสร้างองค์กร</small>
                </div>
                <div>
                    <span>ตำแหน่ง</span><strong>{{ counts.positions }}</strong
                    ><small>ตำแหน่งบุคลากร</small>
                </div>
                <div>
                    <span>หมวดหลักสูตร</span><strong>{{ counts.categories }}</strong
                    ><small>พื้นที่การเรียนรู้</small>
                </div>
            </section>
            <div class="dashboard-columns">
                <section class="surface section-pad">
                    <h2 class="section-title"><AppIcon name="user" /> บัญชีของฉัน</h2>
                    <dl class="detail-list">
                        <div>
                            <dt>ชื่อ</dt>
                            <dd>{{ page.props.auth.user?.display_name }}</dd>
                        </div>
                        <div>
                            <dt>แผนก</dt>
                            <dd>{{ page.props.auth.user?.department || 'ยังไม่ได้กำหนด' }}</dd>
                        </div>
                        <div>
                            <dt>ตำแหน่ง</dt>
                            <dd>{{ page.props.auth.user?.position || 'ยังไม่ได้กำหนด' }}</dd>
                        </div>
                        <div>
                            <dt>บทบาท</dt>
                            <dd>
                                {{
                                    page.props.auth.user?.roles.map((role) => role.name).join(', ')
                                }}
                            </dd>
                        </div>
                    </dl>
                    <Link class="btn btn-secondary" href="/profile">จัดการข้อมูลส่วนตัว</Link>
                </section>
                <section class="surface section-pad">
                    <h2 class="section-title"><AppIcon name="folder" /> หมวดที่คุณควบคุม</h2>
                    <div v-if="supervisedCategories.length" class="assigned-list">
                        <Link
                            v-for="category in supervisedCategories"
                            :key="category.id"
                            :href="`/categories/${category.id}`"
                            >{{ category.name }}<AppIcon name="chevron" :size="20"
                        /></Link>
                    </div>
                    <p v-else class="muted">ไม่มีหมวดที่มอบหมายให้ควบคุม</p>
                    <Link class="btn btn-secondary" href="/learning">การเรียนของฉัน</Link>
                </section>
            </div>
            <section v-if="recentAudit.length" class="surface section-pad audit-preview">
                <div class="section-heading">
                    <h2 class="section-title"><AppIcon name="clock" /> การดำเนินงานล่าสุด</h2>
                    <Link class="text-link" href="/admin/audit">ดูประวัติทั้งหมด</Link>
                </div>
                <div class="table-wrap">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>ผู้ดำเนินงาน</th>
                                <th>รายการ</th>
                                <th>เวลา</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in recentAudit" :key="log.id">
                                <td>{{ log.user?.display_name || 'ไม่ระบุบัญชี' }}</td>
                                <td>{{ labels[log.action] || 'ดำเนินการจัดการระบบ' }}</td>
                                <td>{{ dateTimeLabel(log.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </AppLayout>
</template>
