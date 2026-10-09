<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        return redirect($item->data['url'] ?? route('dashboard'));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('toast', 'تم تعليم جميع الإشعارات كمقروءة.');
    }
}
