<?php

namespace App\Services;

use App\Exceptions\ProjectLockedException;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * "Closed project = read-only". A project is closed once its status is
 * Completed or Cancelled; moving the status back re-opens it. Comments stay
 * allowed (they're discussion, not work data).
 *
 * Enforced at the model layer (GuardsClosedProject) so no controller can forget
 * it, plus explicitly where saves bypass model events (SubtaskAssignmentService
 * uses saveQuietly()). Status lookups are memoised per request: applying a template
 * creates hundreds of rows and each one asks.
 */
class ProjectLock
{
    private static array $locked = [];

    public static function isLocked(?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        return self::$locked[$projectId] ??= in_array(
            DB::table('projects')->where('id', $projectId)->value('status'),
            Project::LOCKED_STATUSES,
            true
        );
    }

    public static function assertOpen(?int $projectId): void
    {
        if (self::isLocked($projectId)) {
            $no = DB::table('projects')->where('id', $projectId)->value('project_no');

            throw new ProjectLockedException("โครงการ {$no} ปิดแล้ว (Completed/Cancelled) จึงแก้ไขข้อมูลไม่ได้ — เปลี่ยนสถานะโครงการกลับก่อนหากต้องการแก้ไข");
        }
    }

    public static function forget(?int $projectId = null): void
    {
        if ($projectId === null) {
            self::$locked = [];
        } else {
            unset(self::$locked[$projectId]);
        }
    }
}
