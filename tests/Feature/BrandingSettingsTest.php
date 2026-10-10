<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FoundationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

class BrandingSettingsTest extends FoundationTestCase
{
    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['organization_name' => 'โรงเรียนทดสอบ', 'session_timeout_minutes' => 60, 'product_name' => 'School Learning', 'header_title' => 'สวัสดีผู้เรียน', 'header_description' => 'เริ่มต้นเรียนรู้', 'banner_text' => 'เรียนรู้ร่วมกัน', 'login_heading' => 'ยินดีต้อนรับนักเรียน', 'footer_text' => 'โรงเรียนทดสอบ', 'support_email' => 'help@example.test', 'support_url' => 'https://example.test/help'];
    }

    private function image(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII='));
    }

    public function test_authorized_branding_changes_are_shared_and_do_not_store_unlisted_fields(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->put('/admin/settings', [...$this->settings(), 'exam_limit' => 99, 'THAID_CLIENT_SECRET' => 'must-not-store'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('app.name', 'School Learning')->where('app.organization', 'โรงเรียนทดสอบ')->where('app.branding.header_title', 'สวัสดีผู้เรียน')->where('app.branding.support_url', 'https://example.test/help'));
        $this->assertDatabaseMissing('system_settings', ['key' => 'exam_limit']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'THAID_CLIENT_SECRET']);
    }

    public function test_images_are_public_only_through_known_type_and_current_hash_and_can_reset(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWithRole('administrator'))->post('/admin/settings', [...$this->settings(), '_method' => 'put', 'logo_file' => $this->image(), 'favicon_file' => $this->image('icon.png')])->assertRedirect()->assertSessionHasNoErrors();
        $settings = app(FoundationSettings::class);
        $url = $settings->assetUrls()['logo_url'];
        $asset = $settings->asset('logo');
        Storage::disk('local')->assertExists($asset['path']);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/branding/logo/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/branding/secret/'.str_repeat('a', 64))->assertNotFound();
        $this->actingAs($this->userWithRole('administrator'))->put('/admin/settings', [...$this->settings(), 'remove_logo' => true])->assertRedirect();
        $this->assertNull(app(FoundationSettings::class)->assetUrls()['logo_url']);
        $this->get($url)->assertNotFound();
    }

    public function test_learner_cannot_upload_or_change_branding(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWithRole('learner'))->post('/admin/settings', [...$this->settings(), '_method' => 'put', 'logo_file' => $this->image()])->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles('branding'));
    }

    public function test_unmatched_route_error_uses_configured_public_branding(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->put('/admin/settings', $this->settings())->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/branding-test-missing-page')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404)->where('app.name', 'School Learning')->where('app.organization', 'โรงเรียนทดสอบ')->where('app.branding.footer_text', 'โรงเรียนทดสอบ')->where('app.branding.support_url', 'https://example.test/help'));
    }

    public function test_svg_disguised_image_and_unsafe_contact_url_are_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWithRole('administrator'))->post('/admin/settings', [...$this->settings(), '_method' => 'put', 'logo_file' => UploadedFile::fake()->createWithContent('bad.png', '<svg><script>alert(1)</script></svg>')])->assertSessionHasErrors('logo_file');
        $this->put('/admin/settings', [...$this->settings(), 'support_url' => 'javascript:alert(1)'])->assertSessionHasErrors('support_url');
        $this->assertSame([], Storage::disk('local')->allFiles('branding'));
    }

    public function test_failed_second_upload_removes_new_file_and_keeps_all_settings_unchanged(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWithRole('administrator'))->post('/admin/settings', [...$this->settings(), '_method' => 'put', 'logo_file' => $this->image(), 'banner_file' => UploadedFile::fake()->createWithContent('bad.png', '<?php echo 1;')])->assertSessionHasErrors('banner_file');
        $this->assertSame([], Storage::disk('local')->allFiles('branding'));
        $this->assertDatabaseMissing('system_settings', ['key' => 'product_name']);
    }
}
