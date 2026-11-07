<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // resignation | contract_end | dismissal
            $table->string('reason', 30);
            $table->date('last_working_day');
            // Draft → Approved → Paid (final). Canceled only from Draft.
            $table->string('status', 20)->default('Draft');
            // Snapshot of the payroll period that owns the last working day.
            $table->date('anchor_month');
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('leave_balance_days', 8, 2)->default(0);
            // pending = reviewer must decide before approval; skip | basic | gross
            $table->string('leave_payout_mode', 20)->default('pending');
            $table->decimal('leave_payout_amount', 12, 2)->default(0);

            $table->decimal('advance_outstanding', 12, 2)->default(0);
            // pending = reviewer must decide before approval; scheduled | full
            $table->string('advance_recovery_mode', 20)->default('pending');
            $table->decimal('advance_recovery_amount', 12, 2)->default(0);
            // [{request_id, amount}] — payroll reads this so a recovered advance is never deducted twice.
            $table->json('advance_recovery_details')->nullable();

            $table->decimal('gratuity_amount', 12, 2)->default(0);
            $table->decimal('manual_earnings', 12, 2)->default(0);
            $table->decimal('manual_deductions', 12, 2)->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();

            // draft_run = applied onto the employee's record inside a Draft payroll run;
            // standalone = no open run exists, settlement is paid on its own.
            $table->string('reconciliation', 20)->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->foreignId('payroll_record_id')->nullable()->constrained('payroll_records')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        foreach ([
            ['settlements.view', 'View Final Settlements', 'settlements', 'view'],
            ['settlements.create', 'Create & Edit Final Settlements', 'settlements', 'create'],
            ['settlements.approve', 'Approve & Pay Final Settlements', 'settlements', 'approve'],
        ] as [$name, $displayName, $module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $displayName, 'module' => $module, 'action' => $action]
            );
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            foreach (DB::table('roles')->whereIn('name', ['super_admin', 'company_manager', 'hr_manager', 'payroll_manager', 'payroll'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('module', 'settlements')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('settlements');
    }
};
