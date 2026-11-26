<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $notifications = $request->user()->notifications()->latest()->paginate($perPage)->withQueryString();

        return view('notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        return redirect($item->data['url'] ?? route('dashboard'));
    }

    public function confirm(Request $request, string $notification, \App\Services\LeaveAttendanceConflictService $conflicts): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermission('leaves.view'), 403);
        $item = $request->user()->notifications()->findOrFail($notification);
        $conflicts->confirm($request->user(), $item);
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => __('تم تأكيد التعارض ولن يتكرر التنبيه.')]);
        }

        return back()->with('toast', __('تم تأكيد التعارض ولن يتكرر التنبيه.'));
    }

    public function dismissOvertimeGap(Request $request, string $notification, \App\Services\OvertimeGapReminderService $reminders): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermission('attendance.view'), 403);
        $item = $request->user()->notifications()->findOrFail($notification);
        $reminders->dismiss($request->user(), $item);
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => __('تم تجاهل التنبيه ولن يتكرر لهذا اليوم.')]);
        }

        return back()->with('toast', __('تم تجاهل التنبيه ولن يتكرر لهذا اليوم.'));
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Notifications marked as read.',
            ]);
        }

        return back()->with('toast', __('تم تعليم جميع الإشعارات كمقروءة.'));
    }
}
