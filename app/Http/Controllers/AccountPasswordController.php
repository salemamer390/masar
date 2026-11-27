<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountPasswordController extends Controller
{
    public function edit()
    {
        return view('account.password');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => __('كلمة المرور الحالية مطلوبة.'),
            'current_password.current_password' => __('كلمة المرور الحالية غير صحيحة.'),
            'password.required' => __('كلمة المرور الجديدة مطلوبة.'),
            'password.min' => __('كلمة المرور يجب ألا تقل عن 8 أحرف.'),
            'password.confirmed' => __('تأكيد كلمة المرور غير مطابق.'),
            'password.different' => __('كلمة المرور الجديدة يجب أن تختلف عن الحالية.'),
        ]);

        $user = $request->user();
        $user->password = Hash::make($data['password']);
        $user->save();
        // Keep this session signed in; the user's other sessions end.
        session()->put('password_hash_web_'.$user->id, $user->getAuthPassword());
        $user->sendPasswordChangedNotification();

        return redirect()->route('account.password')->with('toast', __('تم تغيير كلمة المرور بنجاح.'));
    }
}
