<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cabinet-template tools (Admin / PM):
 *  - send a template's NEW checklist items to the cabinets already made from it (add only; never edits / removes, never
 *    reopens finished work, never re-adds an item a cabinet deleted on purpose)
 *  - duplicate a whole template / one Task / one Sub Task
 *  - Excel (CSV) out and in: Task | Sub Task | แผนก | Checklist | หมายเหตุ
 */
class TemplateTools
{
    public const CSV_HEADER = ['Task', 'Sub Task', 'แผนก', 'Checklist', 'หมายเหตุ'];

    /** @var array<int, int> while duplicating a template: old sub task id => its copy (to copy the pairs) */
    private array $made = [];

    public function __construct(private ProgressService $progress) {}

    // ======================================================================= send new items to existing cabinets

    /**
     * Per cabinet made from this template (open project, cabinet not finished): which of the template's items its matching
     * Sub Tasks lack. Matched by the template item it was copied from, else by name; deleted ones count as there.
     *
     * @return list<array{id: int, mo: string, name: string, project: string, missing: int, sample: list<string>}>
     */
    public function syncPreview(CabinetTemplate $template): array
    {
        return $this->missing($template)->map(fn (array $c) => [
            'id' => $c['cabinet']->id, 'mo' => $c['cabinet']->mo_no, 'name' => $c['cabinet']->cabinet_name,
            'project' => $c['cabinet']->project->project_no, 'missing' => $c['adds']->count(),
            'sample' => $c['adds']->pluck('name')->unique()->take(4)->values()->all(),
        ])->values()->all();
    }

