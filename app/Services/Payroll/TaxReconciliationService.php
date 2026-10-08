<?php

namespace App\Services\Payroll;

use App\Models\PayrollRecord;
use Illuminate\Support\Facades\DB;

class TaxReconciliationService
{
    public function __construct(private readonly TaxEngine $tax) {}

    public function calculate(int $employeeId, int $year): array
    {
        $records = PayrollRecord::where('employee_id',$employeeId)->whereYear('payroll_month',$year)->get();
        $annualGross = $records->sum(fn($r)=>(float)$r->gross_salary);
        $annualInsurance = $records->sum(fn($r)=>(float)$r->employee_insurance);
        $deductible = $records->sum(fn($r)=>(float)$r->tax_deductible_contributions);
        $exemptProfit = $records->sum(fn($r)=>(float)$r->tax_exempt_profit_share);
        $taxable = max(0,$annualGross-$annualInsurance-$deductible-$exemptProfit-$this->tax->personalExemption());
        $breakdown = $this->tax->breakdown($taxable);
        $paid = $records->sum(fn($r)=>(float)$r->income_tax);
        $difference = round($breakdown['annual_tax']-$paid,2);
        $status = $difference > 0 ? 'additional_tax_due' : ($difference < 0 ? 'overpayment_or_credit' : 'fully_settled');
        return compact('annualGross','annualInsurance','deductible','exemptProfit','taxable','breakdown','paid','difference','status');
    }

    public function save(int $employeeId, int $year): array
    {
        $r = $this->calculate($employeeId,$year);
        DB::table('tax_reconciliations')->updateOrInsert(
            ['employee_id'=>$employeeId,'tax_year'=>$year],
            ['actual_annual_tax'=>$r['breakdown']['annual_tax'],'monthly_tax_paid'=>$r['paid'],'tax_difference'=>$r['difference'],'status'=>$r['status'],'updated_at'=>now(),'created_at'=>now()]
        );
        return $r;
    }
}
