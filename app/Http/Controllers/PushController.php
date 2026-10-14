<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\Request;

/** This browser / device: start or stop push notices (Settings > การแจ้งเตือน), and a test notice. */
class PushController extends Controller
{
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:700', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, WebPushService $push)
    {
        abort_unless($push->enabled(), 422, 'ยังไม่ได้ตั้งค่า Web Push บนเซิร์ฟเวอร์ (WEBPUSH_PUBLIC_KEY / WEBPUSH_PRIVATE_KEY)');
        $push->send(collect([$request->user()]), 'ทดสอบการแจ้งเตือน', 'ถ้าเห็นข้อความนี้ แปลว่าเครื่องนี้รับแจ้งเตือนได้แล้ว', route('planning.board'));

        return response()->json(['ok' => true, 'devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }
}
