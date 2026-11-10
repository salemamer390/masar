<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_guest_defaults_to_arabic_and_rtl(): void
    {
        $this->get('/owner/login')->assertOk()
            ->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_english_switch_persists_in_session_and_cookie(): void
    {
        $this->get('/locale/en')->assertRedirect()
            ->assertSessionHas('locale', 'en')->assertCookie('masar_locale', 'en');
        $this->get('/owner/login')->assertOk()
            ->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)
            ->assertSee('Log in');
    }

    public function test_arabic_switch_restores_rtl(): void
    {
        $this->withSession(['locale' => 'en'])->get('/locale/ar')
            ->assertRedirect()->assertSessionHas('locale', 'ar');
        $this->get('/owner/login')->assertOk()
            ->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_unknown_locale_is_rejected(): void
    {
        $this->get('/locale/fr')->assertNotFound();
    }
}
