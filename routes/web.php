<?php

declare(strict_types=1);

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\MasterImportController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\QuestionImportController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\ThaiDController;
use App\Http\Controllers\WelfareController;
use App\Services\InfrastructureStatus;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::get('/branding/{asset}/{hash}', [BrandingController::class, 'show'])->where('asset', 'logo|banner|favicon')->where('hash', '[a-f0-9]{64}');
Route::middleware('guest')->group(function (): void {
    Route::get('/auth/thaid', [ThaiDController::class, 'redirect'])->name('thaid.redirect');
    Route::get('/auth/thaid/callback', [ThaiDController::class, 'callback'])->name('thaid.callback');
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::middleware(['auth', 'active.session'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [AuthController::class, 'editPassword'])->name('password.edit');
    Route::put('/password/change', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::middleware('password.changed')->group(function (): void {
        Route::get('/admin/imports', [MasterImportController::class, 'index']);
        Route::get('/admin/imports/{kind}/template', [MasterImportController::class, 'template']);
        Route::post('/admin/imports/{kind}/preview', [MasterImportController::class, 'preview'])->middleware('throttle:master-import-preview');
        Route::post('/admin/imports/{kind}/commit', [MasterImportController::class, 'commit'])->middleware('throttle:master-import-commit');
        Route::get('/coupons', [WelfareController::class, 'catalog']);
        Route::post('/coupons/rewards/{reward}/exchange', [WelfareController::class, 'issue'])->middleware('throttle:20,1');
        Route::get('/coupons/{coupon:coupon_id}', [WelfareController::class, 'card']);
        Route::get('/admin/welfare', [WelfareController::class, 'manage']);
        Route::post('/admin/welfare/stores', [WelfareController::class, 'createStore']);
        Route::put('/admin/welfare/stores/{store}', [WelfareController::class, 'updateStore']);
        Route::put('/admin/welfare/stores/{store}/status', [WelfareController::class, 'storeStatus']);
        Route::post('/admin/welfare/rewards', [WelfareController::class, 'createReward']);
        Route::put('/admin/welfare/rewards/{reward}', [WelfareController::class, 'updateReward']);
        Route::post('/admin/welfare/rewards/{reward}/stock', [WelfareController::class, 'stock']);
        Route::get('/welfare/store', [WelfareController::class, 'merchant']);
        Route::post('/welfare/coupons/check', [WelfareController::class, 'preview'])->middleware('throttle:30,1');
        Route::post('/welfare/coupons/receive', [WelfareController::class, 'receive'])->middleware('throttle:30,1');
        Route::post('/welfare/claims', [WelfareController::class, 'createClaim']);
        Route::get('/welfare/claims/{claim:claim_id}', [WelfareController::class, 'showClaim']);
        Route::get('/welfare/claims/{claim:claim_id}/{format}', [WelfareController::class, 'claimFile'])->where('format', 'pdf|csv');
        Route::get('/reports', [ReportingController::class, 'index'])->name('reports.index');
        Route::post('/reports/exports', [ReportingController::class, 'export']);
        Route::get('/reports/exports/{export:token}/download', [ReportingController::class, 'download']);
        Route::get('/points', [PointController::class, 'index']);
        Route::get('/admin/points', [PointController::class, 'manage']);
        Route::put('/admin/points/rules', [PointController::class, 'rules']);
        Route::post('/admin/points/users/{user}/adjust', [PointController::class, 'adjust']);
        Route::put('/sessions/{session}/points', [PointController::class, 'session']);
        Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
        Route::get('/manage/courses', [CourseController::class, 'index'])->name('courses.manage');
        Route::post('/manage/courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/manage/courses/{course}/edit', [CourseController::class, 'edit'])->name('courses.edit');
        Route::put('/manage/courses/{course}', [CourseController::class, 'update']);
        Route::post('/versions/{version}/clone', [CourseController::class, 'version']);
        Route::post('/versions/{version}/publish', [CourseController::class, 'publish']);
        Route::post('/versions/{version}/modules', [CourseController::class, 'module']);
        Route::post('/modules/{module}/lessons', [CourseController::class, 'lesson']);
        Route::post('/lessons/{lesson}/contents', [CourseController::class, 'content']);
        Route::delete('/lessons/{lesson}', [CourseController::class, 'removeLesson']);
        Route::delete('/contents/{content}', [CourseController::class, 'removeContent']);
        Route::post('/versions/{version}/sessions', [CourseController::class, 'session']);
        Route::put('/sessions/{session}/status', [CourseController::class, 'sessionStatus']);
        Route::post('/sessions/{session}/assign', [CourseController::class, 'assign']);
        Route::post('/sessions/{session}/enroll', [CourseController::class, 'enroll']);
        Route::post('/learning/{enrollment}/cancel', [CourseController::class, 'cancelEnrollment']);
        Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');
        Route::get('/learning', [LearningController::class, 'index'])->name('learning.index');
        Route::get('/learning/{enrollment}', [LearningController::class, 'show'])->name('learning.show');
        Route::post('/learning/{enrollment}/exams/start', [AssessmentController::class, 'start']);
        Route::get('/attempts/{attempt}', [AssessmentController::class, 'show'])->name('assessment.show');
        Route::post('/attempts/{attempt}/answers', [AssessmentController::class, 'save']);
        Route::post('/attempts/{attempt}/submit', [AssessmentController::class, 'submit']);
        Route::put('/sessions/{session}/assessment', [AssessmentSettingsController::class, 'session']);
        Route::put('/exams/{exam}/settings', [AssessmentSettingsController::class, 'exam']);
        Route::put('/lessons/{lesson}/documents', [AssessmentSettingsController::class, 'documents']);
        Route::get('/media/{content}', [LearningController::class, 'file'])->name('learning.file');
        Route::post('/learning/{enrollment}/contents/{content}/open', [LearningController::class, 'open']);
        Route::post('/learning/{enrollment}/contents/{content}/confirm', [LearningController::class, 'confirm']);
        Route::post('/learning/{enrollment}/contents/{content}/heartbeat', [LearningController::class, 'heartbeat']);
        Route::get('/versions/{version}/questions', [QuestionImportController::class, 'index'])->name('questions.index');
        Route::get('/versions/{version}/questions/template', [QuestionImportController::class, 'template']);
        Route::post('/versions/{version}/questions/preview', [QuestionImportController::class, 'preview']);
        Route::post('/versions/{version}/questions/commit', [QuestionImportController::class, 'commit']);
        Route::delete('/versions/{version}/questions', [QuestionImportController::class, 'clear']);
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
        Route::get('/department', [DashboardController::class, 'department'])->name('department');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::get('/admin/users/create', [AdminUserController::class, 'create'])->name('admin.users.create');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::get('/admin/users/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::post('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('admin.users.reset-password');
        Route::get('/admin/organization/{kind}', [OrganizationController::class, 'index'])->whereIn('kind', ['departments', 'positions'])->name('admin.organization.index');
        Route::post('/admin/organization/{kind}', [OrganizationController::class, 'store'])->whereIn('kind', ['departments', 'positions']);
        Route::put('/admin/organization/{kind}/{record}', [OrganizationController::class, 'update'])->whereIn('kind', ['departments', 'positions'])->whereNumber('record');
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
        Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::get('/admin/roles', [SystemController::class, 'roles'])->name('admin.roles');
        Route::post('/admin/roles', [SystemController::class, 'storeRole']);
        Route::put('/admin/roles/{role}', [SystemController::class, 'updateRole']);
        Route::get('/admin/settings', [SystemController::class, 'settings'])->name('admin.settings');
        Route::put('/admin/settings', [SystemController::class, 'updateSettings']);
        Route::get('/admin/audit', [SystemController::class, 'audit'])->name('admin.audit');
    });
});

// Temporary environment diagnostics are registered only for DEV/tests.
if (app()->environment(['local', 'testing'])) {
    Route::get('/dev', fn () => Inertia::render('DevHome', [
        'laravelVersion' => app()->version(),
        'phpVersion' => PHP_VERSION,
    ]))->name('dev.home');

    Route::get('/dev/status', function (InfrastructureStatus $status) {
        $result = $status->check();

        return response()->json($result, $result['ok'] ? 200 : 503);
    })->name('dev.status');
}
