<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailVerificationController extends Controller
{
    /** Opened from the email, so it works even when the user is not logged in. The signed URL is the proof. */
    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::find($id);
        abort_unless($user && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $message = __('تم تأكيد بريدك الإلكتروني بنجاح.');

        return Auth::check()
            ? redirect()->route('dashboard')->with('toast', $message)
            : redirect()->route('login')->with('site_success', $message);
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return back()->with('toast', __('بريدك الإلكتروني مؤكد بالفعل.'));
        }

        $sent = $user->sendEmailVerificationNotification();

        return $sent
            ? back()->with('toast', __('أرسلنا رابط التأكيد إلى بريدك الإلكتروني.'))
            : back()->with('error', __('تعذر إرسال الرسالة الآن. حاول مرة أخرى بعد قليل.'));
    }
}
