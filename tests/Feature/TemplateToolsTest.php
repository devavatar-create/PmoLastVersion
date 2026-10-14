<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningSync;
use App\Services\TemplateTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Template tools: new items to existing cabinets (add only), duplicate template / Task / Sub Task, Excel out and in. */
class TemplateToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private Department $qc;

    private Project $project;

    private CabinetTemplate $tpl;

    private CabinetSubtaskTemplate $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'pm', 'password' => 'x', 'role' => 'project_manager', 'is_active' => true]);
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->tpl = CabinetTemplate::create(['name' => 'Std', 'code' => 'STD', 'is_default' => true, 'is_active' => true]);
        $task = CabinetTaskTemplate::create(['cabinet_template_id' => $this->tpl->id, 'name' => 'ตรวจ', 'sequence' => 1]);
        $this->sub = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $task->id, 'name' => 'QC ขาออก', 'department_id' => $this->qc->id, 'sequence' => 1]);
        foreach (['Hi-pot', 'แรงบิด'] as $i => $n) {
            ChecklistTemplate::create(['cabinet_subtask_template_id' => $this->sub->id, 'name' => $n, 'description' => $i ? 'ตาม spec' : null, 'sequence' => $i + 1]);
        }
    }

    private function cabinet(string $mo, ?Project $project = null): Cabinet
    {
        $r = app(PlanningSync::class)->apply($this->pm, [['type' => 'cabinet_add', 'taskId' => 'p'.($project ?? $this->project)->id, 'mo' => $mo, 'text' => 'Cab', 'templateId' => (string) $this->tpl->id]])[0];
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        return Cabinet::where('mo_no', $mo)->firstOrFail();
    }

    private function items(Cabinet $c): array
    {
        return CabinetChecklist::whereHas('subtask.cabinetTask', fn ($q) => $q->where('cabinet_id', $c->id))->orderBy('sequence')->pluck('name')->all();
    }

    public function test_new_template_items_go_to_existing_cabinets_only_where_it_is_safe(): void
    {
        $open = $this->cabinet('MO-1');
        $deletedOne = $this->cabinet('MO-2');
        $finished = $this->cabinet('MO-3');
        $closed = $this->cabinet('MO-4', $closedProject = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']));
        $closedProject->update(['status' => 'completed']);
        CabinetChecklist::whereHas('subtask.cabinetTask', fn ($q) => $q->where('cabinet_id', $deletedOne->id))->where('name', 'แรงบิด')->first()->delete();   // removed on purpose
        CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $finished->id))->update(['assignment_status' => 'COMPLETED']);

        ChecklistTemplate::create(['cabinet_subtask_template_id' => $this->sub->id, 'name' => 'ฉนวน', 'sequence' => 3]);
        ChecklistTemplate::create(['cabinet_subtask_template_id' => $this->sub->id, 'name' => 'แรงบิด ', 'sequence' => 4]);   // same name as one already there

        $rows = collect($this->actingAs($this->pm)->getJson(route('cabinet-templates.sync', $this->tpl))->assertOk()->json('cabinets'))->keyBy('mo');
        $this->assertEqualsCanonicalizing(['MO-1', 'MO-2'], $rows->keys()->all(), 'not the finished work, not the closed project');
        $this->assertSame([1, 1], [$rows['MO-1']['missing'], $rows['MO-2']['missing']], 'only "ฉนวน"; "แรงบิด" counts as there even where it was deleted');

        $this->actingAs($this->pm)->postJson(route('cabinet-templates.sync.apply', $this->tpl), ['cabinet_ids' => [$open->id]])->assertOk()->assertJsonPath('added', 1);
        $this->assertSame(['Hi-pot', 'แรงบิด', 'ฉนวน'], $this->items($open));
        $this->assertSame(['Hi-pot'], $this->items($deletedOne), 'not chosen: untouched');
        $this->assertSame(1, collect($this->actingAs($this->pm)->getJson(route('cabinet-templates.sync', $this->tpl))->json('cabinets'))->count(), 'MO-1 is complete now');

        $member = User::forceCreate(['name' => 'M', 'email' => 'm', 'password' => 'x', 'role' => 'member', 'department_id' => $this->qc->id, 'is_active' => true]);
        $this->actingAs($member)->getJson(route('cabinet-templates.sync', $this->tpl))->assertForbidden();
    }

    public function test_duplicate_a_template_a_task_and_a_sub_task(): void
    {
        $this->actingAs($this->pm)->post(route('cabinet-templates.duplicate', $this->tpl))->assertRedirect();
        $copy = CabinetTemplate::where('name', 'Std (สำเนา)')->firstOrFail();
        $this->assertSame(['STD-COPY', false], [$copy->code, $copy->is_default]);
        $this->assertSame(2, ChecklistTemplate::whereHas('subtaskTemplate.taskTemplate', fn ($q) => $q->where('cabinet_template_id', $copy->id))->count());
        $this->actingAs($this->pm)->post(route('cabinet-templates.duplicate', $this->tpl));
        $this->assertTrue(CabinetTemplate::where('code', 'STD-COPY-2')->exists(), 'codes stay unique');

        $this->actingAs($this->pm)->post(route('subtask-templates.duplicate', $this->sub))->assertRedirect(route('cabinet-templates.show', $this->tpl));
        $this->assertSame(['QC ขาออก', 'QC ขาออก (สำเนา)'], CabinetSubtaskTemplate::where('cabinet_task_template_id', $this->sub->cabinet_task_template_id)->orderBy('sequence')->pluck('name')->all());
        $this->actingAs($this->pm)->post(route('task-templates.duplicate', $this->sub->taskTemplate));
        $this->assertSame(['ตรวจ', 'ตรวจ (สำเนา)'], $this->tpl->taskTemplates()->pluck('name')->all());
        $this->assertSame(4, ChecklistTemplate::whereHas('subtaskTemplate.taskTemplate', fn ($q) => $q->where('name', 'ตรวจ (สำเนา)'))->count(), 'the Task copy carries both Sub Tasks with their items (2 + 2)');
    }

    public function test_excel_out_and_back_in_as_a_new_template(): void
    {
        $rows = app(TemplateTools::class)->exportRows($this->tpl);
        $this->assertSame([['ตรวจ', 'QC ขาออก', 'QC', 'Hi-pot', ''], ['ตรวจ', 'QC ขาออก', 'QC', 'แรงบิด', 'ตาม spec']], $rows);
        $this->actingAs($this->pm)->get(route('cabinet-templates.export', $this->tpl))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // as Excel saves it: header, BOM, an extra Sub Task, an unknown department
        $csv = "\xEF\xBB\xBFTask,Sub Task,แผนก,Checklist,หมายเหตุ\nตรวจ,QC ขาออก,QC,Hi-pot,\nตรวจ,QC ขาออก,QC,แรงบิด,ตาม spec\nตรวจ,ถ่ายรูป,ฝ่ายที่ไม่มี,รูปหน้าตู้,\nส่งของ,,,,\n";
        $this->actingAs($this->pm)->post(route('cabinet-templates.import'), ['name' => 'จาก Excel', 'file' => UploadedFile::fake()->createWithContent('t.csv', $csv)])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'ไม่พบแผนก "ฝ่ายที่ไม่มี"'));
        $new = CabinetTemplate::where('name', 'จาก Excel')->firstOrFail();
        $this->assertSame(['ตรวจ', 'ส่งของ'], $new->taskTemplates()->pluck('name')->all());
        $subs = CabinetSubtaskTemplate::whereHas('taskTemplate', fn ($q) => $q->where('cabinet_template_id', $new->id))->orderBy('id')->get();
        $this->assertSame(['QC ขาออก', 'ถ่ายรูป'], $subs->pluck('name')->all());
        $this->assertSame([$this->qc->id, null], $subs->pluck('department_id')->all());
        $this->assertSame(['Hi-pot', 'แรงบิด'], ChecklistTemplate::where('cabinet_subtask_template_id', $subs[0]->id)->orderBy('sequence')->pluck('name')->all());
        $this->assertSame('ตาม spec', ChecklistTemplate::where('cabinet_subtask_template_id', $subs[0]->id)->where('name', 'แรงบิด')->value('description'));

        // Thai Windows Excel (CP874), no header
        $thai = iconv('UTF-8', 'CP874', "งานไฟ,เดินสาย,QC,ตรวจสาย,\n");
        $this->actingAs($this->pm)->post(route('cabinet-templates.import'), ['name' => 'ANSI', 'file' => UploadedFile::fake()->createWithContent('a.csv', $thai)])->assertRedirect();
        $this->assertSame('ตรวจสาย', ChecklistTemplate::whereHas('subtaskTemplate.taskTemplate.cabinetTemplate', fn ($q) => $q->where('name', 'ANSI'))->value('name'));
    }

    public function test_a_template_in_use_is_deleted_and_its_cabinets_keep_their_copies(): void
    {
        $cab = $this->cabinet('MO-1');
        $before = $this->items($cab);
        $this->assertNotEmpty($before);
        $member = User::forceCreate(['name' => 'M', 'email' => 'm', 'password' => 'x', 'role' => 'member', 'department_id' => $this->qc->id, 'is_active' => true]);
        $this->actingAs($member)->delete(route('cabinet-templates.destroy', $this->tpl))->assertForbidden();

        $this->actingAs($this->pm)->get(route('cabinet-templates.show', $this->tpl))->assertSee('ลบเทมเพลต');
        $this->actingAs($this->pm)->delete(route('cabinet-templates.destroy', $this->tpl))
            ->assertRedirect(route('cabinet-templates.index'))->assertSessionHas('success', fn ($m) => str_contains($m, 'ตู้ 1 ตู้'));

        $this->assertNull(CabinetTemplate::find($this->tpl->id));
        $this->assertSame(0, CabinetTaskTemplate::count() + CabinetSubtaskTemplate::count() + ChecklistTemplate::count(), 'its tasks / sub tasks / checklists go with it');
        $this->assertSame($before, $this->items($cab->fresh()), 'the cabinet keeps its own copy');
        $this->assertNull($cab->fresh()->cabinet_template_id);
        $this->assertSame(0, CabinetChecklist::whereNotNull('checklist_template_id')->count(), 'no link left pointing at nothing');
    }
}
