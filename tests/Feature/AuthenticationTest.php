<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

class AuthenticationTest extends FoundationTestCase
{
    public function test_login_preserves_leading_zeroes_and_uses_password_hash(): void
    {
        $user = $this->userWithRole('learner');
        $this->assertTrue(Hash::check('smh123456', $user->password_hash));
        $this->post('/login', ['username' => $user->username, 'password' => 'smh123456'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function test_username_validation_rejects_letters_and_more_than_fifteen_digits(): void
    {
        foreach (['123ABC', '1234567890123456', '１２３', '-123'] as $username) {
            $this->post('/login', ['username' => $username, 'password' => 'smh123456'])->assertSessionHasErrors('username');
            $this->assertGuest();
        }
    }

    public function test_wrong_password_and_inactive_user_are_rejected(): void
    {
        $user = $this->userWithRole('learner');
        $this->post('/login', ['username' => $user->username, 'password' => 'incorrect'])->assertSessionHasErrors('username');
        $user->update(['is_active' => false]);
        $this->post('/login', ['username' => $user->username, 'password' => 'smh123456'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_login_rate_limit_blocks_repeated_attempts(): void
    {
        $user = $this->userWithRole('learner');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'incorrect'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'smh123456'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_initial_password_change_is_required_before_dashboard(): void
    {
        $user = $this->userWithRole('learner');
        $user->update(['must_change_password' => true]);
        $this->post('/login', ['username' => $user->username, 'password' => 'smh123456'])->assertRedirect('/password/change');
        $this->get('/dashboard')->assertRedirect('/password/change');
        $this->put('/password/change', ['current_password' => 'incorrect', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertSessionHasErrors('current_password');
        $this->put('/password/change', ['current_password' => 'smh123456', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password_hash));
        $this->get('/dashboard')->assertOk();
    }

    public function test_logout_invalidates_authentication(): void
    {
        $user = $this->userWithRole('learner');
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_session_is_revoked_after_version_change_or_inactivity(): void
    {
        $user = $this->userWithRole('learner');
        $this->actingAs($user)->withSession(['auth_version' => 1])->get('/dashboard')->assertOk();
        $user->increment('session_version');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $user->update(['is_active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_idle_timeout_uses_organization_setting(): void
    {
        SystemSetting::query()->where('key', 'session_timeout_minutes')->update(['value' => json_encode(['value' => 15])]);
        $this->actingAs($this->userWithRole('learner'))->withSession(['auth_last_activity' => time() - 901])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_thaid_is_disabled_and_untrusted_callback_cannot_sign_in(): void
    {
        config(['identity.thaid.enabled' => true]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('thaidAvailable', false));
        $this->get('/auth/thaid/callback?pid=0000000000001&code=fake')->assertNotFound();
        $this->assertGuest();
    }

    public function test_session_cookie_lifetime_matches_a_longer_organization_timeout(): void
    {
        SystemSetting::query()->where('key', 'session_timeout_minutes')->update(['value' => json_encode(['value' => 240])]);
        $response = $this->actingAs($this->userWithRole('learner'))->get('/dashboard')->assertOk();
        $cookie = $response->getCookie((string) config('session.cookie'), false);
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(time() + 240 * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_profile_does_not_accept_identity_role_or_active_status_changes(): void
    {
        $user = $this->userWithRole('learner');
        $this->actingAs($user)->put('/profile', ['email' => 'synthetic@example.test', 'phone' => '0000000000', 'username' => '999', 'is_active' => false, 'first_name' => 'เปลี่ยนชื่อ', 'role_ids' => [1]])->assertRedirect();
        $this->assertSame($user->username, $user->fresh()->username);
        $this->assertSame($user->first_name, $user->fresh()->first_name);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertFalse($user->hasRole('administrator'));
    }

    public function test_password_and_citizen_username_never_appear_in_audit_payload(): void
    {
        $admin = $this->userWithRole('administrator');
        $payload = $this->userData();
        $this->actingAs($admin)->post('/admin/users', $payload)->assertRedirect('/admin/users');
        $serialized = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('smh123456', $serialized);
        $this->assertStringNotContainsString($payload['username'], $serialized);
        $this->assertStringContainsString('[REDACTED]', $serialized);
    }
}
