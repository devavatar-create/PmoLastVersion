<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "แก้ Checklist ทั้งโครงการ" (Admin / PM): the same change in the same Sub Task of every cabinet of a project at once -
 *  add   : items to every cabinet's Sub Task that does not have them yet (a finished Sub Task is skipped: it would reopen)
 *  rename: an item's name (exact, or a piece of text inside names) everywhere
 *  delete: an item everywhere it is NOT ticked yet (ticked ones are kept and counted)
 * Always previewed first (same call with preview = true); one Activity Log line for the project.
 */
class ChecklistBulk
{
    public function __construct(private ProgressService $progress, private SharedChecklist $shared) {}

    /** What the dialog offers: the project's Sub Task names (how many cabinets have each) and, per Sub Task, its item names. */
    public function options(Project $project): array
    {
        $subs = $this->subtasks($project, null)->load('checklists:id,cabinet_subtask_id,name');

        return $subs->groupBy('name')->map(fn (Collection $g, string $name) => [
            'name' => $name,
            'cabinets' => $g->count(),   // one Sub Task of that name per cabinet
            'items' => $g->flatMap->checklists->pluck('name')->countBy()->sortDesc()->keys()->take(200)->values()->all(),
        ])->sortBy('name')->values()->all();
    }

    /** @return array{preview: bool, changes: int, skipped: int, cabinets: int, rows: list<array>, message: string} */
    public function run(Project $project, User $user, array $op, bool $preview): array
    {
        if (! $user->canDispatchWork()) {
            throw new PlanningRejected('แก้ Checklist ทั้งโครงการได้เฉพาะ Admin / PM');
        }
        ProjectLock::assertOpen($project->id);
        $subs = $this->subtasks($project, trim((string) ($op['subtask'] ?? '')) ?: null)->load('checklists', 'cabinetTask.cabinet:id,mo_no');
        if ($subs->isEmpty()) {
            throw new PlanningRejected('ไม่พบ Sub Task นี้ในโครงการ');
        }

        $plan = match ($op['action'] ?? null) {
            'add' => $this->planAdd($subs, $op),
            'rename' => $this->planRename($subs, $op),
            'delete' => $this->planDelete($subs, $op),
            default => throw new PlanningRejected('เลือกสิ่งที่จะทำ'),
        };

        if (! $preview && $plan['apply']->isNotEmpty()) {
            DB::transaction(fn () => Model::withoutEvents(fn () => $plan['apply']->each(fn (\Closure $f) => $f())));
            $plan['touched']->merge($this->shared->takeTouched())->unique()->each(fn ($id) => $this->progress->recalculateSubtask(CabinetSubtask::find($id)));
            ActivityLog::record($project->id, $user->id, $project, 'checklist_edited', $plan['log'].' ('.$plan['changes'].' รายการ ใน '.$plan['cabinets'].' ตู้)');
            PlanningBoard::touch();
        }

        return [
            'preview' => $preview,
            'changes' => $plan['changes'],
            'skipped' => $plan['skipped'],
            'cabinets' => $plan['cabinets'],
            'rows' => array_slice($plan['rows'], 0, 100),
            'message' => ($preview ? 'จะ' : '').$plan['log'].' · '.$plan['changes'].' รายการ ใน '.$plan['cabinets'].' ตู้'.($plan['skipped'] ? ' · ข้าม '.$plan['skipped'] : ''),
        ];
    }

