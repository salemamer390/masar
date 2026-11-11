<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\OwnerGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerAuthController extends Controller
{
    private const ERROR_MESSAGE = 'بيانات الدخول غير صحيحة.';

    public function showLogin()
    {
        $user = Auth::guard('owner')->user();
        if (OwnerGate::allows($user)) {
            return redirect()->route('owner.index');
        }
        // A stale owner session whose account no longer passes the gate is
        // dropped before showing the form, so it cannot bounce in a loop.
        if ($user) Auth::guard('owner')->logout();

        return view('owner.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => __('البريد الإلكتروني مطلوب.'),
            'email.email' => __('يرجى إدخال بريد إلكتروني صحيح.'),
            'password.required' => __('كلمة المرور مطلوبة.'),
        ]);

        // One generic error for every failure: wrong password, unknown email,
        // disabled account, or an account that simply is not the owner.
        if (!Auth::guard('owner')->attempt($credentials)) {
            return back()->withErrors(['email' => __(self::ERROR_MESSAGE)])->onlyInput('email');
        }

        $user = Auth::guard('owner')->user();
        if (!$user->is_active || !OwnerGate::allows($user)) {
            Auth::guard('owner')->logout();
            return back()->withErrors(['email' => __(self::ERROR_MESSAGE)])->onlyInput('email');
        }

        // Session fixation protection; regenerating keeps the regular app
        // guard's session data, so a concurrent company login is preserved.
        $request->session()->regenerate();

        return redirect()->route('owner.index');
    }

    public function logout(Request $request)
    {
        // Only the owner guard is cleared; the regular app session is untouched.
        Auth::guard('owner')->logout();

        return redirect()->route('owner.login');
    }
}
