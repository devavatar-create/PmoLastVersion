<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Makes the key pair web push needs (Settings > การแจ้งเตือน › แจ้งเตือนบนเครื่องนี้). Prints them, or writes them into .env with --write. */
class WebPushKeys extends Command
{
    protected $signature = 'webpush:keys {--write : put them into .env (replacing any WEBPUSH_*_KEY lines)}';

    protected $description = 'Generate the VAPID key pair for web push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $lines = "WEBPUSH_PUBLIC_KEY={$keys['publicKey']}\nWEBPUSH_PRIVATE_KEY={$keys['privateKey']}";

        if (! $this->option('write')) {
            $this->line($lines);
            $this->comment('ใส่สองบรรทัดนี้ (และ WEBPUSH_SUBJECT=mailto:อีเมลผู้ดูแล) ใน .env แล้วรัน php artisan config:clear — ห้ามเปลี่ยนคีย์หลังมีคนเปิดใช้แล้ว เพราะเครื่องเหล่านั้นต้องเปิดใหม่');

            return self::SUCCESS;
        }

        $env = base_path('.env');
        $text = preg_replace('/^WEBPUSH_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', (string) @file_get_contents($env));
        file_put_contents($env, rtrim($text)."\n".$lines."\n".(str_contains($text, 'WEBPUSH_SUBJECT=') ? '' : "WEBPUSH_SUBJECT=mailto:admin@example.com\n"));
        $this->info('เขียนคีย์ลง .env แล้ว — รัน php artisan config:clear');

        return self::SUCCESS;
    }
}
