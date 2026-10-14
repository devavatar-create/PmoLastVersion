<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('notifications.index', [
            'notifications' => ($unreadOnly ? $request->user()->unreadNotifications() : $request->user()->notifications())->paginate(30)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** What the bell in the top bar shows; every page asks again every 30 seconds (so new notices appear without a reload). */
    public function feed(Request $request)
    {
        return response()->json(self::feedFor($request->user()));
    }

    public static function feedFor($user): array
    {
        $latest = $user->notifications()->limit(8)->get();

        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $latest->map(fn ($n) => [
                'id' => $n->id, 'kind' => $n->data['kind'] ?? '', 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '',
                'at' => $n->created_at->thaiDateTime(), 'read' => $n->read_at !== null, 'latest' => $n->data['latest'] ?? null,
            ])->values()->all(),
            // unread urgent ones (tag, alert, new work, overdue): a new one pops the alert window up at once
            // (id + how many events it holds: a project's notice that gained something urgent counts as new)
            'urgent' => $user->unreadNotifications()->latest()->limit(50)->get()
                ->filter(fn ($n) => ($n->data['urgent'] ?? false) || in_array($n->data['kind'] ?? null, \App\Services\StatusReport::URGENT_KINDS, true))
                ->map(fn ($n) => $n->id.':'.($n->data['count'] ?? 1))->values()->all(),
        ];
    }

    /** Marks one notice read and goes where it points. */
    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect($notification->data['url'] ?? route('notifications.index'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
