<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ContactMessage;

class MarketingSiteController extends Controller
{
    public function home()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('index');
    }

    public function features()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('features');
    }

    public function privacy()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('privacy');
    }

    public function terms()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('terms');
    }

    public function signup()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('signup');
    }

    public function login()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('login');
    }

    public function forgotPassword()
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('forgot');
    }

    public function resetPassword(Request $request, string $token)
    {
        if (Auth::check()) return redirect()->route('dashboard');

        return $this->page('reset', ['token' => $token]);
    }

    public function contact()
    {
        return $this->page('contact');
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

        return redirect()->route('site.contact')->with('site_success', __('تم استلام رسالتك بنجاح. سيتواصل معك فريق اودير قريبًا.'));
    }

    private function page(string $view, array $data = [])
    {
        return response()->view('marketing.'.$view, $data)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
