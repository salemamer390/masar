<?php
namespace Tests\Feature;
use App\Models\{Attendance,AttendanceImportDay,AttendanceImportBatch,AttendancePunchRevision,AttendancePeriodArchive,Company,CompanySubscription,Employee,EmployeeStatus,Role,SubscriptionPlan,User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\TestCase;
class AttendanceExceptionTest extends TestCase {
    use DatabaseTransactions;
    private User $user; private Company $company; private Employee $day; private Employee $night;
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        Storage::fake('local');
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'import-preview-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'ImportPreview', 'slug' => 'import-preview', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'import-preview@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->day = Employee::create(['employee_code' => 'I1', 'first_name' => 'Hoda', 'full_name' => 'Hoda Import', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        $this->night = Employee::create(['employee_code' => 'I2', 'first_name' => 'Khaled', 'full_name' => 'Khaled Night', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '22:00', 'shift_end' => '06:00']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }


    private function importPunches(array $times): AttendanceImportDay {
        $csv="رقم الموظف,التاريخ والوقت\n";
        foreach ($times as $at) $csv.="I2,$at\n";
        $r=$this->post('/attendance/import/preview',['file'=>UploadedFile::fake()->createWithContent('raw.csv',$csv)]);
        $r->assertOk();$html=$r->getContent();
        preg_match('/name="import_token" value="([0-9a-f-]{36})"/',$html,$t);
        preg_match('/class="form-check-input unit-radio"[^>]*value="([^"]+)"/',$html,$u);
        preg_match('/name="preview_key" id="previewKey" value="([0-9a-f]{64})"/',$html,$k);
        $this->post('/attendance/import',['import_token'=>$t[1],'unit'=>$u[1],'preview_key'=>$k[1]])->assertRedirect();
        return AttendanceImportDay::latest('id')->firstOrFail();
    }
    private function extra(): AttendanceImportDay {
        return $this->importPunches(['2026-11-02 21:55:00','2026-11-03 01:00:00','2026-11-03 06:05:00']);
    }
    private function correction(array $extra=[]): array {
        return array_merge(['version'=>0,'punches'=>['2026-11-02T21:55','2026-11-03T01:00','2026-11-03T06:05'],'check_in_index'=>0,'check_out_index'=>2,'reason'=>'Duplicate reviewed'], $extra);
    }
    public function test_all_raw_punches_are_private_and_extra_day_waits_for_review(): void {
        $day=$this->extra();
        $this->assertStringContainsString('الصف',$day->original_punches[0]['ref']);
        $this->assertSame('extra',$day->issue);$this->assertCount(3,$day->original_punches);
        $this->assertSame(0,Attendance::where('employee_id',$this->night->id)->count());
        $stored=DB::table('attendance_import_days')->where('id',$day->id)->value('original_punches');
        $this->assertStringNotContainsString('21:55',$stored);
        $this->assertArrayNotHasKey('original_punches',$day->toArray());
        $this->get('/attendance/exceptions')->assertOk()->assertSee('بصمات زائدة');
        $this->get('/attendance/exceptions/'.$day->id)->assertOk()->assertSee('01:00');
        $this->get('/attendance/import-batches/'.$day->batch_id)->assertOk()->assertSee('2026-11-03 01:00:00');
    }
    public function test_explicit_overnight_selection_resolves_without_losing_original(): void {
        $day=$this->extra();
        $this->put('/attendance/exceptions/'.$day->id,$this->correction())->assertSessionHasNoErrors()->assertRedirect();
        $day->refresh(); $this->assertSame('resolved',$day->resolution);$this->assertCount(3,$day->original_punches);
        $a=Attendance::findOrFail($day->attendance_id);
        $this->assertSame('2026-11-02',$a->attendance_date->toDateString());
        $this->assertStringStartsWith('21:55',$a->check_in);$this->assertStringStartsWith('06:05',$a->check_out);
        $this->assertSame(0,$a->late_minutes);
        $revision=AttendancePunchRevision::where('import_day_id',$day->id)->firstOrFail();
        $this->assertSame($this->user->id,$revision->user_id);$this->assertCount(3,$revision->before_data['punches']);
        $this->get('/attendance/exceptions/'.$day->id)->assertOk()->assertSee('Duplicate reviewed');
    }
    public function test_missing_punch_remains_missing_without_invented_departure(): void {
        $day=$this->importPunches(['2026-11-02 22:00:00']);
        $this->assertSame('missing',$day->issue);$this->assertSame('pending',$day->resolution);
        $this->assertNull(Attendance::findOrFail($day->attendance_id)->check_out);
        $this->putJson('/attendance/exceptions/'.$day->id,['punches'=>['2026-11-02T22:00'],'check_in_index'=>0,'reason'=>'Only check in'])->assertStatus(422);
        $this->assertSame(0,AttendancePunchRevision::count());
    }
    public function test_deleted_punch_is_audited_and_original_retained(): void {
        $day=$this->extra();
        $this->delete('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'Wrong duplicate day'])->assertRedirect();
        $day->refresh();$this->assertSame('deleted',$day->resolution);$this->assertCount(3,$day->original_punches);$this->assertCount(0,$day->current_punches);
        $this->assertSame('delete',AttendancePunchRevision::first()->action);
        $this->assertNotNull(AttendanceImportBatch::find($day->batch_id));
    }
    public function test_closed_period_refuses_correction_and_delete(): void {
        $day=$this->extra();
        AttendancePeriodArchive::create(['company_id'=>$this->company->id,'period_start'=>'2026-11-01','period_end'=>'2026-11-30','closes_on'=>'2026-12-01','status'=>'closed','rows'=>[]]);
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction())->assertStatus(422);
        $this->deleteJson('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'Not allowed'])->assertStatus(422);
        $this->assertSame(0,AttendancePunchRevision::count());
        $this->get('/attendance/exceptions/'.$day->id)->assertOk()->assertSee('الفترة مقفولة');
    }
    public function test_other_company_cannot_read_or_mutate_lineage(): void {
        $day=$this->extra();
        $company=Company::create(['name'=>'Other','slug'=>'exceptions-other','status'=>'active']);
        $other=User::create(['name'=>'Other','email'=>'exception-other@example.test','password'=>bcrypt('test'),'company_id'=>$company->id,'role_id'=>$this->user->role_id,'is_active'=>true]);
        $this->actingAs($other);
        $this->getJson('/attendance/exceptions/'.$day->id)->assertNotFound();
        $this->getJson('/attendance/import-batches/'.$day->batch_id)->assertNotFound();
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction())->assertNotFound();
        $this->deleteJson('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'No'])->assertNotFound();
    }
    public function test_viewer_cannot_correct_or_delete(): void {
        $day=$this->extra();
        $role=Role::create(['name'=>'viewer','display_name'=>'Viewer','company_id'=>$this->company->id]);
        $permission=\App\Models\Permission::firstOrCreate(['name'=>'attendance.view'],['display_name'=>'View attendance','module'=>'attendance','action'=>'view']);
        $role->permissions()->sync([$permission->id]);
        $this->user->update(['role_id'=>$role->id]);$this->user->unsetRelation('role');
        $this->get('/attendance/exceptions/'.$day->id)->assertOk()->assertDontSee('حفظ وإعادة الفحص');
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction())->assertForbidden();
        $this->deleteJson('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'No'])->assertForbidden();
    }
    public function test_stale_revision_is_refused_and_raw_is_immutable(): void {
        $day=$this->extra();
        $this->put('/attendance/exceptions/'.$day->id,$this->correction())->assertRedirect();
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction(['overwrite'=>1]))->assertStatus(409);
        $this->deleteJson('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'stale'])->assertStatus(409);
        $this->assertSame(1,AttendancePunchRevision::count());
        $this->expectException(\LogicException::class);
        $day->update(['original_punches'=>[]]);
    }
    public function test_cross_day_selection_is_rejected_instead_of_losing_date(): void {
        $day=$this->extra();
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction(['punches'=>['2026-11-03T08:00','2026-11-03T09:00'],'check_out_index'=>1]))->assertStatus(422);
        $this->assertSame(0,AttendancePunchRevision::count());
    }
    public function test_finalized_payroll_refuses_all_exception_writes(): void {
        $day=$this->extra();
        \App\Models\PayrollRun::create(['company_id'=>$this->company->id,'payroll_month'=>'2026-11-01','period_start'=>'2026-11-01','period_end'=>'2026-11-30','status'=>'Locked','created_by'=>$this->user->id]);
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction())->assertStatus(422);
        $this->deleteJson('/attendance/exceptions/'.$day->id,['version'=>0,'reason'=>'Locked'])->assertStatus(422);
        $this->assertSame(0,AttendancePunchRevision::count());
    }
    public function test_editor_without_delete_cannot_remove_a_punch(): void {
        $day=$this->extra();
        $role=Role::create(['name'=>'punch-editor','display_name'=>'Editor','company_id'=>$this->company->id]);
        $ids=[];foreach(['view','edit','create'] as $action) $ids[]=\App\Models\Permission::firstOrCreate(['name'=>'attendance.'.$action],['module'=>'attendance','action'=>$action,'display_name'=>$action])->id;
        $role->permissions()->sync($ids);$this->user->update(['role_id'=>$role->id]);$this->user->unsetRelation('role');
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction(['punches'=>['2026-11-02T21:55','2026-11-03T06:05'],'check_out_index'=>1]))->assertForbidden();
        $this->assertSame(0,AttendancePunchRevision::count());
    }
    public function test_source_view_hides_unmapped_private_cells_and_escapes_html(): void {
        $batch=AttendanceImportBatch::create(['company_id'=>$this->company->id,'user_id'=>$this->user->id,'file_name'=>'sample.xlsx','layout'=>'daily','original_data'=>[
            'map'=>['employee_code'=>0,'date'=>1,'check_in'=>2,'check_out'=>3],
            'unit'=>['sheets'=>[['name'=>'daily','header_row'=>1]]],
            'sheets'=>[['name'=>'daily','rows'=>[0=>['PRIVATE_METADATA'],1=>['Code','Date','In','Out','Salary','ID'],2=>['I1','2026-11-02','09:00','17:00','PRIVATE_SALARY','PRIVATE_NATIONAL_ID'],3=>['<script>alert(1)</script>','2026-11-03','09:00','17:00','PRIVATE_SALARY_2']]]],
        ]]);
        $r=$this->get('/attendance/import-batches/'.$batch->id);$r->assertOk();
        $r->assertDontSee('PRIVATE_METADATA')->assertDontSee('PRIVATE_SALARY')->assertDontSee('PRIVATE_NATIONAL_ID');
        $r->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
        $role=Role::create(['name'=>'viewer','display_name'=>'Viewer','company_id'=>$this->company->id]);
        $permission=\App\Models\Permission::firstOrCreate(['name'=>'attendance.view'],['display_name'=>'View attendance','module'=>'attendance','action'=>'view']);
        $role->permissions()->sync([$permission->id]);$this->user->update(['role_id'=>$role->id]);$this->user->unsetRelation('role');
        $this->getJson('/attendance/import-batches/'.$batch->id)->assertForbidden();
    }
    public function test_existing_daily_record_requires_explicit_overwrite(): void {
        $day=$this->extra();
        Attendance::create(['employee_id'=>$this->night->id,'attendance_date'=>'2026-11-02','check_in'=>'22:00','check_out'=>'06:00','status'=>'Present','source'=>'manual']);
        $this->putJson('/attendance/exceptions/'.$day->id,$this->correction())->assertStatus(422);
        $this->put('/attendance/exceptions/'.$day->id,$this->correction(['overwrite'=>1]))->assertRedirect();
        $this->assertSame('resolved',$day->fresh()->resolution);
    }
}
