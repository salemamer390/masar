<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The language switcher sits beside the dark-mode toggle, and its menu must
 * never render behind the fixed sidebar (sidebar z-index 20 vs topbar 10).
 */
class TopbarLanguageMenuTest extends TestCase
{
    use DatabaseTransactions;

    public function test_language_switcher_sits_next_to_theme_toggle_in_both_locales(): void
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'topbar-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'TopbarCo', 'slug' => 'topbar-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'topbar@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($user);

        foreach (['ar', 'en'] as $locale) {
            $html = $this->withSession(['locale' => $locale])->get(route('dashboard'))->assertOk()->getContent();

            $translate = strpos($html, 'bi-translate');
            $theme = strpos($html, 'id="themeToggleBtn"');
            $this->assertNotFalse($translate, "language switcher missing ($locale)");
            $this->assertNotFalse($theme, "theme toggle missing ($locale)");
            // The switcher comes immediately before the theme toggle: only its own
            // dropdown markup sits between the two buttons, no other topbar control.
            $this->assertLessThan($theme, $translate, "language switcher must precede the theme toggle ($locale)");
            $between = substr($html, $translate, $theme - $translate);
            $this->assertStringNotContainsString('user-menu', $between, "user menu still separates the switcher from the theme toggle ($locale)");
            $this->assertStringNotContainsString('notification-trigger', $between, "notifications still separate the switcher from the theme toggle ($locale)");
            $this->assertStringContainsString('language-menu', $between, "language menu marker class missing ($locale)");
        }
    }

    public function test_styles_keep_language_menu_above_the_fixed_sidebar(): void
    {
        $shared = file_get_contents(resource_path('styles/02-components.css'));
        $this->assertStringContainsString('.topbar:has(.language-menu.show)', $shared);

        // The built stylesheet must carry the same rule (run scripts/build-styles.php).
        $built = file_get_contents(public_path('app.css'));
        $this->assertStringContainsString('.topbar:has(.language-menu.show)', $built);
    }
}
