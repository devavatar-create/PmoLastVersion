<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\GuardsClosedProject;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CabinetSubtask extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, CascadesSoftDeletes, GuardsClosedProject;

    const STATUS_NOT_STARTED = 'not_started';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_COMPLETED = 'completed';

    const STATUS_ON_HOLD = 'on_hold';

    public static array $statuses = [
        self::STATUS_NOT_STARTED => 'Not Started',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_ON_HOLD => 'On Hold',
    ];

    const ASSIGNMENT_UNASSIGNED = 'UNASSIGNED';

    const ASSIGNMENT_ASSIGNED = 'ASSIGNED';

    const ASSIGNMENT_ACCEPTED = 'ACCEPTED';

    const ASSIGNMENT_IN_PROGRESS = 'IN_PROGRESS';

    const ASSIGNMENT_COMPLETED = 'COMPLETED';

    public static array $assignmentStatuses = [
        self::ASSIGNMENT_UNASSIGNED => 'ยังไม่จ่ายงาน',
        self::ASSIGNMENT_ASSIGNED => 'รอรับงาน',
        self::ASSIGNMENT_ACCEPTED => 'รับงานแล้ว',
        self::ASSIGNMENT_IN_PROGRESS => 'กำลังดำเนินงาน',
        self::ASSIGNMENT_COMPLETED => 'เสร็จงานแล้ว',
    ];

    /** Statuses in which the assigned department may still be changed (i.e. before the department accepts). */
    public const DEPARTMENT_EDITABLE_STATUSES = [
        self::ASSIGNMENT_UNASSIGNED,
        self::ASSIGNMENT_ASSIGNED,
    ];

    /** Statuses in which the assigned department may manage this Sub Task's checklist. */
    public const CHECKLIST_EDITABLE_STATUSES = [
        self::ASSIGNMENT_ACCEPTED,
        self::ASSIGNMENT_IN_PROGRESS,
    ];

    protected $fillable = [
        'cabinet_task_id',
        'cabinet_subtask_template_id',
        'name',
        'description',
        'department_id',
        'assignment_status',
        'accepted_by',
        'assigned_at',
        'accepted_at',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'owner_id',
        'status',
        'progress',
        'start_date',
        'due_date',
        'completed_date',
        'remark',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            // Without this, some servers' PDO/MySQL driver settings return
            // this column as a string ("6") while Eloquent always auto-casts
            // a model's own primary key to int - so a strict `===` comparison
            // between the two (e.g. Blade's @selected() on the department
            // dropdown, or changeDepartment()'s "already this department"
            // check) silently always fails, even though the values agree.
            // Confirmed as the actual cause of "department resets to
            // unassigned after a page reload" on production (2026-09-30).
            'department_id' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_date' => 'date',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function cabinetTask()
    {
        return $this->belongsTo(CabinetTask::class);
    }

    public function subtaskTemplate()
    {
        return $this->belongsTo(CabinetSubtaskTemplate::class, 'cabinet_subtask_template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The department can only be changed until the department accepts the
     * work - from ACCEPTED onwards it is locked.
     */
    public function getIsDepartmentLockedAttribute(): bool
    {
        return ! in_array($this->assignment_status, self::DEPARTMENT_EDITABLE_STATUSES, true);
    }

    public function getAssignmentStatusLabelAttribute(): string
    {
        return self::$assignmentStatuses[$this->assignment_status] ?? (string) $this->assignment_status;
    }

    /**
     * Only the department this Sub Task is assigned to may add/tick/remove
     * its checklist items, and only once that department has accepted the
     * work (still ASSIGNED = not accepted yet, so nothing to do yet) -
     * except Admin/PM, who may manage any Sub Task's checklist regardless of
     * department or workflow stage (same administrative-oversight precedent
     * as SubtaskAssignmentService::changeDepartment(), which is likewise
     * gated by canDispatchWork()/canViewOtherDepartments() rather than an
     * exact department match).
     * Single source of truth for this rule - used both to expose the
     * `checklist_editable` flag to the frontend (My Department) and to
     * enforce it server-side (CabinetChecklistController).
     */
    public function isChecklistEditableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->canViewOtherDepartments()) {
            return true;
        }

        return $this->department_id !== null
            && $user->department_id === $this->department_id
            && in_array($this->assignment_status, self::CHECKLIST_EDITABLE_STATUSES, true);
    }

    /**
     * Initial assignment workflow columns for a freshly created sub task
     * (template apply / cabinet copy): ASSIGNED when a department is given,
     * otherwise UNASSIGNED, with the accept/start/complete trail cleared.
     */
    public static function initialAssignment(?int $departmentId): array
    {
        return [
            'department_id' => $departmentId,
            'assignment_status' => $departmentId ? self::ASSIGNMENT_ASSIGNED : self::ASSIGNMENT_UNASSIGNED,
            'assigned_at' => $departmentId ? now() : null,
            'accepted_by' => null,
            'accepted_at' => null,
            'started_by' => null,
            'started_at' => null,
            'completed_by' => null,
            'completed_at' => null,
        ];
    }

    public function checklists()
    {
        return $this->hasMany(CabinetChecklist::class)->orderBy('sequence');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isLateOn();
    }

    /**
     * Calendar days left until due_date (negative = overdue by that many days).
     */
    public function getDaysRemainingAttribute(): ?int
    {
    /**
     * Finished: its checklist is complete, or its department pressed "เสร็จงาน". The one rule every count uses (late work,
     * done counts, reminders, Planning), so the dashboard, the project page and Planning agree (PRODUCT.md "one number").
     */
    public function isDone(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->assignment_status === self::ASSIGNMENT_COMPLETED;
    }

    /** Not done and due before $today: the due day itself is not late yet. */
    public function isLateOn(?\Illuminate\Support\Carbon $today = null): bool
    {
        return $this->due_date !== null && ! $this->isDone() && $this->due_date->lt(($today ?? today())->copy()->startOfDay());
    }

    /** The query form of ! isDone(). */
    public function scopeNotDone($query)
    {
        return $query->where('status', '!=', self::STATUS_COMPLETED)->where('assignment_status', '!=', self::ASSIGNMENT_COMPLETED);
    }

        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    protected function activityProjectId(): ?int
    {
        return $this->cabinetTask?->cabinet?->project_id;
    }

    protected function activityLabel(): string
    {
        return "งานย่อย \"{$this->name}\"";
    }

    /**
     * progress/status intentionally excluded - see Cabinet::activityLoggableFields().
     */
    protected function activityLoggableFields(): array
    {
        return [
            'name' => 'ชื่องานย่อย',
            'department_id' => 'แผนก',
            'owner_id' => 'ผู้รับผิดชอบ',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'remark' => 'หมายเหตุ',
        ];
    }

    protected function activityFormatValue(string $field, $value): string
    {
        if ($field === 'department_id' && $value) {
            return Department::find($value)?->name ?? "#{$value}";
        }

        if ($field === 'owner_id' && $value) {
            return User::find($value)?->name ?? "#{$value}";
        }

        return $this->activityDefaultFormatValue($value);
    }
}
