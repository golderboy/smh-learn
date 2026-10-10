<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_code' => fake()->unique()->bothify('TEST-########'),
            'username' => '000'.fake()->unique()->numerify('##########'),
            'password_hash' => 'smh123456',
            'first_name' => 'ผู้ใช้จำลอง',
            'last_name' => 'ทดสอบ',
            'display_name' => 'ผู้ใช้จำลอง ทดสอบ',
            'email' => null,
            'is_active' => true,
            'must_change_password' => false,
        ];
    }
}
