<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Collection;

/**
 * Presentation-only grouping of the existing activity_logs rows into
 * Cabinet > Task > Sub Task > Checklist > Activity. Nothing is written and no
 * hierarchy is stored: each row's loggable_type/loggable_id is resolved upward
 * through the live tables with one batched query per level (no N+1).
 *
 * Rows whose entity has since been hard-deleted can't be walked upward (the
 * app has no soft deletes), so they land in an "อื่น ๆ / รายการที่ถูกลบแล้ว"
 * section instead of being dropped - name recovered from the logged text.
 */
class ActivityTreeBuilder
{
    /** Filter value => actions it matches (null = decided by entity type). */
    private const CATEGORIES = [
        'create' => ['created', 'copied_from_cabinet', 'template_applied'],
        'edit' => ['updated', 'extended'],
        'status' => ['accepted', 'started', 'completed'],
        'assignment' => ['assigned', 'department_changed', 'accepted', 'started', 'completed'],
        'checklist' => ['checked', 'unchecked', 'checklists_copied'],
        'attachment' => ['attachment_uploaded', 'attachment_deleted'],
        'delete' => ['deleted', 'attachment_deleted'],
    ];

    private const ACTION_LABELS = [
        'created' => 'สร้าง',
        'updated' => 'แก้ไขรายละเอียด',
        'extended' => 'ขยายกำหนดเวลา',
        'deleted' => 'ลบ',
        'restored' => 'กู้คืน',
        'remark_migrated' => 'หมายเหตุเดิม (ย้ายมาจากช่องหมายเหตุ)',
        'checked' => 'ทำเครื่องหมายเสร็จ',
        'unchecked' => 'ยกเลิกเครื่องหมายเสร็จ',
        'checklists_copied' => 'คัดลอก Checklist',
        'assigned' => 'มอบหมายงานให้แผนก',
        'department_changed' => 'เปลี่ยนแผนกที่รับผิดชอบ',
        'accepted' => 'แผนกรับงาน',
        'started' => 'เริ่มงาน',
        'completed' => 'เสร็จงาน',
        'attachment_uploaded' => 'อัปโหลดไฟล์แนบ',
        'attachment_deleted' => 'ลบไฟล์แนบ',
        'template_applied' => 'สร้างโครงสร้างงานจากเทมเพลต',
        'copied_from_cabinet' => 'คัดลอกจากตู้อื่น',
    ];

    private const ACTION_ICONS = [
        'created' => 'plus', 'copied_from_cabinet' => 'plus', 'template_applied' => 'plus',
        'updated' => 'edit', 'extended' => 'edit', 'restored' => 'check',
        'checked' => 'check', 'completed' => 'check',
        'unchecked' => 'circle',
        'assigned' => 'arrow', 'department_changed' => 'arrow', 'accepted' => 'arrow',
        'started' => 'play',
        'checklists_copied' => 'copy',
        'attachment_uploaded' => 'clip', 'attachment_deleted' => 'clip',
        'deleted' => 'trash',
    ];

    private const ENTITY_TYPES = [
        'project' => [Project::class, ProjectTask::class],
        'cabinet' => [Cabinet::class],
        'task' => [CabinetTask::class],
        'subtask' => [CabinetSubtask::class],
        'checklist' => [CabinetChecklist::class],
    ];

