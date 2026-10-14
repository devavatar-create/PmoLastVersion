<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;
use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\GuardsClosedProject;
use App\Models\Concerns\LogsActivity;
use App\Services\WorkingDaysCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cabinet extends Model
{
    use HasFactory, HasAuditFields, LogsActivity, SoftDeletes, CascadesSoftDeletes, GuardsClosedProject;

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

    protected $fillable = [
        'project_id',
        'cabinet_template_id',
        'mo_no',
        'cabinet_name',
        'cabinet_type',
        'size',
        'quantity',
        'description',
        'start_date',
        'due_date',
        'expected_completion_date',
        'status',
        'progress',
        'weight',
        'remark',
        'sequence',
        'board_status_id',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'expected_completion_date' => 'date',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function cabinetTemplate()
    {
        return $this->belongsTo(CabinetTemplate::class);
    }

    public function tasks()
    {
        return $this->hasMany(CabinetTask::class)->orderBy('sequence');
    }

    public function attachments()
    {
        return $this->hasMany(CabinetAttachment::class)->latest();
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && $this->status !== self::STATUS_COMPLETED;
    }

    /**
     * Total working days (Mon-Fri) spanned by the cabinet's own schedule -
     * the pool that each task's weight % is a share of.
     */
    public function getTotalWorkingDaysAttribute(): ?int
    {
        if (! $this->start_date || ! $this->due_date) {
            return null;
        }

        return app(WorkingDaysCalculator::class)->countWorkingDays($this->start_date, $this->due_date);
    }

    /**
     * Calendar days left until the cabinet's expected completion date
     * (negative = overdue by that many days) - falls back to due_date when
     * no one has estimated a completion date for this cabinet yet.
     */
    public function getDaysRemainingAttribute(): ?int
    {
        $target = $this->expected_completion_date ?? $this->due_date;

        if (! $target) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($target, false);
    }

    /**
     * Total / completed checklist counts across every task and sub task in this cabinet.
     */
    public function getChecklistCountsAttribute(): array
    {
        $total = 0;
        $completed = 0;

        foreach ($this->tasks as $task) {
            $counts = $task->checklist_counts;
            $total += $counts['total'];
            $completed += $counts['completed'];
        }

        return ['completed' => $completed, 'total' => $total];
    }

    protected function activityProjectId(): ?int
    {
        return $this->project_id;
    }

    protected function activityLabel(): string
    {
        return "ตู้ {$this->mo_no}";
    }

    /**
     * progress/status intentionally excluded - ProgressService recalculates
     * both on every checklist toggle, which would otherwise spam the
     * timeline on top of the checklist's own explicit log entry.
     */
    protected function activityLoggableFields(): array
    {
        return [
            'cabinet_name' => 'ชื่อตู้',
            'cabinet_type' => 'ประเภทตู้',
            'size' => 'ขนาดตู้',
            'quantity' => 'จำนวน',
            'weight' => 'น้ำหนัก',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'expected_completion_date' => 'วันคาดว่าจะเสร็จ',
        ];
    }

    protected function activityFormatValue(string $field, $value): string
    {
        if ($field === 'weight' && $value !== null) {
            return rtrim(rtrim(number_format((float) $value, 2), '0'), '.').'%';
        }

        return $this->activityDefaultFormatValue($value);
    }

    /** A Planning status picked by hand for this cabinet (null = worked out from its departments' progress). */
    public function boardStatus()
    {
        return $this->belongsTo(BoardStatus::class);
    }
}
