<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class MarketingBladeTest extends TestCase
{
    private const PAGES = [
        '/' => 'index', '/index.html' => 'index', '/features.html' => 'features',
        '/privacy.html' => 'privacy', '/terms.html' => 'terms', '/login' => 'login',
        '/signup.html' => 'signup', '/contact.html' => 'contact',
        '/forgot-password.html' => 'forgot', '/reset-password/test-token' => 'reset',
    ];

    public function test_all_marketing_pages_render_blade_in_both_languages(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach (self::PAGES as $url => $view) {
                $response = $this->withSession(['locale' => $locale])->get($url);
                $response->assertOk()->assertViewIs('marketing.'.$view)
                    ->assertSee('lang="'.$locale.'"', false)
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertDontSee('how-it-works.html', false)
                    ->assertDontSee('pricing.html', false)
                    ->assertDontSee('class="note"', false);
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        }
    }

    public function test_native_forms_keep_their_actions_csrf_and_field_names(): void
    {
        foreach (['/login' => 'login.attempt', '/signup.html' => 'register.company',
            '/contact.html' => 'site.contact.submit', '/forgot-password.html' => 'password.email',
            '/reset-password/test-token' => 'password.update'] as $url => $action) {
            $this->get($url)->assertOk()->assertSee('method="POST"', false)
                ->assertSee('action="'.route($action).'"', false)
                ->assertSee('name="_token"', false)->assertSee('name="email"', false);
        }
        $this->get('/signup.html')->assertSee('name="password_confirmation"', false)
            ->assertSee('name="company_name"', false)->assertSee('name="terms_accepted"', false);
        $this->get('/reset-password/test-token')->assertViewHas('token', 'test-token')
            ->assertSee('name="token" value="test-token"', false)
            ->assertSee('name="password_confirmation"', false);
        $this->get('/login.html')->assertRedirect(route('login'));
    }

    public function test_errors_and_success_messages_are_escaped(): void
    {
        $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => '<script>bad()</script>']))])
            ->get('/login')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)
            ->assertDontSee('<script>bad()</script>', false);
        session()->flush();
        $this->withSession(['errors' => new \Illuminate\Support\ViewErrorBag, 'site_success' => '<b>sent</b>'])->get('/contact.html')
            ->assertSee('&lt;b&gt;sent&lt;/b&gt;', false)->assertDontSee('<b>sent</b>', false);
    }

    public function test_authenticated_visitors_keep_the_dashboard_redirects(): void
    {
        $this->actingAs(new User(['id' => 999999, 'is_active' => true]));
        foreach (array_keys(self::PAGES) as $url) {
            if ($url !== '/contact.html') $this->get($url)->assertRedirect(route('dashboard'));
        }
    }

    public function test_marketing_runtime_no_longer_reads_html_templates(): void
    {
        foreach (['routes/web.php', 'app/Http/Controllers/MarketingSiteController.php'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertStringNotContainsString('masar_hr_multi_page_site', $source);
            $this->assertStringNotContainsString('MarketingLocalizer', $source);
        }
    }
}
