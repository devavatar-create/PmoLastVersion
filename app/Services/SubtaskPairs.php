<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which Sub Task compares its checklist with which (e.g. Production "ตรวจรับอุปกรณ์เข้า" ← จัดซื้อ "สั่งซื้ออุปกรณ์"): set on
 * the cabinet template, copied to each cabinet made or copied from it, and sent to the cabinets made earlier on request.
 * HandoffService reads the cabinets' pairs (the list next to it, the dates, the notices).
 */
class SubtaskPairs
{
    /** Set what a template Sub Task compares with: other Sub Tasks of the same template. @param list<int> $sourceIds */
    public function setTemplatePairs(CabinetSubtaskTemplate $sub, array $sourceIds): void
    {
        $sourceIds = collect($sourceIds)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $sub->id)->values();
        $templateId = $sub->taskTemplate->cabinet_template_id;
        $valid = CabinetSubtaskTemplate::whereIn('id', $sourceIds)->whereHas('taskTemplate', fn ($q) => $q->where('cabinet_template_id', $templateId))->pluck('id');
        if ($valid->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages(['sources' => 'เลือกได้เฉพาะ Sub Task ในเทมเพลตเดียวกัน']);
        }
        $sub->pairSources()->sync($valid->all());
        app(HandoffService::class)->forgetPairs();
    }

    /**
     * Change one cabinet's pairs (the cabinet page, Admin / PM): what this Sub Task compares with, other Sub Tasks of the same
     * cabinet. Only this cabinet; the template keeps its own. @param list<int> $sourceIds
     */
    public function setCabinetPairs(CabinetSubtask $sub, array $sourceIds, ?\App\Models\User $actor = null): void
    {
        $sub->loadMissing('cabinetTask.cabinet');
        $cabinet = $sub->cabinetTask->cabinet;
        ProjectLock::assertOpen($cabinet->project_id);   // the pivot write fires no model event
        $sourceIds = collect($sourceIds)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $sub->id)->values();
        $valid = CabinetSubtask::whereIn('id', $sourceIds)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->get(['id', 'name']);
        if ($valid->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages(['sources' => 'เลือกได้เฉพาะ Sub Task ในตู้เดียวกัน']);
        }
        $before = $sub->pairSources()->pluck('cabinet_subtasks.id')->sort()->values();
        $sub->pairSources()->sync($valid->pluck('id')->all());
        app(HandoffService::class)->forgetPairs();

        if ($before->all() !== $valid->pluck('id')->sort()->values()->all()) {
            \App\Models\ActivityLog::record($cabinet->project_id, $actor?->id, $sub, 'updated', $valid->isEmpty()
                ? "เลิกเทียบ Checklist ของ {$sub->name} ({$cabinet->mo_no})"
                : "ตั้งคู่เทียบ {$sub->name} ({$cabinet->mo_no}) กับ ".$valid->pluck('name')->implode(', '));
        }
    }

    /** A cabinet just made from a template: its Sub Tasks take the template's pairs. @param array<int, int> $made template sub task id => cabinet sub task id */
    public function copyFromTemplate(array $made): void
    {
        if (! $made) {
            return;
        }
        $rows = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_template_id]))
            ->map(fn ($p) => $this->row($made[$p->cabinet_subtask_template_id], $made[$p->source_subtask_template_id]));
        DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
    }

    /** A cabinet copied from another: the copy keeps its pairs. @param array<int, int> $made old sub task id => new one */
    public function copyFromCabinet(array $made): void
    {
        if (! $made) {
            return;
        }
        $rows = DB::table('cabinet_subtask_pairs')->whereIn('cabinet_subtask_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_id]))
            ->map(fn ($p) => $this->row($made[$p->cabinet_subtask_id], $made[$p->source_subtask_id]));
        DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
    }

    /** A template duplicated: the copy keeps its pairs. @param array<int, int> $made old template sub task id => new one */
    public function copyTemplatePairs(array $made): void
    {
        if (! $made) {
            return;
        }
        $now = now();
        $rows = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_template_id]))
            ->map(fn ($p) => ['cabinet_subtask_template_id' => $made[$p->cabinet_subtask_template_id], 'source_subtask_template_id' => $made[$p->source_subtask_template_id], 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cabinet_subtask_template_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
    }

    /** The cabinets made from this template whose pairs can still change (projects not closed). */
    public function cabinetsOf(CabinetTemplate $template): Collection
    {
        return Cabinet::where('cabinet_template_id', $template->id)
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', [\App\Models\Project::STATUS_COMPLETED, \App\Models\Project::STATUS_CANCELLED]))
            ->get();
    }

    /**
     * Send the template's pairs to the cabinets made from it earlier: each cabinet's pairs become the template's. A cabinet
     * Sub Task matches a template one by the one it was copied from, else by name. Returns how many cabinets got pairs.
     */
    public function applyToCabinets(CabinetTemplate $template): int
    {
        $template->loadMissing('taskTemplates.subtaskTemplates');
        $templateSubs = $template->taskTemplates->flatMap->subtaskTemplates;
        $byName = $templateSubs->groupBy(fn ($s) => self::norm($s->name))->map(fn ($g) => $g->first()->id);
        $pairs = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', $templateSubs->pluck('id'))->get();
        $cabinets = $this->cabinetsOf($template)->pluck('id');
        if ($cabinets->isEmpty()) {
            return 0;
        }

        $subtasks = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->whereIn('cabinet_id', $cabinets))->with('cabinetTask:id,cabinet_id')
            ->get(['id', 'cabinet_task_id', 'cabinet_subtask_template_id', 'name']);
        $got = 0;
        DB::transaction(function () use ($subtasks, $pairs, $byName, &$got) {
            DB::table('cabinet_subtask_pairs')->whereIn('cabinet_subtask_id', $subtasks->pluck('id'))->delete();
            foreach ($subtasks->groupBy(fn ($s) => $s->cabinetTask->cabinet_id) as $cabinetSubs) {
                // template sub task id => this cabinet's sub tasks made from it
                $made = $cabinetSubs->groupBy(fn ($s) => $s->cabinet_subtask_template_id ?: ($byName[self::norm($s->name)] ?? 0));
                $rows = $pairs->flatMap(fn ($p) => collect($made->get($p->cabinet_subtask_template_id, []))
                    ->crossJoin(collect($made->get($p->source_subtask_template_id, [])))
                    ->map(fn ($two) => $this->row($two[0]->id, $two[1]->id)));
                if ($rows->isNotEmpty()) {
                    DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->all());
                    $got++;
                }
            }
        });
        app(HandoffService::class)->forgetPairs();

        return $got;
    }

    private function row(int $to, int $from): array
    {
        return ['cabinet_subtask_id' => $to, 'source_subtask_id' => $from, 'created_at' => now(), 'updated_at' => now()];
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }
}
