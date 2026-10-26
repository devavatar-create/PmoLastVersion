<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\GuardsClosedProject;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CabinetChecklist extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, CascadesSoftDeletes, GuardsClosedProject;

    protected $fillable = [
        'cabinet_subtask_id',
        'checklist_template_id',
        'name',
        'description',
        'is_completed',
        'completed_by',
        'completed_at',
        'remark',
        'expected_date',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'expected_date' => 'date',
        ];
    }

    public function subtask()
    {
        return $this->belongsTo(CabinetSubtask::class, 'cabinet_subtask_id');
    }

    public function checklistTemplate()
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** The item of the Sub Task this one compares with that this item was made from (App\Services\SharedChecklist). */
    public function sourceItem()
    {
        return $this->belongsTo(self::class, 'source_checklist_id');
    }

    /** The items made from this one in the Sub Tasks comparing with its own. */
    public function copies()
    {
        return $this->hasMany(self::class, 'source_checklist_id');
    }

    protected function activityProjectId(): ?int
    {
        return $this->subtask?->cabinetTask?->cabinet?->project_id;
    }

    protected function activityLabel(): string
    {
        return "Checklist \"{$this->name}\"";
    }

    /**
     * is_completed toggles are logged explicitly by ProgressService::toggleChecklist
     * with a friendlier message, so that field is deliberately left out here
     * to avoid double-logging every check/uncheck.
     */
    protected function activityLoggableFields(): array
    {
        return [
            'remark' => 'หมายเหตุ',
            'expected_date' => 'วันที่ (ส่งต่อแผนก)',
        ];
    }
}
