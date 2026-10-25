<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ContactMessage;

class MarketingSiteController extends Controller
{
    public function signup(Request $request)
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->formPage($request, 'signup.html', route('register.company'), [
            ['<input required placeholder="مثال: شركة النور">', '<input name="company_name" required placeholder="مثال: شركة النور">'],
            ['<input required placeholder="الاسم بالكامل">', '<input name="owner_name" required placeholder="الاسم بالكامل">'],
            ['<select required>', '<select name="employee_count">'],
            ['<option>1–10</option>', '<option value="10">1–10</option>'],
            ['<option>11–50</option>', '<option value="50">11–50</option>'],
            ['<option>51–200</option>', '<option value="200">51–200</option>'],
            ['<option>201–500</option>', '<option value="500">201–500</option>'],
            ['<option>أكثر من 500</option>', '<option value="1000">أكثر من 500</option>'],
            ['<input type="email" required placeholder="name@company.com">', '<input name="email" type="email" required placeholder="name@company.com">'],
            ['<input type="tel" required placeholder="01xxxxxxxxx">', '<input name="phone" type="tel" required placeholder="01xxxxxxxxx">'],
            ['<input type="password" required minlength="8" placeholder="8 أحرف على الأقل">', '<input name="password" id="signup-password" type="password" required minlength="8" placeholder="8 أحرف على الأقل">'],
            ['<input type="checkbox" required>', '<input name="terms_accepted" type="checkbox" value="1" required>'],
            ['<button class="btn primary full" type="submit">', '<input type="hidden" name="password_confirmation" id="signup-password-confirmation"><button class="btn primary full" type="submit">'],
        ], <<<'JS'
<script>document.getElementById('form').addEventListener('submit',function(){document.getElementById('signup-password-confirmation').value=document.getElementById('signup-password').value;});const employeeCount=document.querySelector('[name="employee_count"]');if(employeeCount&&employeeCount.closest('.field'))employeeCount.closest('.field').style.display='none';</script>
JS);
    }

    public function login(Request $request)
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->formPage($request, 'login.html', route('login.attempt'), [
            ['<input type="email" required placeholder="name@company.com" autocomplete="email">', '<input name="email" type="email" required placeholder="name@company.com" autocomplete="email">'],
            ['<input type="password" required minlength="8" placeholder="أدخل كلمة المرور" autocomplete="current-password">', '<input name="password" type="password" required minlength="8" placeholder="أدخل كلمة المرور" autocomplete="current-password">'],
            ['<label><input type="checkbox"> تذكرني</label>', '<label><input name="remember" type="checkbox" value="1"> تذكرني</label>'],
        ], <<<'JS'
<script>
window.addEventListener('pageshow', function (event) {
    if (event.persisted || (performance.getEntriesByType('navigation')[0]?.type === 'back_forward')) window.location.reload();
});
</script>
JS);
    }

    public function forgotPassword(Request $request)
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->formPage($request, 'forgot-password.html', route('password.email'), [
            ['<input type="email" required placeholder="name@company.com">', '<input name="email" type="email" required placeholder="name@company.com" autocomplete="email">'],
        ]);
    }

    public function resetPassword(Request $request, string $token)
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->formPage($request, 'forgot-password.html', route('password.update'), [
            ['<h1>ط§ط³طھط¹ط§ط¯ط© ظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط±</h1>', '<h1>طھط¹ظٹظٹظ† ظƒظ„ظ…ط© ظ…ط±ظˆط± ط¬ط¯ظٹط¯ط©</h1>'],
            ['<p class="sub">ط£ط¯ط®ظ„ ط§ظ„ط¨ط±ظٹط¯ ط§ظ„ط¥ظ„ظƒطھط±ظˆظ†ظٹ ط§ظ„ظ…ط±طھط¨ط· ط¨ط­ط³ط§ط¨ظƒطŒ ظˆط³طھط±ط³ظ„ ط§ظ„ظ…ظ†طµط© ط±ط§ط¨ط·ظ‹ط§ ط¢ظ…ظ†ظ‹ط§ ظ„ط¥ط¹ط§ط¯ط© طھط¹ظٹظٹظ† ظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط± ط¨ط¹ط¯ ط±ط¨ط· ط§ظ„ط®ط¯ظ…ط©.</p>', '<p class="sub">ط£ط¯ط®ظ„ ط¨ط±ظٹط¯ظƒ ظˆظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط± ط§ظ„ط¬ط¯ظٹط¯ط© ظ„ط¥طھظ…ط§ظ… ط¥ط¹ط§ط¯ط© ط§ظ„طھط¹ظٹظٹظ† ط¨ط£ظ…ط§ظ†.</p>'],
            ['<input type="email" required placeholder="name@company.com">', '<input name="email" type="email" required placeholder="name@company.com" autocomplete="email">'],
            ['<button class="btn primary full">ط¥ط±ط³ط§ظ„ ط±ط§ط¨ط· ط§ظ„ط§ط³طھط¹ط§ط¯ط©</button>', '<div class="field"><label>ظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط± ط§ظ„ط¬ط¯ظٹط¯ط©</label><input name="password" type="password" required minlength="8" autocomplete="new-password"></div><div class="field"><label>طھط£ظƒظٹط¯ ظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط±</label><input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></div><button class="btn primary full">ط­ظپط¸ ظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط±</button>'],
        ], '', ['token' => $token]);
    }

    public function contact(Request $request)
    {
        return $this->formPage($request, 'contact.html', route('site.contact.submit'), [
            ['<input required placeholder="اسمك بالكامل">', '<input name="name" required placeholder="اسمك بالكامل">'],
            ['<input type="email" required placeholder="name@company.com">', '<input name="email" type="email" required placeholder="name@company.com">'],
            ['<select required>', '<select name="subject" required>'],
            ['<textarea required rows="4" placeholder="اكتب تفاصيل استفسارك">', '<textarea name="message" required rows="4" placeholder="اكتب تفاصيل استفسارك">'],
        ]);
    }

    public function submitContact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $data['company_id'] = $request->user()?->company_id;
        ContactMessage::create($data);

        return redirect()->route('site.contact')->with('site_success', 'تم استلام رسالتك بنجاح. سيتواصل معك فريق مسار قريبًا.');
    }

    private function formPage(Request $request, string $file, string $action, array $replacements, string $script = '', array $hidden = [])
    {
        $html = file_get_contents(base_path('masar_hr_multi_page_site/' . $file));
        $token = '<input type="hidden" name="_token" value="' . e($request->session()->token()) . '">';
        foreach ($hidden as $name => $value) {
            $token .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
        }
        $html = str_replace('<form id="form">', '<form id="form" method="POST" action="' . e($action) . '">' . $token, $html);
        foreach ($replacements as [$search, $replace]) $html = str_replace($search, $replace, $html);
        $html = preg_replace('~<script>document\.getElementById\([\'\"]form[\'\"]\).*?</script>~s', '', $html, 1);
        $html = preg_replace('~<a[^>]+href="(?:pricing|how-it-works)\\.html"[^>]*>.*?</a>~u', '', $html);
        $html = preg_replace('~<p class="note">.*?</p>~u', '', $html);
        if ($script !== '') $html = str_replace('</body>', $script . '</body>', $html);

        if ($request->session()->has('errors')) {
            $message = e($request->session()->get('errors')->first());
            $html = str_replace('<div id="msg" class="alert"></div>', '<div id="msg" class="alert" style="display:block">' . $message . '</div>', $html);
        }
        if ($request->session()->has('site_success')) {
            $html = str_replace('<div id="msg" class="alert"></div>', '<div id="msg" class="alert" style="display:block">' . e($request->session()->get('site_success')) . '</div>', $html);
        }

        if (isset($hidden['token'])) {
            $html = preg_replace('~<h1>.*?</h1>~s', '<h1>تعيين كلمة مرور جديدة</h1>', $html, 1);
            $html = preg_replace('~<p class="sub">.*?</p>~s', '<p class="sub">أدخل بريدك وكلمة المرور الجديدة لإتمام إعادة التعيين بأمان.</p>', $html, 1);
            $html = preg_replace('~<button class="btn primary full">.*?</button>~s', '<div class="field"><label>كلمة المرور الجديدة</label><input name="password" type="password" required minlength="8" autocomplete="new-password"></div><div class="field"><label>تأكيد كلمة المرور</label><input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></div><button class="btn primary full">حفظ كلمة المرور</button>', $html, 1);
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
