<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PlatformCompanyAnnouncement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationCenterFlowsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_read_marking_is_user_scoped_and_read_all_only_touches_the_owner(): void
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'Center A', 'slug' => 'center-a', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'M', 'company_id' => $company->id]);
        $owner = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $other = User::create(['name' => 'T', 'email' => 't@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);

        foreach (range(1, 8) as $i) {
            $owner->notify(new PlatformCompanyAnnouncement("إعلان رقم {$i}"));
        }
        $other->notify(new PlatformCompanyAnnouncement('إشعار المستخدم الآخر'));

        $target = $owner->notifications()->latest()->first();

        // Another user cannot mark or traverse someone else's notification.
        $this->actingAs($other);
        $this->post("/notifications/{$target->id}/read")->assertNotFound();
        $this->assertNull($target->fresh()->read_at);

        // The owner follows the item: marked read and redirected to its internal link.
        $this->actingAs($owner);
        $this->post("/notifications/{$target->id}/read")
            ->assertRedirect(route('company.account'));
        $this->assertNotNull($target->fresh()->read_at);

        // The topbar shows the real unread count, not the dropdown page size.
        $this->get('/notifications')->assertOk()->assertSee('7 جديدة', false)->assertSee('تعيين الكل كمقروءة')->assertSee('notification-scroll')->assertSee('عرض كل الإشعارات');

        $this->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }
}
