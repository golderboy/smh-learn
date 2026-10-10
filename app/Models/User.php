<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\PermissionCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** @property string|null $remember_token */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['employee_code', 'username', 'password_hash', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'department_id', 'position_id', 'employment_status', 'is_active', 'is_demo', 'must_change_password', 'session_version', 'last_login_at'];

    protected $hidden = ['password_hash', 'remember_token', 'session_version', 'root_admin_key'];

    protected $appends = ['is_root_admin'];

    public function isRootAdministrator(): bool
    {
        return $this->root_admin_key === 'PRIMARY';
    }

    public function getIsRootAdminAttribute(): bool
    {
        return $this->isRootAdministrator();
    }

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->getOriginal('root_admin_key') === 'PRIMARY' && $user->isDirty(['root_admin_key', 'employee_code', 'username', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'department_id', 'position_id', 'employment_status', 'is_active', 'is_demo'])) {
                throw new \LogicException('Protected administrator identity cannot be edited.');
            }
        });
        static::deleting(function (User $user): void {
            if ($user->isRootAdministrator()) {
                throw new \LogicException('Protected administrator cannot be deleted.');
            }
        });
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['username' => 'string', 'password_hash' => 'hashed', 'is_active' => 'boolean', 'is_demo' => 'boolean', 'must_change_password' => 'boolean', 'session_version' => 'integer', 'last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return BelongsToMany<CourseCategory, $this> */
    public function supervisedCategories(): BelongsToMany
    {
        return $this->belongsToMany(CourseCategory::class, 'course_category_instructors')->withPivot('assigned_by')->withTimestamps();
    }

    public function hasRole(string $code): bool
    {
        return $this->roles()->where('code', $code)->exists();
    }

    public function hasPermission(string $code): bool
    {
        return $this->is_active && (($this->isRootAdministrator() && array_key_exists($code, PermissionCatalog::all())) || $this->roles()->whereHas('permissions', fn ($query) => $query->where('code', $code))->exists());
    }

    public function supervises(CourseCategory $category): bool
    {
        return $this->hasPermission('courses.manage.assigned') && $this->supervisedCategories()->whereKey($category->id)->exists();
    }
}
