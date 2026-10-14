<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notice (the bell in the top bar). In-app only on purpose: accounts
 * here are plain usernames, not real email addresses (see LoginRequest), so
 * there is nothing reliable to mail to.
 *
 * `key` identifies the event so a reminder isn't sent twice for the same thing
 * (see NotificationService::alreadySent).
 */
class PmoNotice extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $url,
        public ?string $key = null,
        public ?int $projectId = null,   // the project it is about ("ของฉัน" on Planning lists projects that tagged / alerted you)
        // notices with the same group that arrive while the last one is still unread become ONE notice ("แผนกขาย รับงาน 18 งาน"):
        // groupTitle is its title then, ":n" = how many; count = how many this notice already stands for
        public ?string $group = null,
        public ?string $groupTitle = null,
        public int $count = 1,
        // one notice per PROJECT: what this event adds to the project's summary ("งานใหม่ 3", "Busbar รับงาน 18", "เกินกำหนด 10 งาน")
        public array $parts = [],
        public ?string $projectLabel = null,
        public ?string $latest = null,   // a project's notice: the newest event in words ("แผนก ขาย รับงาน: งาน X (SO / MO)")
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'key' => $this->key,
            'project_id' => $this->projectId,
            'group' => $this->group,
            'group_title' => $this->groupTitle,
            'count' => $this->count,
            'parts' => $this->parts ?: null,
            'urgent' => $this->parts ? self::urgentParts($this->parts) : null,
            'latest' => $this->latest,
        ];
    }

    /** Parts that make the project's notice urgent (pops the alert window): new work for you, overdue work, you were made in charge. */
    public static function urgentParts(array $parts): bool
    {
        return (bool) array_intersect(array_keys($parts), ['assigned', 'overdue', 'owner']);
    }

    /**
     * Adds an event's parts to a project's summary: counts add up, the daily reminder parts (overdue / due soon) are a
     * snapshot and replace the old one.
     */
    public static function mergeParts(array $old, array $new): array
    {
        foreach ($new as $k => $v) {
            $old[$k] = in_array($k, ['overdue', 'due_soon', 'undated', 'handoff_today', 'handoff_late', 'waiting'], true) || ! is_int($v) ? $v : (int) ($old[$k] ?? 0) + $v;
        }

        return $old;
    }

    /** The one-line summary of a project's notice, in a fixed order. */
    public static function summary(array $parts, ?string $lastComment = null): string
    {
        $out = [];
        foreach (['owner', 'assigned', 'due_changed'] as $k) {
            if (! empty($parts[$k])) {
                $out[] = ['owner' => 'คุณเป็นผู้รับผิดชอบ', 'assigned' => "งานใหม่ {$parts[$k]} งาน", 'due_changed' => "เลื่อนวันส่ง {$parts[$k]} ครั้ง"][$k];
            }
        }
        foreach ($parts as $k => $n) {   // "accepted|Busbar" => 18
            if (preg_match('/^(accepted|started|completed)\|(.+)$/u', $k, $m)) {
                $out[] = $m[2].' '.['accepted' => 'รับงาน', 'started' => 'เริ่มงาน', 'completed' => 'เสร็จ'][$m[1]].' '.$n;
            }
        }
        foreach ($parts as $k => $n) {   // Settings > การส่งต่องาน: "handoff_date|จัดซื้อ|วันของเข้า" => 5, "handoff_done|คลัง" => 3, "handoff_cab|คลัง" => 1
            if (preg_match('/^handoff_date\|(.+)\|(.+)$/u', $k, $m)) {
                $out[] = "{$m[1]} ลง{$m[2]} {$n} รายการ";
            } elseif (preg_match('/^handoff_(done|cab)\|(.+)$/u', $k, $m)) {
                $out[] = $m[1] === 'cab' ? "{$m[2]} ครบ {$n} ตู้" : "{$m[2]} เสร็จ {$n} รายการ";
            }
        }
        if (! empty($parts['handoff_late'])) {
            $out[] = '📦 เลย'.$parts['handoff_late']['label'].' '.$parts['handoff_late']['n'].' รายการ';
        }
        if (! empty($parts['handoff_today'])) {
            $out[] = '📦 '.$parts['handoff_today']['label'].'วันนี้ '.$parts['handoff_today']['n'].' รายการ';
        }
        if (! empty($parts['overdue'])) {
            $out[] = '⚠ เกินกำหนด '.$parts['overdue']['n'].' งาน (สุด '.$parts['overdue']['worst'].' วัน)';
        }
        if (! empty($parts['waiting'])) {
            $out[] = '📥 รอรับงาน '.$parts['waiting']['n'].' งาน (นานสุด '.$parts['waiting']['days'].' วัน)';
        }
        if (! empty($parts['undated'])) {
            $out[] = '📅 ยังไม่มีวันส่ง '.$parts['undated'].' งาน';
        }
        if (! empty($parts['due_soon'])) {
            $out[] = 'ใกล้ครบกำหนด '.$parts['due_soon'].' งาน';
        }
        if (! empty($parts['comment'])) {
            $out[] = 'คอมเมนต์ใหม่ '.$parts['comment'].($lastComment ? ' — “'.mb_strimwidth($lastComment, 0, 60, '…').'”' : '');
        }
        if (! empty($parts['project_done'])) {
            $out[] = '✅ งานทุกแผนกเสร็จแล้ว';
        }

        return implode(' · ', $out);
    }
}
