<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

abstract class FoundationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'smh_learning_test') {
            throw new LogicException('Refusing to refresh a database other than smh_learning_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    protected function userWithRole(string $code): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function userData(?User $user = null): array
    {
        return ['employee_code' => $user?->employee_code ?? 'TEST-NEW', 'username' => $user?->username ?? '0000000000015', 'first_name' => 'บุคลากรจำลอง', 'last_name' => 'ทดสอบ', 'email' => null, 'phone' => null, 'department_id' => null, 'position_id' => null, 'employment_status' => 'ข้อมูลทดสอบ', 'is_active' => true, 'role_ids' => [Role::query()->where('code', 'learner')->firstOrFail()->id]];
    }
}
