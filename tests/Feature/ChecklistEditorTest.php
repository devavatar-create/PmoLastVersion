<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTask;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\DepartmentLink;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The one checklist editor: cabinet lists (department that accepted / Admin / PM) and template lists (Admin / PM). */
class ChecklistEditorTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private CabinetSubtask $sub;

    private CabinetSubtask $other;   // same cabinet, another Sub Task

    private CabinetSubtask $twin;    // same Sub Task in another cabinet of the project

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        [$this->sub, $this->other] = $this->cabinet('MO-1', ['บัส', 'สาย']);
        [$this->twin] = $this->cabinet('MO-2', ['บัส']);
        foreach (['ก', 'ข', 'ค'] as $i => $n) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->sub->id, 'name' => $n, 'is_completed' => $i === 0, 'sequence' => $i + 1]);
        }
    }

    /** @return list<CabinetSubtask> */
    private function cabinet(string $mo, array $subs): array
    {
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);

        return array_map(fn ($n) => CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $n, 'department_id' => $this->busbar->id,
            'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]), $subs);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'E'.++$n, 'email' => 'ed'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function op(User $u, array $op, string $kind = 'subtask', ?int $id = null)
    {
        return $this->actingAs($u)->postJson(route('checklist-editor.apply', [$kind, $id ?? $this->sub->id]), $op);
    }

    private function names(CabinetSubtask $s): array
    {
        return $s->checklists()->orderBy('sequence')->pluck('name')->all();
    }

    public function test_many_items_at_once_one_log_line_and_the_bars_follow(): void
    {
        $worker = $this->user($this->busbar);
        $r = $this->op($worker, ['type' => 'add', 'items' => [['name' => 'ง', 'remark' => 'หมายเหตุ'], ['name' => '  '], 'จ']])->assertOk();

        $this->assertSame(['ก', 'ข', 'ค', 'ง', 'จ'], $this->names($this->sub), 'blank rows dropped, in order, at the end');
        $this->assertSame('หมายเหตุ', CabinetChecklist::where('name', 'ง')->value('remark'));
        $this->assertSame(1, ActivityLog::where('action', 'checklist_edited')->count(), 'one line, not one per item');
        $this->assertSame(['completed_checklists' => 1, 'total_checklists' => 5], array_intersect_key($r->json('progress.subtask'), ['completed_checklists' => 0, 'total_checklists' => 0]));
        $this->assertSame(20, $this->sub->fresh()->progress, '1 of 5 ticked');
        $this->assertSame('delete', $r->json('undo.type'));
        $this->assertCount(2, $r->json('undo.ids'));
    }

    public function test_edit_in_place_and_undo(): void
    {
        $worker = $this->user($this->busbar);
        $id = CabinetChecklist::where('name', 'ข')->value('id');

        $r = $this->op($worker, ['type' => 'update', 'id' => $id, 'name' => 'ข ใหม่', 'remark' => 'รอของ'])->assertOk();
        $this->assertSame(['ข ใหม่', 'รอของ'], [CabinetChecklist::find($id)->name, CabinetChecklist::find($id)->remark]);
        $this->op($worker, $r->json('undo'))->assertOk();
        $this->assertSame(['ข', null], [CabinetChecklist::find($id)->name, CabinetChecklist::find($id)->remark], 'the undo puts it back (even with no date field here)');
        $this->op($worker, ['type' => 'update', 'id' => $id, 'name' => ' '])->assertStatus(422);
        $this->op($worker, ['type' => 'update', 'id' => $id, 'date' => '20/10/2569'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ไม่มีช่องวันที่'));
    }

    public function test_dates_only_where_the_handoff_asks_and_pasted_thai_dates_are_read(): void
    {
        DepartmentLink::create(['from_department_id' => $this->busbar->id, 'to_department_id' => Department::create(['name' => 'คลัง', 'code' => 'ST', 'is_active' => true])->id, 'date_label' => 'วันของเข้า']);
        $this->op($this->user($this->busbar), ['type' => 'add', 'items' => [['name' => 'Breaker', 'date' => '20/10/2569'], ['name' => 'CT', 'date' => '2026-11-01']]])->assertOk();
        $this->assertSame(['2026-10-20', '2026-11-01'], CabinetChecklist::whereIn('name', ['Breaker', 'CT'])->orderBy('sequence')->get()->map(fn ($c) => $c->expected_date->format('Y-m-d'))->all());
        $this->op($this->user($this->busbar), ['type' => 'add', 'items' => [['name' => 'X', 'date' => '31/02/2569']]])->assertStatus(422);
    }

    public function test_delete_is_undone_reorder_and_move(): void
    {
        $pm = $this->user(null, 'project_manager');
        $ids = CabinetChecklist::whereIn('name', ['ก', 'ค'])->pluck('id')->all();

        $r = $this->op($pm, ['type' => 'delete', 'ids' => $ids])->assertOk();
        $this->assertSame(['ข'], $this->names($this->sub));
        $this->op($pm, $r->json('undo'))->assertOk();
        $this->assertSame(['ก', 'ข', 'ค'], $this->names($this->sub), 'the same rows come back (soft delete)');

        $order = CabinetChecklist::orderByDesc('sequence')->pluck('id')->all();
        $this->op($pm, ['type' => 'reorder', 'ids' => $order])->assertOk();
        $this->assertSame(['ค', 'ข', 'ก'], $this->names($this->sub));
        $this->op($pm, ['type' => 'reorder', 'ids' => [999]])->assertStatus(422);

        $this->op($pm, ['type' => 'move', 'ids' => [CabinetChecklist::where('name', 'ก')->value('id')], 'to' => $this->other->id])->assertOk();
        $this->assertSame(['ค', 'ข'], $this->names($this->sub));
        $this->assertSame(['ก'], $this->names($this->other));
        $this->op($pm, ['type' => 'move', 'ids' => [CabinetChecklist::where('name', 'ข')->value('id')], 'to' => $this->twin->id])->assertStatus(422);   // move = same cabinet only
    }

    public function test_copy_to_the_same_sub_task_in_other_cabinets_and_in_from_another(): void
    {
        $pm = $this->user(null, 'admin');
        $targets = $this->actingAs($pm)->getJson(route('checklist-editor.show', ['subtask', $this->sub->id, 'targets' => 1]))->assertOk()->json();
        $this->assertSame([$this->twin->id], array_column($targets['copyTo'], 'id'), 'same Sub Task in the other cabinet');
        $this->assertSame([$this->other->id], array_column($targets['copyFrom'], 'id'), 'other Sub Tasks of the same cabinet');

        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->twin->id, 'name' => 'ข', 'is_completed' => false, 'sequence' => 1]);
        $r = $this->op($pm, ['type' => 'copy', 'ids' => CabinetChecklist::where('cabinet_subtask_id', $this->sub->id)->pluck('id')->all(), 'to' => [$this->twin->id]])->assertOk();
        $this->assertSame(['ข', 'ก', 'ค'], $this->names($this->twin), '"ข" was already there');
        $this->assertStringContainsString('ข้าม 1', $r->json('message'));
        $this->assertFalse(CabinetChecklist::where('cabinet_subtask_id', $this->twin->id)->where('name', 'ก')->value('is_completed'), 'copies start unticked');

        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->other->id, 'name' => 'ซ', 'is_completed' => false, 'sequence' => 1]);
        $this->op($pm, ['type' => 'copyFrom', 'from' => $this->other->id])->assertOk();
        $this->assertSame(['ก', 'ข', 'ค', 'ซ'], $this->names($this->sub));
    }

    public function test_who_may_edit_and_see(): void
    {
        $waiting = $this->user($this->busbar);
        $this->sub->forceFill(['assignment_status' => 'ASSIGNED'])->save();
        $this->op($waiting, ['type' => 'add', 'items' => ['x']])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'รับงานแล้ว'));
        $this->op($this->user(Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true])), ['type' => 'add', 'items' => ['x']])->assertStatus(422);
        $this->actingAs($this->user(Department::where('code', 'QC')->first()))->getJson(route('checklist-editor.show', ['subtask', $this->sub->id]))->assertForbidden();

        $this->project->update(['status' => 'completed']);
        $this->op($this->user(null, 'admin'), ['type' => 'add', 'items' => ['x']])->assertStatus(423);
        $this->assertSame(3, $this->sub->checklists()->count());
    }

    public function test_template_lists_admin_or_pm_only_and_cabinets_are_never_touched(): void
    {
        $tpl = CabinetTemplate::create(['name' => 'Std', 'code' => 'STD', 'is_active' => true]);
        $task = CabinetTaskTemplate::create(['cabinet_template_id' => $tpl->id, 'name' => 'QC', 'sequence' => 1]);
        $a = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $task->id, 'name' => 'ตรวจขาออก', 'sequence' => 1]);
        $other = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => CabinetTaskTemplate::create(['cabinet_template_id' => CabinetTemplate::create(['name' => 'B', 'code' => 'B', 'is_active' => true])->id, 'name' => 'X', 'sequence' => 1])->id, 'name' => 'ชุด QC', 'sequence' => 1]);
        ChecklistTemplate::create(['cabinet_subtask_template_id' => $other->id, 'name' => 'Hi-pot', 'sequence' => 1]);
        $pm = $this->user(null, 'project_manager');
        $cabinetItems = CabinetChecklist::count();

        $this->op($this->user($this->busbar), ['type' => 'add', 'items' => ['x']], 'template', $a->id)->assertStatus(422);
        $this->op($pm, ['type' => 'add', 'items' => [['name' => 'แรงบิด', 'remark' => 'ตาม spec']]], 'template', $a->id)->assertOk();
        $this->op($pm, ['type' => 'copyFrom', 'from' => $other->id], 'template', $a->id)->assertOk();
        $this->assertSame(['แรงบิด', 'Hi-pot'], ChecklistTemplate::where('cabinet_subtask_template_id', $a->id)->orderBy('sequence')->pluck('name')->all(), 'a ready-made set from another template');

        $r = $this->op($pm, ['type' => 'delete', 'ids' => ChecklistTemplate::where('cabinet_subtask_template_id', $a->id)->pluck('id')->all()], 'template', $a->id)->assertOk();
        $this->assertSame(0, ChecklistTemplate::where('cabinet_subtask_template_id', $a->id)->count());
        $this->op($pm, $r->json('undo'), 'template', $a->id)->assertOk();
        $this->assertSame(['แรงบิด', 'Hi-pot'], ChecklistTemplate::where('cabinet_subtask_template_id', $a->id)->orderBy('sequence')->pluck('name')->all(), 'undo re-creates them, in order');
        $this->assertSame('ตาม spec', ChecklistTemplate::where('name', 'แรงบิด')->value('description'));
        $this->assertSame($cabinetItems, CabinetChecklist::count(), 'cabinets made from templates are not touched');
        $this->actingAs($this->user($this->busbar))->getJson(route('checklist-editor.show', ['template', $a->id]))->assertOk()->assertJsonPath('editable', false);
    }
}
