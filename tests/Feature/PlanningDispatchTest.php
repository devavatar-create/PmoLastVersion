<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A cabinet made on the Planning page gets its template's checklists, and its sub tasks can be handed to departments right there. */
class PlanningDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    private CabinetTemplate $template;

    /** @var array<string, CabinetSubtaskTemplate> */
    private array $subs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);

        // a template whose sub tasks have NO department (like the real default one) + one that already has one
        $this->template = CabinetTemplate::create(['name' => 'Std', 'code' => 'STD', 'is_default' => true, 'is_active' => true]);
        $task = CabinetTaskTemplate::create(['cabinet_template_id' => $this->template->id, 'name' => 'ประกอบ', 'sequence' => 1]);
        foreach (['บัส' => null, 'สาย' => null, 'QC' => $this->wiring->id] as $i => $dept) {
            $this->subs[$i] = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $task->id, 'name' => $i, 'department_id' => $dept, 'sequence' => count($this->subs) + 1]);
            ChecklistTemplate::create(['cabinet_subtask_template_id' => $this->subs[$i]->id, 'name' => "$i-1", 'sequence' => 1]);
            ChecklistTemplate::create(['cabinet_subtask_template_id' => $this->subs[$i]->id, 'name' => "$i-2", 'sequence' => 2]);
        }
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'D'.++$n, 'email' => 'disp'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    private function cabinetJson(User $u, string $mo): ?array
    {
        $t = collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.$this->project->id);

        return collect($t['subtasks'] ?? [])->firstWhere('mo', $mo);
    }

    public function test_the_page_offers_the_templates_with_their_sub_tasks(): void
    {
        $config = $this->actingAs($this->user(null, 'project_manager'))->get(route('planning.board'))->viewData('config');
        $tpl = $config['templates'][0];
        $this->assertSame(['Std', true], [$tpl['name'], $tpl['default']]);
        $this->assertSame(['บัส', 'สาย', 'QC'], array_column($tpl['subtasks'], 'name'));
        $this->assertSame(['', '', 'd_'.$this->wiring->id], array_column($tpl['subtasks'], 'deptId'));
        $this->assertSame([2, 2, 2], array_column($tpl['subtasks'], 'checklists'));
        $this->assertSame([], $this->actingAs($this->user($this->busbar))->get(route('planning.board'))->viewData('config')['templates']);
    }

    public function test_a_new_cabinet_hands_its_template_sub_tasks_to_the_chosen_departments(): void
    {
        $pm = $this->user(null, 'project_manager');
        $worker = $this->user($this->busbar);

        $r = $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-9', 'text' => 'MDB-9',
            'templateId' => (string) $this->template->id, 'assign' => [(string) $this->subs['บัส']->id => 'd_'.$this->busbar->id, (string) $this->subs['สาย']->id => '']]);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('จ่ายงาน 1 งาน', $r['message']);

        $cab = Cabinet::where('mo_no', 'MO-9')->first();
        $rows = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cab->id))->get()->keyBy('name');
        $this->assertSame([$this->busbar->id, 'ASSIGNED'], [$rows['บัส']->department_id, $rows['บัส']->assignment_status]);
        $this->assertNull($rows['สาย']->department_id, 'left for later');
        $this->assertSame($this->wiring->id, $rows['QC']->department_id, "the template's own department stays");
        $this->assertSame(1, $worker->notifications()->count(), 'the department is told');

        // the checklists came with it and show for the department; PMO sees what is still free
        $this->assertSame(['บัส-1', 'บัส-2'], array_column($this->cabinetJson($worker, 'MO-9')['checklist'], 'text'));
        $this->assertSame(['สาย'], array_column($this->cabinetJson($pm, 'MO-9')['free'], 'name'));
        $this->assertSame([], $this->cabinetJson($worker, 'MO-9')['free'], 'members never get the free list');
    }

    public function test_no_template_or_another_template(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'E-1', 'text' => 'empty', 'templateId' => 'none'])['ok']);
        $this->assertSame(0, Cabinet::where('mo_no', 'E-1')->first()->tasks()->count());
        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'E-2', 'text' => 'x', 'templateId' => '999'])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'E-3', 'text' => 'default'])['ok']);
        $this->assertSame(1, Cabinet::where('mo_no', 'E-3')->first()->tasks()->count(), 'no choice = the default template');
    }

    public function test_free_sub_tasks_are_handed_out_later_from_the_page(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-9', 'text' => 'MDB-9']);
        $free = collect($this->cabinetJson($pm, 'MO-9')['free'])->keyBy('name');
        $this->assertSame(['บัส', 'สาย'], $free->keys()->all());

        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id, 'assign' => [$free['บัส']['id'] => 'd_'.$this->busbar->id]])['ok'], 'PMO roles only');
        $other = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'O', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->assertFalse($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => 'p'.$other->id, 'assign' => [$free['บัส']['id'] => 'd_'.$this->busbar->id]])['ok'], 'a sub task of another project');

        $r = $this->apply($pm, ['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id, 'assign' => [$free['บัส']['id'] => 'd_'.$this->busbar->id, $free['สาย']['id'] => 'd_'.$this->wiring->id]]);
        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('จ่ายงานแล้ว 2 งาน', $r['message']);
        $this->assertStringContainsString('แผนก Wiring ยังไม่มีผู้ใช้', $r['message'], 'nobody works in Wiring: the sender is warned');
        $this->assertStringNotContainsString('แผนก Busbar ยังไม่มีผู้ใช้', $r['message']);
        $this->assertSame([], $this->cabinetJson($pm, 'MO-9')['free']);
        $this->assertEqualsCanonicalizing(['d_'.$this->busbar->id, 'd_'.$this->wiring->id],
            array_column(collect(app(PlanningBoard::class)->tasks($pm))->firstWhere('id', 'p'.$this->project->id)['deptStages'], 'deptId'));
    }

    public function test_free_work_says_which_department_it_usually_goes_to(): void
    {
        $pm = $this->user(null, 'project_manager');
        // an earlier cabinet: "บัส" went to Busbar
        $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'OLD', 'text' => 'old', 'assign' => [(string) $this->subs['บัส']->id => 'd_'.$this->busbar->id]]);
        $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'NEW', 'text' => 'new']);

        $free = collect($this->cabinetJson($pm, 'NEW')['free'])->pluck('usual', 'name');
        $this->assertSame('d_'.$this->busbar->id, $free['บัส'], 'the same template work went to Busbar before');
        $this->assertSame('', $free['สาย'], 'no history: no suggestion');
    }

    public function test_a_suggestion_by_name_alone_works_when_the_template_has_no_history(): void
    {
        $pm = $this->user(null, 'project_manager');
        // work typed in by hand (no template) called "สาย" went to Wiring; the template's own "สาย" was never handed out
        $old = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'HAND', 'cabinet_name' => 'hand', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = \App\Models\CabinetTask::forceCreate(['cabinet_id' => $old->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        \App\Models\CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'สาย', 'department_id' => $this->wiring->id, 'assignment_status' => 'ASSIGNED', 'status' => 'not_started', 'progress' => 0]);
        $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'NEW', 'text' => 'new']);

        // (this used to break the whole board for Admin / PM: an empty Eloquent collection merged with plain numbers)
        $free = collect($this->cabinetJson($pm, 'NEW')['free'])->pluck('usual', 'name');
        $this->assertSame('d_'.$this->wiring->id, $free['สาย'], 'same name went to Wiring before');
        $this->assertSame('', $free['บัส']);
    }

    public function test_each_piece_of_work_can_get_its_own_dates_and_the_cabinet_its_own(): void
    {
        $pm = $this->user(null, 'project_manager');
        $r = $this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-D', 'text' => 'dates',
            'startDate' => '2026-10-01', 'dueDate' => '2026-10-30',
            'assign' => [
                (string) $this->subs['บัส']->id => ['dept' => 'd_'.$this->busbar->id, 'start' => '2026-10-02', 'due' => '2026-10-10'],
                (string) $this->subs['สาย']->id => ['dept' => 'd_'.$this->wiring->id, 'start' => '2026-10-11', 'due' => '2026-10-25'],
            ]]);
        $this->assertTrue($r['ok']);

        $cab = Cabinet::where('mo_no', 'MO-D')->first();
        $this->assertSame(['2026-10-01', '2026-10-30'], [$cab->start_date->format('Y-m-d'), $cab->due_date->format('Y-m-d')]);
        $rows = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cab->id))->get()->keyBy('name');
        $this->assertSame(['2026-10-02', '2026-10-10'], [$rows['บัส']->start_date->format('Y-m-d'), $rows['บัส']->due_date->format('Y-m-d')]);
        $this->assertSame(['2026-10-11', '2026-10-25'], [$rows['สาย']->start_date->format('Y-m-d'), $rows['สาย']->due_date->format('Y-m-d')]);
        $this->assertSame('2026-10-30', $this->cabinetJson($pm, 'MO-D')['ownDue']);

        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-E', 'text' => 'x', 'startDate' => '2026-10-30', 'dueDate' => '2026-10-01'])['ok']);
    }
}
