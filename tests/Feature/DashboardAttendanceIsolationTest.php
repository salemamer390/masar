<?php
namespace Tests\Feature;
use App\Models\{Attendance,Company,CompanySubscription,Employee,EmployeeStatus,Role,SubscriptionPlan,User};
use App\Services\DashboardDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DashboardAttendanceIsolationTest extends TestCase
{
    use DatabaseTransactions;
    protected function tearDown(): void { Carbon::setTestNow();parent::tearDown(); }
    public function test_another_company_holiday_cannot_empty_attendance_chart_or_absent_late_details(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 18:00:00','Africa/Cairo'));
        $plan=SubscriptionPlan::firstOrCreate(['slug'=>'dashboard-isolation'],['name'=>'Test','employee_limit'=>10000,'features'=>[]]);
        $a=Company::create(['slug'=>'dash-a','name'=>'A','status'=>'active']);
        $b=Company::create(['slug'=>'dash-b','name'=>'B','status'=>'active']);
        CompanySubscription::create(['company_id'=>$a->id,'subscription_plan_id'=>$plan->id,'status'=>'active','starts_at'=>today()]);
        $role=Role::create(['company_id'=>$a->id,'name'=>'company_manager','display_name'=>'Manager']);
        $user=User::create(['name'=>'Manager','email'=>'dash-a@example.test','password'=>bcrypt('test-only'),'company_id'=>$a->id,'role_id'=>$role->id,'is_active'=>true]);
        $this->actingAs($user);
        $status=EmployeeStatus::firstOrCreate(['name'=>'Active']);
        $absent=Employee::create(['employee_code'=>'DASH-ABSENT','full_name'=>'Absent employee','first_name'=>'Absent','company_id'=>$a->id,'status_id'=>$status->id,'hire_date'=>'2025-01-01']);
        $late=Employee::create(['employee_code'=>'DASH-LATE','full_name'=>'Late employee','first_name'=>'Late','company_id'=>$a->id,'status_id'=>$status->id,'hire_date'=>'2025-01-01']);
        Attendance::create(['employee_id'=>$late->id,'attendance_date'=>'2026-11-09','status'=>'Late','check_in'=>'09:30','late_minutes'=>30]);
        DB::table('hr_holidays')->insert(['company_id'=>$b->id,'holiday_date'=>'2026-11-09','name'=>'Other company only','created_at'=>now(),'updated_at'=>now()]);
        $data=app(DashboardDataService::class)->attendance($user);
        $this->assertFalse($data['holiday']);
        $this->assertSame(1,$data['counts']['absent']);
        $this->assertSame(1,$data['counts']['late']);
        $this->assertSame(2,$data['counts']['scheduled']);
        $this->get(route('dashboard.details','absent'))->assertOk()->assertSee('Absent employee')->assertDontSee('Late employee');
        $this->get(route('dashboard.details','late'))->assertOk()->assertSee('Late employee')->assertDontSee('Absent employee');
        $this->get(route('dashboard'))->assertOk()->assertSee('--absent:50%',false)->assertSee('--late:50%',false);
    }
    public function test_dashboard_details_have_scroll_loading_instead_of_page_size_controls(): void
    {
        $view=file_get_contents(resource_path('views/dashboard/partials/details.blade.php'));
        $this->assertStringNotContainsString('x-pagination-controls',$view);
        $this->assertStringContainsString('data-dashboard-next',$view);
        $js=file_get_contents(public_path('dashboard-details.js'));
        $this->assertStringContainsString('IntersectionObserver',$js);
    }
    public function test_new_employees_pagination_is_deterministic_under_equal_hire_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 18:00:00','Africa/Cairo'));
        $plan=SubscriptionPlan::firstOrCreate(['slug'=>'dashboard-isolation'],['name'=>'Test','employee_limit'=>10000,'features'=>[]]);
        $a=Company::create(['slug'=>'dash-page','name'=>'Paging','status'=>'active']);
        CompanySubscription::create(['company_id'=>$a->id,'subscription_plan_id'=>$plan->id,'status'=>'active','starts_at'=>today()]);
        $role=Role::create(['company_id'=>$a->id,'name'=>'company_manager','display_name'=>'Manager']);
        $user=User::create(['name'=>'Manager','email'=>'dash-page@example.test','password'=>bcrypt('test-only'),'company_id'=>$a->id,'role_id'=>$role->id,'is_active'=>true]);
        $this->actingAs($user);
        $status=EmployeeStatus::firstOrCreate(['name'=>'Active']);
        for($i=1;$i<=40;$i++) Employee::create(['employee_code'=>'DASH-P'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'full_name'=>'Paging employee '.$i,'first_name'=>'Paging','company_id'=>$a->id,'status_id'=>$status->id,'hire_date'=>'2025-01-01']);
        $seen=[];
        $firstPageBody=null;
        foreach([1,2,3] as $page) {
            $body=$this->get(route('dashboard.details','new-employees').'?page='.$page)->assertOk()->getContent();
            if($page===1) $firstPageBody=$body;
            preg_match_all('/DASH-P\d+/',$body,$m);
            foreach($m[0] as $code) $seen[]=$code;
        }
        $this->assertCount(40,$seen);
        $this->assertCount(40,array_unique($seen));
        $this->get(route('dashboard.details','new-employees').'?page=1')->assertOk()->assertSee($firstPageBody===null?'':'',false);
        $repeat=$this->get(route('dashboard.details','new-employees').'?page=1')->assertOk()->getContent();
        $this->assertSame($firstPageBody,$repeat);
    }
}
