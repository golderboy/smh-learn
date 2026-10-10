<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;

class UiErrorPageTest extends FoundationTestCase
{
    public function test_missing_html_page_has_safe_recovery_without_changing_status(): void
    {
        $this->actingAs($this->userWithRole('learner'))->get('/ui-qa-missing-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_json_errors_keep_the_api_contract_and_scoped_page_stays_forbidden(): void
    {
        $this->actingAs($this->userWithRole('learner'))->get('/admin/users')
            ->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
        $this->getJson('/ui-qa-missing-page')->assertNotFound()->assertJsonStructure(['message']);
    }
}
