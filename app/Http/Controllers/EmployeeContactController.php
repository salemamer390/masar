<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeEmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeContactController extends Controller
{
    public function storeEmergencyContact(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'relationship' => ['required', 'string', 'max:100'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $contact = DB::transaction(function () use ($data, $employee) {
            if (!empty($data['is_primary'])) {
                $employee->emergencyContacts()->update(['is_primary' => false]);
            }
            return $employee->emergencyContacts()->create($data);
        });

        ActivityLog::record('emergency_contact_create', 'Emergency contact created.', $contact, null, $contact->toArray(), 'employees');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'تم حفظ جهة اتصال الطوارئ.', 'contact' => $contact->fresh()])
            : back()->with('toast', 'تم حفظ جهة اتصال الطوارئ.');
    }

    public function storeBankAccount(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'bank_id' => ['nullable', new \App\Rules\VisibleModel(\App\Models\Bank::class)],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:64'],
            'payment_method_id' => ['nullable', new \App\Rules\VisibleModel(\App\Models\PaymentMethod::class)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $account = DB::transaction(function () use ($data, $employee) {
            if (!empty($data['is_primary'])) {
                $employee->bankAccounts()->where('status', 'Active')->update(['is_primary' => false]);
            }
            return $employee->bankAccounts()->create($data + ['status' => 'Active']);
        });

        ActivityLog::record('bank_account_create', 'Employee bank account created.', $account, null, $account->toArray(), 'employees');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'تم حفظ الحساب البنكي.', 'account' => $account->fresh(['bank', 'paymentMethod'])])
            : back()->with('toast', 'تم حفظ الحساب البنكي.');
    }
}
