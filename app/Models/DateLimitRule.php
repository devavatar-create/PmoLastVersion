<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DateLimitRule extends Model
{
    protected $fillable = ['anchor_department_id', 'blocked_department_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function anchorDepartment()
    {
        return $this->belongsTo(Department::class, 'anchor_department_id');
    }

    public function blockedDepartment()
    {
        return $this->belongsTo(Department::class, 'blocked_department_id');
    }

    /**
     * If giving a Sub Task of $departmentId (in cabinet $cabinetId) the due date $dueDate would break an active rule, a Thai message saying why;
     * otherwise null. The anchor's date is the LATEST due date any anchor-department Sub Task has in the
     * same cabinet - no anchor work (or none dated) means nothing to be later than, so no limit yet.
     */
    public static function violationFor(int $cabinetId, ?int $departmentId, string $dueDate): ?string
    {
        if (! $departmentId) {
            return null;
        }

        $rules = static::query()->where('is_active', true)
            ->where('blocked_department_id', $departmentId)
            ->with('anchorDepartment')
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        foreach ($rules as $rule) {
            $anchorDue = CabinetSubtask::query()
                ->where('department_id', $rule->anchor_department_id)
                ->whereNotNull('due_date')
                ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinetId))
                ->max('due_date');

            if ($anchorDue && $dueDate > substr((string) $anchorDue, 0, 10)) {
                return 'กำหนดส่งต้องไม่เกินแผนก '.$rule->anchorDepartment->name.' ('.\Illuminate\Support\Carbon::parse($anchorDue)->thaiDate().')';
            }
        }

        return null;
    }
}
