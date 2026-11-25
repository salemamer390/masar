<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\GlobalPayrollRulesService;
use Illuminate\Http\Request;

/**
 * Owner-only editing of the platform-wide social insurance and income tax
 * rules. A save applies to every company at once and recalculates their
 * active salary structures. Approved or paid payroll runs do not change.
 */
class OwnerPayrollRulesController extends Controller
{
    public function updateSettings(Request $request, GlobalPayrollRulesService $rules)
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'settings' => ['required', 'array'],
        ]);
        abort_if(array_diff(array_keys($data['settings']), GlobalPayrollRulesService::SETTING_KEYS) !== [], 422, 'Invalid payroll setting.');
        foreach ($data['settings'] as $value) {
            if ($value !== null && $value !== '' && (!is_numeric($value) || (float) $value < 0)) {
                return back()->withErrors(['payroll_rules' => __('كل القيم يجب أن تكون أرقامًا موجبة.')])->with('open_owner_tab', 'payroll-rules')->withInput();
            }
        }

        $rules->saveSettings($data['settings'], $data['effective_from']);
        $count = $rules->recalculateAllCompanies();
        ActivityLog::record('owner_payroll_rules_update', 'تحديث قيم التأمينات والضرائب العامة لكل الشركات.', null, null, null, 'owner');

        return redirect()->route('owner.index')->with('open_owner_tab', 'payroll-rules')
            ->with('toast', "تم حفظ التأمينات والضرائب لكل الشركات وإعادة حساب {$count} هيكل راتب. المسيرات المعتمدة أو المصروفة لم تتغير.");
    }

    public function updateTax(Request $request, GlobalPayrollRulesService $rules)
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.label' => ['required', 'string', 'max:150'],
            'categories.*.max_income' => ['nullable', 'numeric', 'min:0'],
            'categories.*.brackets' => ['required', 'array', 'min:1'],
            'categories.*.brackets.*.from' => ['required', 'numeric', 'min:0'],
            'categories.*.brackets.*.to' => ['nullable', 'numeric', 'min:0'],
            'categories.*.brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $categories = array_values($data['categories']);
        if ($error = $rules->validateTax($categories)) {
            return back()->withErrors(['payroll_tax' => $error])->with('open_owner_tab', 'payroll-rules')->withInput();
        }

        $rules->saveTax($categories, $data['effective_from']);
        $count = $rules->recalculateAllCompanies();
        ActivityLog::record('owner_payroll_tax_update', 'تحديث شرائح الضرائب العامة لكل الشركات.', null, null, null, 'owner');

        return redirect()->route('owner.index')->with('open_owner_tab', 'payroll-rules')
            ->with('toast', "تم حفظ شرائح الضرائب لكل الشركات وإعادة حساب {$count} هيكل راتب.");
    }
}
