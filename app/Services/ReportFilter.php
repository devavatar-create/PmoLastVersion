<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a report is limited to (the CEO report's "เลือก…"): some projects (SO), some cabinets (MO), some departments,
 * a hand-over period.
 * Empty = everything. ExecutiveReport and ProductionReport apply the same filter, so their numbers keep agreeing:
 *  - projects + cabinets: the whole of these SO plus these single MO (either one: ticking SO-A whole and two cabinets
 *                 of SO-B gives SO-A's cabinets and those two);
 *  - departments: only the projects / cabinets these departments have work in, and only their late work counts;
 *  - period:      only the cabinets due in it (a cabinet without its own date goes by its project's).
 */
final class ReportFilter
{
    public const PERIODS = ['all' => 'ทุกช่วง', 'next30' => '30 วันข้างหน้า', 'month' => 'เดือนนี้', 'quarter' => 'ไตรมาสนี้', 'custom' => 'กำหนดเอง'];

    /** @param list<int> $projects @param list<int> $departments @param list<int> $cabinetIds */
    public function __construct(
        public readonly array $projects = [],
        public readonly array $departments = [],
        public readonly string $period = 'all',
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly array $cabinetIds = [],
    ) {}

    public static function fromRequest(Request $request, ?Carbon $today = null): self
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $ids = fn (string $key) => collect((array) $request->query($key, []))->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'all';
        $date = function (string $key) use ($request) {
            try {
                return $request->filled($key) ? Carbon::createFromFormat('Y-m-d', (string) $request->query($key))->startOfDay() : null;
            } catch (\Throwable) {
                return null;
            }
        };
        [$from, $to] = match ($period) {
            'next30' => [$today->copy(), $today->copy()->addDays(30)],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
            'quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter()],
            'custom' => [$date('from'), $date('to')],
            default => [null, null],
        };
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($period === 'custom' && ! $from && ! $to) {
            $period = 'all';
        }

        return new self($ids('projects'), $ids('departments'), $period, $from, $to, $ids('cabinets'));
    }

    public function isEmpty(): bool
    {
        return ! $this->projects && ! $this->cabinetIds && ! $this->departments && ! $this->from && ! $this->to;
    }

    public function hasPeriod(): bool
    {
        return (bool) ($this->from || $this->to);
    }

    /** Limit a project query. */
    public function projects(Builder $q): Builder
    {
        return $q->when($this->projects || $this->cabinetIds, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('projects.id', $this->projects ?: [0])
                ->orWhereHas('cabinets', fn ($c) => $c->whereIn('cabinets.id', $this->cabinetIds ?: [0]))))
            ->when($this->departments, fn ($q) => $q->whereHas('cabinets.tasks.subtasks', fn ($s) => $s->whereIn('department_id', $this->departments)))
            ->when($this->hasPeriod(), fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('cabinets', fn ($c) => $this->dueIn($c, 'cabinets.due_date'))
                ->orWhere(fn ($q) => $this->dueIn($q, 'projects.due_date')->whereHas('cabinets', fn ($c) => $c->whereNull('cabinets.due_date')))));
    }

    /** Limit a project's cabinets (an eager-load constraint). */
    public function cabinets($q, ?Carbon $projectDue = null)
    {
        return $q->when($this->cabinetIds, fn ($q) => $q->where(fn ($q) => $q   // the cabinets chosen, or any of a whole SO chosen
                ->whereIn('cabinets.id', $this->cabinetIds)->orWhereIn('cabinets.project_id', $this->projects ?: [0])))
            ->when($this->departments, fn ($q) => $q->whereHas('tasks.subtasks', fn ($s) => $s->whereIn('department_id', $this->departments)))
            ->when($this->hasPeriod(), fn ($q) => $q->where(fn ($q) => $this->dueIn($q, 'cabinets.due_date')
                ->orWhere(fn ($q) => $q->whereNull('cabinets.due_date')->whereHas('project', fn ($p) => $this->dueIn($p, 'projects.due_date')))));
    }

    /** Does this department's work count (late work, who to chase, the department tables)? */
    public function countsDepartment(?int $id): bool
    {
        return ! $this->departments || in_array((int) $id, $this->departments, true);
    }

    private function dueIn($q, string $column)
    {
        return $q->when($this->from, fn ($q) => $q->whereDate($column, '>=', $this->from->format('Y-m-d')))
            ->when($this->to, fn ($q) => $q->whereDate($column, '<=', $this->to->format('Y-m-d')));
    }

    /** One line for the printed page: what this report covers. */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'ทุกโครงการที่ยังไม่ปิด · ทุกแผนก · ทุกช่วงเวลา';
        }
        $parts = [];
        if ($this->projects) {
            $nos = Project::whereIn('id', $this->projects)->orderBy('project_no')->pluck('project_no');
            $parts[] = 'เฉพาะ '.($nos->count() > 4 ? $nos->take(4)->implode(', ').' และอีก '.($nos->count() - 4).' SO' : $nos->implode(', '));
        }
        if ($this->cabinetIds) {
            $mos = \App\Models\Cabinet::whereIn('id', $this->cabinetIds)->orderBy('mo_no')->pluck('mo_no');
            $parts[] = 'ตู้ '.($mos->count() > 4 ? $mos->take(4)->implode(', ').' และอีก '.($mos->count() - 4).' ตู้' : $mos->implode(', '));
        }
        if ($this->departments) {
            $parts[] = 'แผนก '.Department::whereIn('id', $this->departments)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($this->hasPeriod()) {
            $d = fn (?Carbon $c) => $c ? $c->format('d/m/').($c->year + 543) : '…';
            $parts[] = 'กำหนดส่ง '.($this->period !== 'custom' ? self::PERIODS[$this->period].' ' : '').'('.$d($this->from).' – '.$d($this->to).')';
        }

        return implode(' · ', $parts);
    }
}