    /**
     * @param  array{from?:?string,to?:?string,category?:?string,user_id?:?int,entity?:?string,q?:?string,sort?:?string}  $filters
     */
    public function build(Project $project, ?Cabinet $cabinet, array $filters): array
    {
        $logs = $this->query($project, $cabinet, $filters)->with('user:id,name')->get();

        $ctx = $this->resolveEntities($logs);

        $root = ['children' => [], 'activities' => []];
        $orphans = ['children' => [], 'activities' => []];
        $projectLevel = ['activities' => []];

        foreach ($logs as $log) {
            $path = $this->path($log, $ctx, $project, $cabinet);
            $activity = $this->activity($log);

            if ($path === null) {
                $orphans = $this->place($orphans, [$this->orphanNode($log)], $activity);
            } elseif ($path === []) {
                $projectLevel['activities'][] = $activity;
            } else {
                $root = $this->place($root, $path, $activity);
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        $desc = ($filters['sort'] ?? 'desc') !== 'asc';

        $nodes = $this->finish($root['children'], $desc);
        $sections = [];

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $nodes = $this->search($nodes, $needle);
            $projectLevel['activities'] = array_values(array_filter($projectLevel['activities'], fn ($a) => str_contains($a['haystack'], $needle)));
        }

        if ($projectLevel['activities']) {
            $sections[] = $this->section('project-level', $cabinet ? 'ระดับตู้' : 'Project Changes', 'project', $projectLevel['activities'], $desc);
        }

        $orphanNodes = $this->finish($orphans['children'], $desc);
        $orphanNodes = $q !== '' ? $this->search($orphanNodes, mb_strtolower($q)) : $orphanNodes;

        if ($orphanNodes) {
            $sections[] = $this->section('orphans', 'อื่น ๆ / รายการที่ถูกลบแล้ว', 'other', [], $desc, $orphanNodes);
        }

        $nodes = array_merge($nodes, $sections);

        return [
            'nodes' => $nodes,
            // After search pruning, so it's the number of rows actually shown.
            'total' => array_sum(array_column($nodes, 'count')),
            'users' => $this->actors($project),
        ];
    }

    private function query(Project $project, ?Cabinet $cabinet, array $filters)
    {
        $query = ActivityLog::query()->where('project_id', $project->id)->where('action', '!=', 'note');

        if ($cabinet) {
            $taskIds = $cabinet->tasks()->withTrashed()->pluck('id');
            $subtaskIds = CabinetSubtask::withTrashed()->whereIn('cabinet_task_id', $taskIds)->pluck('id');
            $checklistIds = CabinetChecklist::withTrashed()->whereIn('cabinet_subtask_id', $subtaskIds)->pluck('id');

            $query->where(function ($q) use ($cabinet, $taskIds, $subtaskIds, $checklistIds) {
                $q->where(fn ($q) => $q->where('loggable_type', Cabinet::class)->where('loggable_id', $cabinet->id))
                    ->orWhere(fn ($q) => $q->where('loggable_type', CabinetTask::class)->whereIn('loggable_id', $taskIds))
                    ->orWhere(fn ($q) => $q->where('loggable_type', CabinetSubtask::class)->whereIn('loggable_id', $subtaskIds))
                    ->orWhere(fn ($q) => $q->where('loggable_type', CabinetChecklist::class)->whereIn('loggable_id', $checklistIds));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['category']) && isset(self::CATEGORIES[$filters['category']])) {
            $query->whereIn('action', self::CATEGORIES[$filters['category']]);
        }

        if (! empty($filters['entity']) && isset(self::ENTITY_TYPES[$filters['entity']])) {
            $query->whereIn('loggable_type', self::ENTITY_TYPES[$filters['entity']]);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * One whereIn per level, walking checklist -> subtask -> task -> cabinet.
     */
    private function resolveEntities(Collection $logs): array
    {
        $ids = fn (string $type) => $logs->where('loggable_type', $type)->pluck('loggable_id')->unique()->values();

        // Trashed rows are included on purpose: a deleted Sub Task/Checklist keeps its place
        // in the tree (labelled "ถูกลบ") instead of dropping to the orphan section.
        $checklists = CabinetChecklist::withTrashed()->whereIn('id', $ids(CabinetChecklist::class))->get(['id', 'name', 'cabinet_subtask_id', 'deleted_at'])->keyBy('id');
        $subtasks = CabinetSubtask::withTrashed()->whereIn('id', $ids(CabinetSubtask::class)->merge($checklists->pluck('cabinet_subtask_id'))->unique())
            ->get(['id', 'name', 'cabinet_task_id', 'deleted_at'])->keyBy('id');
        $tasks = CabinetTask::withTrashed()->whereIn('id', $ids(CabinetTask::class)->merge($subtasks->pluck('cabinet_task_id'))->unique())
            ->get(['id', 'name', 'cabinet_id', 'sequence', 'deleted_at'])->keyBy('id');
        $cabinets = Cabinet::withTrashed()->whereIn('id', $ids(Cabinet::class)->merge($tasks->pluck('cabinet_id'))->unique())
            ->get(['id', 'mo_no', 'cabinet_name', 'project_id', 'deleted_at'])->keyBy('id');
        $projectTasks = ProjectTask::whereIn('id', $ids(ProjectTask::class))->get(['id', 'name'])->keyBy('id');

        return compact('checklists', 'subtasks', 'tasks', 'cabinets', 'projectTasks');
    }

    /**
     * Ancestor chain (outermost first) for a log's entity.
     * [] = project-level, null = can't be resolved (entity deleted).
     * In Cabinet scope the Cabinet node itself is dropped: the page is already that cabinet.
     */
    private function path(ActivityLog $log, array $ctx, Project $project, ?Cabinet $scope): ?array
    {
        $gone = fn ($e) => $e->trashed() ? ' [ถูกลบ]' : '';
        $cabinetNode = fn ($c) => ['key' => 'cabinet-'.$c->id, 'type' => 'cabinet', 'label' => $c->mo_no.$gone($c)];
        $taskNode = fn ($t) => ['key' => 'task-'.$t->id, 'type' => 'task', 'label' => $t->name.$gone($t)];
        $subtaskNode = fn ($s) => ['key' => 'subtask-'.$s->id, 'type' => 'subtask', 'label' => $s->name.$gone($s)];

        $nodes = null;

        switch ($log->loggable_type) {
            case Project::class:
            case ProjectTask::class:
                return [];
            case Cabinet::class:
                $c = $ctx['cabinets'][$log->loggable_id] ?? null;
                $nodes = $c ? [$cabinetNode($c)] : null;
                break;
            case CabinetTask::class:
                $t = $ctx['tasks'][$log->loggable_id] ?? null;
                $c = $t ? ($ctx['cabinets'][$t->cabinet_id] ?? null) : null;
                $nodes = $c ? [$cabinetNode($c), $taskNode($t)] : null;
                break;
            case CabinetSubtask::class:
                $s = $ctx['subtasks'][$log->loggable_id] ?? null;
                $t = $s ? ($ctx['tasks'][$s->cabinet_task_id] ?? null) : null;
                $c = $t ? ($ctx['cabinets'][$t->cabinet_id] ?? null) : null;
                $nodes = $c ? [$cabinetNode($c), $taskNode($t), $subtaskNode($s)] : null;
                break;
            case CabinetChecklist::class:
                $k = $ctx['checklists'][$log->loggable_id] ?? null;
                $s = $k ? ($ctx['subtasks'][$k->cabinet_subtask_id] ?? null) : null;
                $t = $s ? ($ctx['tasks'][$s->cabinet_task_id] ?? null) : null;
                $c = $t ? ($ctx['cabinets'][$t->cabinet_id] ?? null) : null;
                $nodes = $c ? [$cabinetNode($c), $taskNode($t), $subtaskNode($s), ['key' => 'checklist-'.$k->id, 'type' => 'checklist', 'label' => $k->name.$gone($k)]] : null;
                break;
        }

        if ($nodes === null) {
            return null;
        }

        if ($scope) {
            array_shift($nodes);
        }

        return $nodes;
    }

    private function place(array $root, array $path, array $activity): array
    {
        $level = &$root;

        foreach ($path as $node) {
            $level['children'][$node['key']] ??= $node + ['children' => [], 'activities' => []];
            $level = &$level['children'][$node['key']];
        }

        $level['activities'][] = $activity;

        return $root;
    }

    private function orphanNode(ActivityLog $log): array
    {
        $type = match ($log->loggable_type) {
            Cabinet::class => 'cabinet', CabinetTask::class => 'task', CabinetSubtask::class => 'subtask', CabinetChecklist::class => 'checklist', default => 'other',
        };
        $word = ['cabinet' => 'Cabinet', 'task' => 'Task', 'subtask' => 'Sub Task', 'checklist' => 'Checklist', 'other' => 'รายการ'][$type];
        preg_match('/"([^"]+)"/u', $log->description, $m);

        return [
            'key' => 'gone-'.$type.'-'.$log->loggable_id,
            'type' => $type,
            'label' => ($m[1] ?? "#{$log->loggable_id}").' [ถูกลบ]',
            'word' => $word,
        ];
    }

    /**
     * Sort children (latest activity first, or Task sequence for stability when
     * ascending), sort activities, and roll counts/latest-time up each node.
     */
    private function finish(array $children, bool $desc): array
    {
        $out = [];

        foreach ($children as $node) {
            $node['children'] = $this->finish($node['children'], $desc);
            usort($node['activities'], fn ($a, $b) => $desc ? $b['ts'] <=> $a['ts'] : $a['ts'] <=> $b['ts']);

            $node['count'] = count($node['activities']) + array_sum(array_column($node['children'], 'count'));
            $node['latest'] = max(array_merge(array_column($node['activities'], 'ts'), array_column($node['children'], 'latest_ts')) ?: [0]);
            $node['latest_ts'] = $node['latest'];
            $node['latest'] = $node['latest'] ? \Illuminate\Support\Carbon::createFromTimestamp($node['latest'], config('app.timezone'))->thaiDateTime() : null;

            $out[] = $node;
        }

        usort($out, fn ($a, $b) => $desc ? $b['latest_ts'] <=> $a['latest_ts'] : $a['latest_ts'] <=> $b['latest_ts']);

        return $out;
    }

    /**
     * Keep a node if its own label matches (then everything under it stays) or
     * anything beneath it matched, so a hit always shows its ancestor path.
     */
    private function search(array $nodes, string $q, bool $ancestorHit = false): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            $hit = $ancestorHit || str_contains(mb_strtolower($node['label']), $q);
            $node['activities'] = array_values(array_filter($node['activities'], fn ($a) => $hit || str_contains($a['haystack'], $q)));
            $node['children'] = $this->search($node['children'], $q, $hit);

            if ($node['activities'] || $node['children']) {
                $node['count'] = count($node['activities']) + array_sum(array_column($node['children'], 'count'));
                $kept[] = $node;
            }
        }

        return $kept;
    }

    private function section(string $key, string $label, string $type, array $activities, bool $desc, ?array $children = null): array
    {
        usort($activities, fn ($a, $b) => $desc ? $b['ts'] <=> $a['ts'] : $a['ts'] <=> $b['ts']);
        $latest = max(array_merge(array_column($activities, 'ts'), array_column($children ?? [], 'latest_ts')) ?: [0]);
        $count = count($activities) + array_sum(array_column($children ?? [], 'count'));

        return [
            'key' => $key, 'type' => $type, 'label' => $label,
            'children' => $children ?? [], 'activities' => $activities,
            'count' => $count, 'latest_ts' => $latest, 'latest' => $latest ? \Illuminate\Support\Carbon::createFromTimestamp($latest, config('app.timezone'))->thaiDateTime() : null,
        ];
    }

    private function activity(ActivityLog $log): array
    {
        $changes = collect($log->changesFor(auth()->user()))   // money / phone values as ••• for whom Settings > สิทธิ์การใช้งาน hides them
            ->map(fn ($c) => ['label' => $c['label'] ?? '', 'old' => $c['old'] ?? '-', 'new' => $c['new'] ?? '-'])
            ->values()->all();
        $description = $log->descriptionFor(auth()->user());

        // The tree already says WHICH entity; only keep the sentence where it
        // carries information nothing else shows (counts, file names, deletions).
        $known = isset(self::ACTION_LABELS[$log->action]);
        $detail = (! $changes && (! $known || in_array($log->action, ['deleted', 'checklists_copied', 'attachment_uploaded', 'attachment_deleted', 'template_applied', 'copied_from_cabinet', 'accepted', 'started', 'completed'], true)))
            ? $description : null;

        $name = $log->user?->name;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'icon' => self::ACTION_ICONS[$log->action] ?? 'dot',
            'title' => self::ACTION_LABELS[$log->action] ?? $log->action,
            'at' => $log->created_at->thaiDateTime(),
            'ts' => $log->created_at->getTimestamp(),
            'actor' => $name ?? 'Unknown User',
            'initial' => $name ? mb_strtoupper(mb_substr($name, 0, 1)) : '?',
            'changes' => $changes,
            'detail' => $detail,
            'haystack' => mb_strtolower($description.' '.($name ?? '').' '.($log->action)),
        ];
    }

    private function actors(Project $project): array
    {
        return ActivityLog::where('project_id', $project->id)->where('action', '!=', 'note')
            ->whereNotNull('user_id')->with('user:id,name')->distinct()->get(['user_id'])
            ->map(fn ($l) => ['id' => $l->user_id, 'name' => $l->user?->name])
            ->filter(fn ($u) => $u['name'])->sortBy('name')->values()->all();
    }
}
