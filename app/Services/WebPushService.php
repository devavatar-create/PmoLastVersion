<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends a notice to every device a person switched push on for (Settings > การแจ้งเตือน). Off when the VAPID keys are not set.
 * A device the push service says is gone (404 / 410) is forgotten. Never throws: push is a bonus on top of the in-app bell.
 */
class WebPushService
{
    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    /** @param  Collection<int, User>  $users */
    /** @param  array<string, mixed>  $extra  more for the service worker, e.g. ['accept' => true] = show a "รับงาน" button */
    public function send(Collection $users, string $title, string $body, string $url, ?string $tag = null, array $extra = []): void
    {
        if (! $this->enabled() || $users->isEmpty()) {
            return;
        }
        $subs = PushSubscription::whereIn('user_id', $users->pluck('id'))->get();
        if ($subs->isEmpty()) {
            return;
        }

        try {
            $push = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600], 8);
            $payload = json_encode(['title' => $title, 'body' => mb_strimwidth($body, 0, 180, '…'), 'url' => $url, 'tag' => $tag] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($subs as $s) {
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth]), $payload);
            }
            foreach ($push->flush() as $report) {
                if (! $report->isSuccess() && in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('web push failed: '.$e->getMessage());
        }
    }
}
