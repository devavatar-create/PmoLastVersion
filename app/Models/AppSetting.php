<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-controlled switches (Settings > กฎการทำงาน). Read through get() so a missing row means the default below,
 * i.e. an install that never opened the page behaves exactly as before.
 */
class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public const DEFAULTS = [
        'cross_mention' => false,               // anyone may @tag anyone (off: own department + PMO roles only)
        'block_done_needs_checklist' => false,  // a Sub Task cannot be completed until every checklist item is ticked
        'block_project_done_needs_work' => false, // a Project cannot be set to Completed while dispatched work is unfinished
        'automation_locked_statuses' => [],
        'status_rules_locked' => [],            // Planning statuses (board_statuses ids) that status rules never overwrite
        'pmo_acts_for_departments' => false,    // Admin / PM may accept / start / finish work on a department's behalf (logged as such)
        'waiting_reminder_days' => 2,          // work still "รอรับ" after this many days: the department + PM are reminded (0 = off)
        'file_default_folders' => ['เอกสารโครงการ'],   // the standard folders of every project: names, or [name, visibility, departments] (Settings > ไฟล์และโฟลเดอร์)
        'department_permissions' => [],       // Settings > สิทธิ์การใช้งาน: department id | 'none' => permission => bool (missing = App\Support\Access defaults)
        'file_cabinet_folders' => 'work',     // 'work': a department sees the folders of the cabinets it has work in; 'all': every cabinet's
    ];

    private static ?array $memo = null;

    public static function get(string $key): mixed
    {
        self::$memo ??= self::query()->pluck('value', 'key')->all();

        return array_key_exists($key, self::$memo) ? self::$memo[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::DEFAULTS), 500, "unknown setting {$key}");

        self::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memo = null;
    }

    public static function flush(): void
    {
        self::$memo = null;
    }
}
