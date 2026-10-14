<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\StatusRule;
use Illuminate\Support\Collection;

/**
 * Applies Settings > กฎสถานะ to one project: for each active rule (in order) whose conditions hold, the Planning status of the
 * project or of each of its cabinets is set. Nothing is ever reverted, and a status listed in "สถานะที่ห้ามเปลี่ยนอัตโนมัติ" is
 * left alone. Only the Planning status (board_status_id) is touched - PMO's own workflow and project status are not.
 */
class StatusRuleService
{
    public function applyFor(?Project $project): void
    {
        if (! $project) {
            return;
        }
        $rules = StatusRule::where('is_active', true)->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return;
        }
        $locked = array_map('intval', (array) AppSetting::get('status_rules_locked'));

        $rows = CabinetSubtask::query()
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->with('checklists', 'cabinetTask:id,cabinet_id')
            ->get();

        foreach ($rules as $rule) {
            $targets = $rule->target === 'project'
                ? collect([[$project, $rows]])
                : $project->cabinets()->get()->map(fn ($c) => [$c, $rows->filter(fn ($s) => $s->cabinetTask->cabinet_id === $c->id)]);

            foreach ($targets as [$model, $targetRows]) {
                $current = $model->board_status_id;
                if ($current === $rule->board_status_id || in_array((int) $current, $locked, true)) {
                    continue;
                }
                if ($this->matches($rule, $targetRows, $current)) {
                    $model->forceFill(['board_status_id' => $rule->board_status_id])->saveQuietly();
                }
            }
        }
    }

    public function matches(StatusRule $rule, Collection $rows, ?int $currentStatusId): bool
    {
        $results = collect($rule->conditions)->map(fn (array $c) => $this->holds($c, $rows, $currentStatusId));

        return $results->isNotEmpty() && ($rule->logic === 'or' ? $results->contains(true) : ! $results->contains(false));
    }

    private function holds(array $c, Collection $rows, ?int $current): bool
    {
        $items = fn (?int $dept) => $rows->when($dept, fn ($r) => $r->where('department_id', $dept))->flatMap->checklists;
        $dept = isset($c['department_id']) ? (int) $c['department_id'] : null;

        return match ($c['when'] ?? null) {
            'all_done' => ($all = $items(null))->isNotEmpty() && $all->every('is_completed'),
            'dept_done' => ($d = $items($dept))->isNotEmpty() && $d->every('is_completed'),
            'dept_started' => $items($dept)->contains('is_completed', true),
            'item' => $items($dept)->contains(fn ($i) => $i->is_completed && trim($i->name) === trim((string) ($c['item'] ?? ''))),
            'status' => $current !== null && $current === (int) ($c['status_id'] ?? 0),
            'not_status' => $current !== (int) ($c['status_id'] ?? 0),
            default => false,
        };
    }
}
