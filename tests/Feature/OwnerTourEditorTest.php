<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Permission, Role, SubscriptionPlan, TourStep, User};
use App\Support\GuidedTour;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OwnerTourEditorTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        TourStep::query()->delete();
        foreach (GuidedTour::defaults() as $i => $step) {
            TourStep::create([
                'key' => $step['key'], 'route_name' => $step['route'] ?? null,
                'target' => $step['target'] ?? [], 'permission' => $step['permission'] ?? null,
                'icon' => $step['icon'] ?? null, 'title_ar' => $step['title'], 'body_ar' => $step['body'],
                'sort_order' => $i, 'is_active' => true, 'is_draft' => false,
                'is_system' => in_array($step['key'], ['welcome', 'closing']),
            ]);
        }
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'tour-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'TourOwnerCo', 'slug' => 'tour-owner-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
    }

    private function owner(): User
    {
        config(['owner.emails' => ['owner@example.test']]);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'company_id' => null], ['display_name' => 'Super Admin']);

        return User::create(['name' => 'Platform Owner', 'email' => 'owner@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => null, 'is_active' => true]);
    }

    private function companyUser(array $permissionNames): User
    {
        $role = Role::create(['name' => 'hr_'.uniqid(), 'display_name' => 'HR', 'company_id' => $this->company->id]);
        if ($permissionNames) {
            $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));
        }

        return User::create(['name' => 'HR User', 'email' => 'hr_'.uniqid().'@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
    }

    private function fullPermissionUser(): User
    {
        return $this->companyUser([
            'payroll.settings', 'employees.view', 'salary.view', 'leaves.view',
            'penalties.view', 'requests.view', 'fingerprint-devices.view',
            'payroll.view', 'dashboard.view',
        ]);
    }

    public function test_editor_is_owner_only(): void
    {
        $this->get('/owner/tour')->assertRedirectToRoute('owner.login');

        $this->actingAs($this->companyUser(['dashboard.view']));
        $this->get('/owner/tour')->assertNotFound();

        $this->actingAs($this->owner(), 'owner');
        $this->get('/owner/tour')->assertOk()->assertSee('إدارة الجولة التعريفية', false);
    }

    public function test_company_linked_owner_can_edit_and_publish_but_unlisted_super_admin_cannot(): void
    {
        $owner = $this->owner();
        $owner->update(['company_id' => $this->company->id]);
        $this->actingAs($owner, 'owner');
        $this->get('/owner/tour')->assertOk();
        $this->post(route('owner.tour.draft.start'))->assertRedirect();
        $this->post(route('owner.tour.publish'))->assertRedirect();
        $owner->update(['email' => 'not-allowlisted@example.test']);
        $this->get('/owner/tour')->assertNotFound();
        $this->post(route('owner.tour.draft.start'))->assertNotFound();
        $this->post(route('owner.tour.publish'))->assertNotFound();
    }

    public function test_steps_fall_back_to_builtin_tour_when_table_is_empty(): void
    {
        TourStep::query()->delete();

        $keys = array_column(GuidedTour::stepsFor($this->fullPermissionUser()), 'key');

        $this->assertSame([
            'welcome', 'settings', 'employees', 'salary-structures', 'leaves',
            'penalties', 'requests', 'fingerprint', 'monthly-summary', 'payroll',
            'dashboard', 'closing',
        ], $keys);
    }

    public function test_seeded_steps_drive_the_tour_and_respect_activity_and_permissions(): void
    {
        $user = $this->fullPermissionUser();
        $this->assertCount(12, GuidedTour::stepsFor($user));

        $limited = $this->companyUser(['leaves.view']);
        $this->assertSame(['welcome', 'leaves', 'closing'], array_column(GuidedTour::stepsFor($limited), 'key'));

        TourStep::live()->where('key', 'leaves')->update(['is_active' => false]);
        $keys = array_column(GuidedTour::stepsFor($user), 'key');
        $this->assertNotContains('leaves', $keys);
        $this->assertCount(11, $keys);
    }

    public function test_draft_edit_publish_flow_changes_only_after_publish(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        $this->post(route('owner.tour.draft.start'))->assertRedirect(route('owner.tour.index'));
        $this->assertSame(12, TourStep::draft()->count());

        $draft = TourStep::draft()->where('key', 'settings')->first();
        $this->put(route('owner.tour.steps.update', $draft), [
            'title_ar' => 'إعدادات النظام المعدلة',
            'body_ar' => 'شرح جديد لخطوة الإعدادات.',
            'title_en' => 'Adjusted settings',
            'body_en' => 'New settings body.',
            'icon' => 'bi-gear-fill',
        ])->assertSessionHasNoErrors();

        // Users still see the published wording until the draft is published.
        $user = $this->fullPermissionUser();
        $steps = collect(GuidedTour::stepsFor($user))->keyBy('key');
        $this->assertSame('الإعدادات', $steps['settings']['title']);

        $this->post(route('owner.tour.publish'))->assertRedirect(route('owner.tour.index'));
        $this->assertSame(0, TourStep::draft()->count());

        $steps = collect(GuidedTour::stepsFor($user))->keyBy('key');
        $this->assertSame('إعدادات النظام المعدلة', $steps['settings']['title']);

        app()->setLocale('en');
        $steps = collect(GuidedTour::stepsFor($user))->keyBy('key');
        $this->assertSame('Adjusted settings', $steps['settings']['title']);
        app()->setLocale('ar');
    }

    public function test_discard_draft_keeps_the_published_tour(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));
        TourStep::draft()->where('key', 'employees')->first()->delete();
        $this->assertSame(11, TourStep::draft()->count());

        $this->delete(route('owner.tour.draft.discard'))->assertRedirect(route('owner.tour.index'));

        $this->assertSame(0, TourStep::draft()->count());
        $this->assertSame(12, TourStep::live()->count());
    }

    public function test_system_steps_cannot_be_deactivated_or_deleted(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));

        $welcome = TourStep::draft()->where('key', 'welcome')->first();

        $this->post(route('owner.tour.steps.toggle', $welcome));
        $this->assertTrue($welcome->fresh()->is_active);

        $this->delete(route('owner.tour.steps.destroy', $welcome));
        $this->assertNotNull($welcome->fresh());
    }

    public function test_reordering_swaps_draft_steps(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));

        $settings = TourStep::draft()->where('key', 'settings')->first();
        $employees = TourStep::draft()->where('key', 'employees')->first();

        $this->post(route('owner.tour.steps.move', $employees), ['direction' => 'up']);

        $this->assertSame($settings->sort_order, $employees->fresh()->sort_order);
        $this->assertSame($employees->sort_order, $settings->fresh()->sort_order);
    }

    public function test_publish_reshows_the_tour_only_when_requested(): void
    {
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_seen_at' => now(), 'tour_step' => 'settings'])->save();

        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));
        $this->post(route('owner.tour.publish'));
        $this->assertNotNull($user->fresh()->tour_seen_at);
        $this->assertSame('settings', $user->fresh()->tour_step);

        $this->post(route('owner.tour.draft.start'));
        $this->post(route('owner.tour.publish'), ['reshow' => '1']);
        $this->assertNull($user->fresh()->tour_seen_at);
        $this->assertNull($user->fresh()->tour_step);
    }

    public function test_manual_step_adds_fallback_target_and_joins_the_tour_after_publish(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));

        $this->post(route('owner.tour.steps.store'), [
            'route_name' => 'attendance.index',
            'selector' => '#attendanceExportButton',
            'title_ar' => 'الحضور والانصراف',
            'body_ar' => 'من هنا تتابع سجلات الحضور اليومية.',
            'permission' => 'attendance.view',
        ])->assertSessionHasNoErrors();

        $step = TourStep::draft()->where('route_name', 'attendance.index')->first();
        $this->assertSame(['#attendanceExportButton', '.page-head'], $step->target);
        $this->assertSame('bi-hand-index', $step->icon);

        $this->post(route('owner.tour.publish'));

        $hrUser = $this->companyUser(['attendance.view']);
        $keys = array_column(GuidedTour::stepsFor($hrUser), 'key');
        $this->assertSame(['welcome', 'attendance-index', 'closing'], $keys);
    }

    public function test_manual_step_rejects_unknown_routes(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->post(route('owner.tour.draft.start'));

        $this->from(route('owner.tour.index'))->post(route('owner.tour.steps.store'), [
            'route_name' => 'not.a.route',
            'selector' => '#x',
            'title_ar' => 'عنوان',
            'body_ar' => 'شرح',
        ])->assertSessionHasErrors('route_name');

        $this->assertSame(12, TourStep::draft()->count());
    }

    public function test_picker_saves_a_draft_step_for_the_current_page(): void
    {
        $this->actingAs($this->owner(), 'owner');

        $this->postJson(route('owner.tour.steps.picked'), [
            'route_name' => 'employees.index',
            'selector' => '[data-bs-target="#addEmployeeModal"]',
            'title_ar' => 'زر إضافة موظف',
            'body_ar' => 'اضغط هنا لإضافة موظف جديد.',
        ])->assertOk()->assertJson(['ok' => true]);

        // The draft set is created on demand around the picked step.
        $this->assertSame(13, TourStep::draft()->count());
        $step = TourStep::draft()->where('route_name', 'employees.index')->where('key', 'employees-index')->first();
        $this->assertNotNull($step);
        $this->assertSame(['[data-bs-target="#addEmployeeModal"]', '.page-head'], $step->target);
    }

    public function test_picker_rejects_non_owners(): void
    {
        $this->actingAs($this->companyUser(['employees.view']));

        $this->postJson(route('owner.tour.steps.picked'), [
            'route_name' => 'employees.index',
            'selector' => '#x',
            'title_ar' => 'عنوان',
            'body_ar' => 'شرح',
        ])->assertNotFound();
    }

    public function test_preview_shows_the_draft_without_touching_user_progress(): void
    {
        $owner = $this->owner();
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_seen_at' => now()])->save();

        $this->actingAs($owner, 'owner');
        $this->post(route('owner.tour.draft.start'));
        $draft = TourStep::draft()->where('key', 'settings')->first();
        $draft->forceFill(['title_ar' => 'عنوان معاينة مؤقت'])->save();

        $this->actingAs($user);
        $this->actingAs($owner, 'owner');

        $html = $this->get('/dashboard?tour_preview=1')->assertOk()->getContent();

        $this->assertStringContainsString('preview: true', $html);
        $this->assertStringContainsString('active: true', $html);
        $this->assertStringContainsString('عنوان معاينة مؤقت', $html);
        $this->assertNotNull($user->fresh()->tour_seen_at);
    }

    public function test_edit_mode_loads_the_picker_for_the_owner_only(): void
    {
        $owner = $this->owner();
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_seen_at' => now()])->save();

        $this->actingAs($user);
        $this->actingAs($owner, 'owner');

        $html = $this->get('/dashboard?tour_edit=1')->assertOk()->getContent();
        $this->assertStringContainsString('window.masarTourEditor', $html);
        $this->assertStringContainsString('js/tour-edit.js', $html);

        auth()->guard('owner')->logout();
        $this->actingAs($user);

        $html = $this->get('/dashboard?tour_edit=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('window.masarTourEditor', $html);
    }

    public function test_live_steps_are_not_editable_directly(): void
    {
        $this->actingAs($this->owner(), 'owner');

        $live = TourStep::live()->where('key', 'settings')->first();
        $this->put(route('owner.tour.steps.update', $live), [
            'title_ar' => 'محاولة تعديل المنشور',
            'body_ar' => 'شرح',
        ])->assertNotFound();

        $this->assertSame('الإعدادات', $live->fresh()->title_ar);
    }

    public function test_owner_dashboard_links_to_the_tour_page(): void
    {
        $this->actingAs($this->owner(), 'owner');
        $this->get('/owner')->assertOk()->assertSee(route('owner.tour.index'), false);
    }
}