    private function subtasks(Project $project, ?string $name): Collection
    {
        return CabinetSubtask::whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->when($name, fn ($q) => $q->where('name', $name))->orderBy('id')->get();
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    private function names(array $op, string $key): array
    {
        $raw = is_array($op[$key] ?? null) ? $op[$key] : preg_split('/\r\n|\r|\n/', (string) ($op[$key] ?? ''));

        return collect($raw)->map(fn ($n) => mb_substr(trim(preg_replace('/\t+/', ' ', (string) $n)), 0, 255))->filter()->unique()->take(ChecklistEditor::MAX_ITEMS)->values()->all();
    }

    private function planAdd(Collection $subs, array $op): array
    {
        $names = $this->names($op, 'names');
        if (! $names) {
            throw new PlanningRejected('พิมพ์ชื่อรายการที่จะเพิ่ม (บรรทัดละ 1 รายการ)');
        }
        $plan = $this->emptyPlan('เพิ่ม "'.implode('", "', array_slice($names, 0, 3)).'"'.(count($names) > 3 ? ' และอีก '.(count($names) - 3).' รายการ' : ''));
        foreach ($subs as $s) {
            if ($s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED) {
                $plan['skipped'] += count($names);
                $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => 'ข้าม — งานนี้เสร็จแล้ว'];

                continue;
            }
            $have = $s->checklists->map(fn ($c) => self::norm($c->name))->all();
            $new = array_values(array_filter($names, fn ($n) => ! in_array(self::norm($n), $have, true)));
            $plan['skipped'] += count($names) - count($new);
            if (! $new) {
                continue;
            }
            $plan['changes'] += count($new);
            $plan['cabinetIds'][] = $s->cabinetTask->cabinet_id;
            $plan['touched'][] = $s->id;
            $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => '+ '.implode(', ', $new)];
            $seq = (int) $s->checklists->max('sequence');
            $plan['apply'][] = function () use ($s, $new, $seq) {
                $made = collect($new)->map(fn ($n, $i) => CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $n, 'is_completed' => false, 'sequence' => $seq + $i + 1]));
                $this->shared->added($s, $made);   // the Sub Tasks comparing with this one get them too
            };
        }

        return $this->done($plan);
    }

    private function planRename(Collection $subs, array $op): array
    {
        $find = trim((string) ($op['find'] ?? ''));
        $replace = mb_substr(trim((string) ($op['replace'] ?? '')), 0, 255);
        $part = (bool) ($op['contains'] ?? false);
        if ($find === '' || (! $part && $replace === '')) {
            throw new PlanningRejected('พิมพ์ชื่อเดิมและชื่อใหม่');
        }
        $plan = $this->emptyPlan($part ? "แทนที่ \"{$find}\" ด้วย \"{$replace}\" ในชื่อ" : "เปลี่ยนชื่อ \"{$find}\" เป็น \"{$replace}\"");
        foreach ($subs as $s) {
            foreach ($s->checklists as $c) {
                $newName = $part
                    ? trim(str_ireplace($find, $replace, $c->name))
                    : (self::norm($c->name) === self::norm($find) ? $replace : null);
                if ($newName === null || $newName === $c->name || $newName === '') {
                    continue;
                }
                if ($c->source_checklist_id) {   // a copy: its name follows its source
                    $plan['skipped']++;
                    $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => 'ข้าม "'.$c->name.'" — รายการจากแผนกที่เทียบ แก้ที่ต้นทาง'];

                    continue;
                }
                $plan['changes']++;
                $plan['cabinetIds'][] = $s->cabinetTask->cabinet_id;
                $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => $c->name.' → '.$newName];
                $plan['apply'][] = function () use ($c, $newName) {
                    $c->forceFill(['name' => mb_substr($newName, 0, 255)])->save();
                    $this->shared->renamed($c);
                };
            }
        }

        return $this->done($plan);
    }

    private function planDelete(Collection $subs, array $op): array
    {
        $names = array_map(fn ($n) => self::norm($n), $this->names($op, 'names'));
        if (! $names) {
            throw new PlanningRejected('เลือกรายการที่จะลบ');
        }
        $plan = $this->emptyPlan('ลบ '.count($names).' ชื่อรายการ');
        $copiesTicked = CabinetChecklist::whereIn('source_checklist_id', $subs->flatMap->checklists->pluck('id'))->where('is_completed', true)->pluck('source_checklist_id')->flip();
        foreach ($subs as $s) {
            foreach ($s->checklists->filter(fn ($c) => in_array(self::norm($c->name), $names, true)) as $c) {
                if ($c->is_completed) {
                    $plan['skipped']++;
                    $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => 'เก็บไว้ — "'.$c->name.'" ติ๊กแล้ว'];

                    continue;
                }
                if ($c->source_checklist_id || isset($copiesTicked[$c->id])) {
                    $plan['skipped']++;
                    $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => 'เก็บไว้ — "'.$c->name.'" '.($c->source_checklist_id ? 'เป็นรายการจากแผนกที่เทียบ ลบที่ต้นทาง' : 'แผนกที่เทียบติ๊กแล้ว')];

                    continue;
                }
                $plan['changes']++;
                $plan['cabinetIds'][] = $s->cabinetTask->cabinet_id;
                $plan['touched'][] = $s->id;
                $plan['rows'][] = ['mo' => $s->cabinetTask->cabinet->mo_no, 'change' => '− '.$c->name];
                $plan['apply'][] = function () use ($c) {
                    $c->delete();   // soft: restorable
                    $this->shared->deleted(collect([$c]));
                };
            }
        }

        return $this->done($plan);
    }

    private function emptyPlan(string $log): array
    {
        return ['log' => $log, 'changes' => 0, 'skipped' => 0, 'rows' => [], 'apply' => [], 'touched' => [], 'cabinetIds' => []];
    }

    private function done(array $plan): array
    {
        $plan['apply'] = collect($plan['apply']);
        $plan['touched'] = collect($plan['touched']);
        $plan['cabinets'] = count(array_unique($plan['cabinetIds']));

        return $plan;
    }
}
