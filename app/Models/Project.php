<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\HasAuditFields;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\ProjectLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Project extends Model
{
    use HasFactory, HasAuditFields, LogsActivity, SoftDeletes, CascadesSoftDeletes;

    /** Statuses after which the project and everything under it is read-only (see ProjectLock). */
    public const LOCKED_STATUSES = ['completed', 'cancelled'];

    const STATUS_DRAFT = 'draft';

    const STATUS_PLANNING = 'planning';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_ON_HOLD = 'on_hold';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    public static array $statuses = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PLANNING => 'Planning',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_ON_HOLD => 'On Hold',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    const PRIORITY_LOW = 'low';

    const PRIORITY_NORMAL = 'normal';

    const PRIORITY_HIGH = 'high';

    const PRIORITY_URGENT = 'urgent';

    public static array $priorities = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_URGENT => 'Urgent',
    ];

    protected $fillable = [
        'project_no',
        'project_name',
        'customer_name',
        'customer_phone',
        'po_no',
        'sales_order_no',
        'owner_id',
        'sales_person_id',
        'sales_person',
        'project_owner',
        'project_manager_id',
        'start_date',
        'due_date',
        'description',
        'remark',
        'payment_terms',
        'contract_value',
        'margin',
        'job_type_id',
        'status',
        'priority',
        'color',
        'board_id',
        'board_status_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'contract_value' => 'decimal:2',
            'margin' => 'decimal:2',
        ];
    }

    /** Thai VAT on the contract value (มูลค่างาน is before VAT). */
    public const VAT_RATE = 0.07;

    public function vatAmount(): ?float
    {
        return $this->contract_value !== null ? round((float) $this->contract_value * self::VAT_RATE, 2) : null;
    }

    /** มูลค่างานรวม VAT 7% - worked out, never stored (so it can never disagree with มูลค่างาน). */
    public function contractValueWithVat(): ?float
    {
        return $this->contract_value !== null ? round((float) $this->contract_value + $this->vatAmount(), 2) : null;
    }

    /** Margin as a share of the contract value (null when either is missing / the value is 0), e.g. 18.5. */
    public function marginPercent(): ?float
    {
        return $this->margin !== null && (float) $this->contract_value > 0 ? round((float) $this->margin / (float) $this->contract_value * 100, 1) : null;
    }

    /**
     * An amount typed by a person ("1,250,000", "1 250 000.50", "฿ 3,000") as a plain number string; '' / null stay null.
     * Anything else is passed through unchanged so validation (numeric) rejects it with a message.
     */
    public static function cleanAmount(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $plain = is_string($value) ? preg_replace('/[\s,฿]|บาท/u', '', $value) : $value;

        return is_numeric($plain) ? $plain : $value;
    }

    /** Who may be a project's Project Manager: the PMO roles (admin / project manager). */
    public const MANAGER_ROLES = [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER];

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function boardStatus()
    {
        return $this->belongsTo(BoardStatus::class);
    }

    public function boardLabels()
    {
        return $this->belongsToMany(BoardLabel::class);
    }

    /** People in charge (on top of the departments that hold the work) - set on the Planning page. */
    public function assignees()
    {
        return $this->belongsToMany(User::class);
    }

    public function jobType()
    {
        return $this->belongsTo(JobType::class);
    }

    public function projectTasks()
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sequence');
    }

    /** Only the projects this person may see (App\Services\WorkScope - the same rule as opening one). */
    public function scopeVisibleTo($query, User $user)
    {
        return \App\Services\WorkScope::visibleProjects($query, $user);
    }

    public function cabinets()
    {
        return $this->hasMany(Cabinet::class)->orderBy('sequence');
    }

    public function getTaskProgressAttribute(): int
    {
        if (array_key_exists('project_tasks_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['project_tasks_avg_progress'] ?? 0);
        }

        return (int) round($this->projectTasks()->avg('progress') ?? 0);
    }

    public function getProductionProgressAttribute(): int
    {
        if (array_key_exists('cabinets_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['cabinets_avg_progress'] ?? 0);
        }

        return (int) round($this->cabinets()->avg('progress') ?? 0);
    }

    public function getCabinetSummaryAttribute(): array
    {
        $cabinets = $this->relationLoaded('cabinets') ? $this->cabinets : $this->cabinets()->get(['status']);

        return [
            'total' => $cabinets->count(),
            'completed' => $cabinets->where('status', 'completed')->count(),
            'in_progress' => $cabinets->where('status', 'in_progress')->count(),
            'not_started' => $cabinets->where('status', 'not_started')->count(),
            'on_hold' => $cabinets->where('status', 'on_hold')->count(),
        ];
    }

    public function getRiskLevelAttribute(): string
    {
        if ($this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELLED) {
            return 'none';
        }

        if (! $this->due_date) {
            return 'none';
        }

        $today = now()->startOfDay();

        if ($today->greaterThan($this->due_date) && $this->production_progress < 100) {
            return 'overdue';
        }

        $daysRemaining = $this->days_remaining;

        if ($daysRemaining <= 7 && $this->production_progress < 80) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * Calendar days left until due_date (negative = overdue by that many days).
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    protected static function booted(): void
    {
        // A closed project can only be edited to re-open it (status change). Colour is a
        // per-user calendar preference, not project data, so it stays changeable.
        static::updating(function (self $project) {
            $changed = array_diff(array_keys($project->getDirty()), ['color', 'updated_by', 'updated_at']);

            if ($changed && in_array($project->getOriginal('status'), self::LOCKED_STATUSES, true) && ! $project->isDirty('status')) {
                ProjectLock::assertOpen($project->getKey());
            }
        });

        // Settings > กฎการทำงาน: "โครงการกดเสร็จไม่ได้ ถ้ายังมีงานที่แผนกยังไม่เสร็จ"
        static::updating(function (self $project) {
            if ($project->isDirty('status') && $project->status === 'completed' && AppSetting::get('block_project_done_needs_work')
                && CabinetSubtask::query()
                    ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->getKey()))
                    ->whereNotNull('department_id')
                    ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
                    ->exists()) {
                throw ValidationException::withMessages(['status' => 'ปิดโครงการไม่ได้ — ยังมีงานของแผนกที่ไม่เสร็จ (ตั้งค่าไว้ที่ ตั้งค่า › กฎการทำงาน)']);
            }
        });

        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn (self $project) => ProjectLock::forget($project->getKey()));
        }
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    protected function activityProjectId(): ?int
    {
        return $this->id;
    }

    protected function activityLabel(): string
    {
        return "โครงการ {$this->project_no}";
    }

    protected function activityLoggableFields(): array
    {
        return [
            'project_name' => 'ชื่อโครงการ',
            'customer_name' => 'ลูกค้า',
            'customer_phone' => 'เบอร์ติดต่อ',
            'contract_value' => 'มูลค่างาน',
            'margin' => 'Margin',
            'status' => 'สถานะ',
            'priority' => 'ความสำคัญ',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'project_owner' => 'เจ้าของโครงการ',
            'sales_person' => 'พนักงานขาย',
            'project_manager_id' => 'ผู้จัดการโครงการ',
            'payment_terms' => 'เงื่อนไขการชำระ',
            'job_type_id' => 'ประเภทงาน',
        ];
    }

    protected function activityFormatValue(string $field, $value): string
    {
        if ($field === 'status' && $value) {
            return self::$statuses[$value] ?? $value;
        }

        if ($field === 'priority' && $value) {
            return self::$priorities[$value] ?? $value;
        }

        if ($field === 'project_manager_id' && $value) {
            return User::find($value)?->name ?? "#{$value}";
        }

        if ($field === 'job_type_id' && $value) {
            return JobType::find($value)?->name ?? "#{$value}";
        }

        return $this->activityDefaultFormatValue($value);
    }
}