    /** @param list<int> $cabinetIds */
    public function syncApply(CabinetTemplate $template, User $user, array $cabinetIds): array
    {
        $this->requirePmo($user);
        $chosen = collect($cabinetIds)->map(fn ($id) => (int) $id)->all();
        $plan = $this->missing($template)->filter(fn ($c) => in_array($c['cabinet']->id, $chosen, true));
        $added = 0;
        DB::transaction(function () use ($plan, &$added) {
            Model::withoutEvents(function () use ($plan, &$added) {
                foreach ($plan as $c) {
                    foreach ($c['adds'] as $a) {
                        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $a['subtask']->id, 'checklist_template_id' => $a['template']->id,
                            'name' => $a['name'], 'description' => $a['template']->description, 'is_completed' => false, 'sequence' => $a['sequence']]);
                        $added++;
                    }
                }
            });
        });
        // a Sub Task comparing with another: the items it just got become copies of that one's, and that one's new items reach it
        $shared = app(SharedChecklist::class);
        $shared->syncCabinets($plan->map(fn ($c) => $c['cabinet']->id)->values());
        CabinetSubtask::whereKey($shared->takeTouched())->get()->each(fn ($s) => $this->progress->recalculateSubtask($s));
        foreach ($plan as $c) {
            $c['adds']->pluck('subtask')->unique('id')->each(fn ($s) => $this->progress->recalculateSubtask($s->fresh()));
            ActivityLog::record($c['cabinet']->project_id, $user->id, $c['cabinet'], 'checklist_edited',
                "เพิ่ม Checklist {$c['adds']->count()} รายการจากเทมเพลต \"{$template->name}\" ที่อัปเดต ({$c['cabinet']->mo_no})");
        }
        PlanningBoard::touch();

        return ['cabinets' => $plan->count(), 'added' => $added];
    }

    private function missing(CabinetTemplate $template): Collection
    {
        $template->load('taskTemplates.subtaskTemplates.checklistTemplates');
        $bySubTpl = $template->taskTemplates->flatMap->subtaskTemplates->keyBy('id');
        $cabinets = Cabinet::where('cabinet_template_id', $template->id)->where('status', '!=', 'completed')
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', \App\Models\Project::LOCKED_STATUSES))
            ->with('project:id,project_no,status', 'tasks.subtasks')->orderBy('id')->get();
        $items = CabinetChecklist::withTrashed()->whereIn('cabinet_subtask_id', $cabinets->flatMap(fn ($c) => $c->tasks->flatMap->subtasks)->pluck('id'))
            ->get(['id', 'cabinet_subtask_id', 'checklist_template_id', 'name', 'sequence'])->groupBy('cabinet_subtask_id');
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));

        return $cabinets->map(function (Cabinet $cabinet) use ($bySubTpl, $items, $norm) {
            $adds = collect();
            foreach ($cabinet->tasks->flatMap->subtasks as $s) {
                $tpl = $bySubTpl->get($s->cabinet_subtask_template_id);
                if (! $tpl || $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED) {
                    continue;
                }
                $have = $items->get($s->id, collect());
                $seq = (int) $have->max('sequence');
                foreach ($tpl->checklistTemplates as $ct) {
                    if ($have->contains('checklist_template_id', $ct->id) || $have->contains(fn ($i) => $norm($i->name) === $norm($ct->name))) {
                        continue;
                    }
                    $adds->push(['subtask' => $s, 'template' => $ct, 'name' => $ct->name, 'sequence' => ++$seq]);
                }
            }

            return ['cabinet' => $cabinet, 'adds' => $adds];
        })->filter(fn ($c) => $c['adds']->isNotEmpty())->values();
    }

    // ======================================================================= delete

    /**
     * Deletes a template with its tasks / sub tasks / checklists. Cabinets made from it keep their own copies; only their
     * link back to it goes (so "ส่งรายการใหม่ไปตู้เดิม" no longer reaches them). Done by hand rather than left to the
     * foreign keys: production's MyISAM tables have none. The links are cleared with plain updates - a template link is
     * not project data, so a closed project's cabinets lose theirs too. Returns how many cabinets were made from it.
     */
    public function deleteTemplate(CabinetTemplate $template): int
    {
        return DB::transaction(function () use ($template) {
            $taskIds = CabinetTaskTemplate::where('cabinet_template_id', $template->id)->pluck('id');
            $subIds = CabinetSubtaskTemplate::whereIn('cabinet_task_template_id', $taskIds)->pluck('id');
            $checkIds = ChecklistTemplate::whereIn('cabinet_subtask_template_id', $subIds)->pluck('id');

            $cabinets = Cabinet::withTrashed()->where('cabinet_template_id', $template->id)->update(['cabinet_template_id' => null]);
            \App\Models\CabinetTask::withTrashed()->whereIn('cabinet_task_template_id', $taskIds)->update(['cabinet_task_template_id' => null]);
            CabinetSubtask::withTrashed()->whereIn('cabinet_subtask_template_id', $subIds)->update(['cabinet_subtask_template_id' => null]);
            CabinetChecklist::withTrashed()->whereIn('checklist_template_id', $checkIds)->update(['checklist_template_id' => null]);

            ChecklistTemplate::whereKey($checkIds)->delete();
            CabinetSubtaskTemplate::whereKey($subIds)->delete();
            CabinetTaskTemplate::whereKey($taskIds)->delete();
            $template->delete();

            return $cabinets;
        });
    }

    // ======================================================================= duplicate

    public function duplicateTemplate(CabinetTemplate $source, User $user): CabinetTemplate
    {
        $this->requirePmo($user);

        return DB::transaction(function () use ($source) {
            $copy = CabinetTemplate::create(['name' => $source->name.' (สำเนา)', 'code' => $this->uniqueCode($source->code.'-COPY'),
                'description' => $source->description, 'is_default' => false, 'is_active' => true]);
            $this->made = [];
            foreach ($source->taskTemplates()->with('subtaskTemplates.checklistTemplates')->get() as $task) {
                $this->copyTask($task, $copy->id, $task->sequence, $task->name);
            }
            app(SubtaskPairs::class)->copyTemplatePairs($this->made);   // the copy compares the same Sub Tasks

            return $copy;
        });
    }

    public function duplicateTask(CabinetTaskTemplate $task, User $user): CabinetTaskTemplate
    {
        $this->requirePmo($user);
        $task->load('subtaskTemplates.checklistTemplates');

        return DB::transaction(fn () => $this->copyTask($task, $task->cabinet_template_id,
            (int) CabinetTaskTemplate::where('cabinet_template_id', $task->cabinet_template_id)->max('sequence') + 1, $task->name.' (สำเนา)'));
    }

    public function duplicateSubtask(CabinetSubtaskTemplate $sub, User $user): CabinetSubtaskTemplate
    {
        $this->requirePmo($user);
        $sub->load('checklistTemplates');

        return DB::transaction(fn () => $this->copySubtask($sub, $sub->cabinet_task_template_id,
            (int) CabinetSubtaskTemplate::where('cabinet_task_template_id', $sub->cabinet_task_template_id)->max('sequence') + 1, $sub->name.' (สำเนา)'));
    }

    private function copyTask(CabinetTaskTemplate $task, int $templateId, int $sequence, string $name): CabinetTaskTemplate
    {
        $copy = CabinetTaskTemplate::create(['cabinet_template_id' => $templateId, 'name' => $name, 'description' => $task->description,
            'weight' => $task->weight, 'schedule_weight' => $task->schedule_weight, 'sequence' => $sequence]);
        foreach ($task->subtaskTemplates as $sub) {
            $this->copySubtask($sub, $copy->id, $sub->sequence, $sub->name);
        }

        return $copy;
    }

    private function copySubtask(CabinetSubtaskTemplate $sub, int $taskId, int $sequence, string $name): CabinetSubtaskTemplate
    {
        $copy = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $taskId, 'name' => $name, 'description' => $sub->description,
            'department_id' => $sub->department_id, 'sequence' => $sequence]);
        foreach ($sub->checklistTemplates as $c) {
            ChecklistTemplate::create(['cabinet_subtask_template_id' => $copy->id, 'name' => $c->name, 'description' => $c->description, 'sequence' => $c->sequence]);
        }
        $this->made[$sub->id] = $copy->id;

        return $copy;
    }

    // ======================================================================= Excel (CSV) out / in

    /** @return list<list<string>> one row per checklist item (a Sub Task / Task with none still gets its row) */
    public function exportRows(CabinetTemplate $template): array
    {
        $rows = [];
        foreach ($template->taskTemplates()->with('subtaskTemplates.department', 'subtaskTemplates.checklistTemplates')->get() as $task) {
            if ($task->subtaskTemplates->isEmpty()) {
                $rows[] = [$task->name, '', '', '', ''];
            }
            foreach ($task->subtaskTemplates as $sub) {
                $dept = (string) $sub->department?->name;
                if ($sub->checklistTemplates->isEmpty()) {
                    $rows[] = [$task->name, $sub->name, $dept, '', ''];
                }
                foreach ($sub->checklistTemplates as $c) {
                    $rows[] = [$task->name, $sub->name, $dept, $c->name, (string) $c->description];
                }
            }
        }

        return $rows;
    }

    /**
     * A new template from a CSV laid out like the export (header row optional). Rows with the same Task / Sub Task as the
     * row before belong to it. Departments are matched by name; an unknown one is left empty and reported.
     *
     * @return array{template: CabinetTemplate, warnings: list<string>}
     */
    public function import(UploadedFile $file, string $name, User $user): array
    {
        $this->requirePmo($user);
        $rows = $this->readCsv($file);
        if (! $rows) {
            throw new PlanningRejected('ไฟล์ว่าง หรือไม่ใช่ CSV');
        }
        $depts = Department::get(['id', 'name'])->keyBy(fn ($d) => mb_strtolower(trim($d->name)));
        $warnings = [];

        $template = DB::transaction(function () use ($rows, $name, $depts, &$warnings) {
            $template = CabinetTemplate::create(['name' => mb_substr($name, 0, 255), 'code' => $this->uniqueCode(Str::upper(Str::slug(Str::ascii($name)) ?: 'TPL')),
                'is_default' => false, 'is_active' => true]);
            $task = $sub = null;
            [$tSeq, $sSeq, $cSeq] = [0, 0, 0];
            foreach ($rows as $n => $r) {
                [$t, $s, $d, $c, $remark] = array_pad(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 255), $r), 5, '');
                if ($t !== '' && (! $task || $task->name !== $t)) {
                    $task = CabinetTaskTemplate::create(['cabinet_template_id' => $template->id, 'name' => $t, 'sequence' => ++$tSeq]);
                    [$sub, $sSeq] = [null, 0];
                }
                if (! $task) {
                    $warnings[] = 'แถว '.($n + 1).': ไม่มีชื่อ Task — ข้าม';

                    continue;
                }
                if ($s !== '' && (! $sub || $sub->name !== $s)) {
                    $deptId = $d === '' ? null : $depts->get(mb_strtolower($d))?->id;
                    if ($d !== '' && ! $deptId) {
                        $warnings[] = "ไม่พบแผนก \"{$d}\" (Sub Task \"{$s}\") — เว้นว่างไว้";
                    }
                    $sub = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $task->id, 'name' => $s, 'department_id' => $deptId, 'sequence' => ++$sSeq]);
                    $cSeq = 0;
                }
                if ($c !== '') {
                    if (! $sub) {
                        $warnings[] = 'แถว '.($n + 1).": Checklist \"{$c}\" ไม่มี Sub Task — ข้าม";

                        continue;
                    }
                    ChecklistTemplate::create(['cabinet_subtask_template_id' => $sub->id, 'name' => $c, 'description' => $remark ?: null, 'sequence' => ++$cSeq]);
                }
            }

            return $template;
        });

        return ['template' => $template, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @return list<list<string>> data rows (header dropped), from UTF-8 (with or without BOM) or Thai Windows (CP874) CSV */
    private function readCsv(UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) @iconv('CP874', 'UTF-8//IGNORE', $raw);   // Excel "CSV" on Thai Windows
        }
        $delim = substr_count(strtok($raw, "\n"), "\t") > substr_count(strtok($raw, "\n"), ',') ? "\t" : ',';
        $rows = [];
        $h = fopen('php://memory', 'r+');
        fwrite($h, $raw);
        rewind($h);
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            $r = array_map(fn ($v) => preg_replace("/^'(?=[=+\\-@])/", '', (string) $v), $r);   // the export's formula guard
            if (array_filter($r, fn ($v) => trim($v) !== '')) {
                $rows[] = $r;
            }
        }
        fclose($h);
        if ($rows && mb_strtolower(trim($rows[0][0] ?? '')) === 'task') {
            array_shift($rows);   // the header row
        }

        return $rows;
    }

    private function uniqueCode(string $base): string
    {
        $base = mb_substr($base, 0, 40);
        $code = $base;
        for ($i = 2; CabinetTemplate::where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    private function requirePmo(User $user): void
    {
        if (! $user->allows('edit.templates')) {
            throw new PlanningRejected('เฉพาะ Admin / PM');
        }
    }
}
