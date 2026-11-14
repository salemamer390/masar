<?php

namespace Tests\Feature;

use Tests\TestCase;

class PasswordVisibilityTest extends TestCase
{
    public function test_all_guest_password_forms_load_the_shared_toggle_without_changing_inputs(): void
    {
        foreach (['/login', '/owner/login', '/signup.html', '/reset-password/test-token'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('password-visibility.js', false);
            $response->assertSee('type="password"', false)->assertSee('name="password"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'password-visibility.js'));
        }
    }

    public function test_authenticated_layouts_load_the_same_dynamic_field_support(): void
    {
        foreach (['app', 'owner', 'platform'] as $layout) {
            $this->assertStringContainsString('password-visibility.js', file_get_contents(resource_path('views/layouts/'.$layout.'.blade.php')));
        }
        $script=file_get_contents(public_path('password-visibility.js'));
        $this->assertStringContainsString('MutationObserver', $script);
        $this->assertStringContainsString("button.type = 'button'", $script);
        $this->assertStringContainsString('aria-pressed', $script);
        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertStringNotContainsString('localStorage', $script);
    }
}
