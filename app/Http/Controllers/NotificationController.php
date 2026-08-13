<?php

namespace App\Http\Controllers;

use App\Models\NotificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => NotificationDelivery::with('event')
                ->where('recipient_user_id', $request->user()->id)
                ->where('channel', 'SYSTEM')
                ->latest('attempted_at')
                ->paginate(30),
        ]);
    }

    public function read(Request $request, NotificationDelivery $notification): RedirectResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id && $notification->channel === 'SYSTEM', 403);
        $notification->update(['read_at' => $notification->read_at ?: now()]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        NotificationDelivery::query()
            ->where('recipient_user_id', $request->user()->id)
            ->where('channel', 'SYSTEM')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }
}
