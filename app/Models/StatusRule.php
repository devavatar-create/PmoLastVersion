<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "When <conditions> -> set the Planning status of the cabinet / project" (Settings > กฎสถานะ), the rich rules of the original
 * Planning page. Applied by StatusRuleService after a checklist tick or a workflow step; a rule only ever SETS a status
 * (when its conditions stop holding nothing is undone), and never overrides a status listed as locked in Settings.
 */
class StatusRule extends Model
{
    public const TARGETS = ['cabinet' => 'ตู้', 'project' => 'โครงการ'];

    public const WHEN = [
        'all_done' => 'เช็คลิสต์ครบทุกแผนก',
        'dept_done' => 'เช็คลิสต์ของแผนก … ครบ',
        'dept_started' => 'แผนก … เริ่มติ๊กเช็คลิสต์',
        'item' => 'ติ๊กรายการ … ของแผนก …',
        'status' => 'สถานะตอนนี้เป็น …',
        'not_status' => 'สถานะตอนนี้ไม่ใช่ …',
    ];

    protected $fillable = ['target', 'logic', 'conditions', 'board_status_id', 'is_active'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'is_active' => 'boolean'];
    }

    public function boardStatus()
    {
        return $this->belongsTo(BoardStatus::class);
    }
}
