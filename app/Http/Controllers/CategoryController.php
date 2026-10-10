<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Services\FoundationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $manage = $user->hasPermission('categories.manage');
        abort_unless($manage || $user->hasPermission('categories.view'), 403);
        $query = CourseCategory::query();
        if (! $manage) {
            $query->whereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
        }

        return Inertia::render('Categories/Index', ['categories' => $query->with('instructors:id,display_name')->orderBy('id')->paginate(15), 'canManage' => $manage]);
    }

    public function show(CourseCategory $category): Response
    {
        Gate::authorize('view', $category);

        return Inertia::render('Categories/Show', ['category' => $category->load('instructors:id,display_name'), 'courses' => Course::where('category_id', $category->id)->orderBy('title')->get(['id', 'title', 'status']), 'canManageCourses' => auth()->user() instanceof User && (auth()->user()->hasRole('administrator') || auth()->user()->supervises($category))]);
    }

    public function create(): Response
    {
        Gate::authorize('categories.manage');

        return $this->form();
    }

    public function edit(CourseCategory $category): Response
    {
        Gate::authorize('update', $category);

        return $this->form($category);
    }

    private function form(?CourseCategory $category = null): Response
    {
        $assignedIds = $category?->instructors()->pluck('users.id')->all() ?? [];

        return Inertia::render('Categories/Form', ['record' => $category?->load('instructors:id,display_name'), 'users' => User::query()->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $assignedIds))->orderBy('display_name')->get(['id', 'display_name', 'is_active'])]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CourseCategory $category = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('course_categories')->ignore($category?->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'instructor_ids' => ['present', 'array'],
            'instructor_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
    }

    public function store(Request $request, FoundationService $service): RedirectResponse
    {
        Gate::authorize('categories.manage');
        $service->saveCategory($request, $this->validated($request));

        return redirect()->route('categories.index')->with('message', 'เพิ่มหมวดหลักสูตรแล้ว');
    }

    public function update(Request $request, CourseCategory $category, FoundationService $service): RedirectResponse
    {
        Gate::authorize('update', $category);
        $service->saveCategory($request, $this->validated($request, $category), $category);

        return redirect()->route('categories.index')->with('message', 'บันทึกหมวดและผู้คุมแล้ว');
    }
}
