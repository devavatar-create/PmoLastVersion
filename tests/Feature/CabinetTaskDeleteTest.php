<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTask;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The cabinet page: Admin / PM delete a cabinet's Task / Sub Task (into the trash) - the template it came from stays as it is. */
class CabinetTaskDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private CabinetTemplate $template;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->template = CabinetTemplate::create(['name' => 'Std', 'code' => 'STD', 'is_default' => true, 'is_active' => true]);
        foreach (['ประกอบ', 'ทดสอบ'] as $t => $taskName) {
            $task = CabinetTaskTemplate::create(['cabinet_template_id' => $this->template->id, 'name' => $taskName, 'sequence' => $t + 1]);
            foreach (['ก', 'ข'] as $i => $sub) {
                $s = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $task->id, 'name' => "{$taskName}-{$sub}", 'department_id' => $this->busbar->id, 'sequence' => $i + 1]);
                ChecklistTemplate::create(['cabinet_subtask_template_id' => $s->id, 'name' => "{$taskName}-{$sub}-1", 'sequence' => 1]);
            }
        }
        // a cabinet made from the template, the way the app does it
        $pm = $this->user(null, 'project_manager');
        $this->assertTrue(app(PlanningSync::class)->apply($pm, [['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-1', 'text' => 'Cab']])[0]['ok']);
        $this->cabinet = Cabinet::where('mo_no', 'MO-1')->firstOrFail();
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'T'.++$n, 'email' => 'tdel'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    /** @return array{int, int, int} task / sub task / checklist templates */
    private function templateCounts(): array
    {
        return [CabinetTaskTemplate::count(), CabinetSubtaskTemplate::count(), ChecklistTemplate::count()];
    }

    public function test_admin_or_pm_deletes_a_task_with_everything_under_it_and_the_template_stays(): void
    {
        $before = $this->templateCounts();
        $task = $this->cabinet->tasks()->where('name', 'ประกอบ')->firstOrFail();
        $this->cabinet->tasks()->where('name', 'ทดสอบ')->first()->subtasks->first()->checklists->first()->update(['is_completed' => true]);
        app(\App\Services\ProgressService::class)->recalculateSubtask(CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('name', 'ทดสอบ'))->first());

        $this->actingAs($this->user($this->busbar))->delete(route('cabinet-tasks.destroy', $task))->assertForbidden();

        $this->actingAs($this->user(null, 'project_manager'))->delete(route('cabinet-tasks.destroy', $task))
            ->assertRedirect(route('cabinets.show', $this->cabinet))->assertSessionHas('success');

        $this->assertSoftDeleted($task);
        $this->assertSame(0, CabinetSubtask::where('cabinet_task_id', $task->id)->count(), 'its Sub Tasks went with it');
        $this->assertSame(2, CabinetSubtask::onlyTrashed()->where('cabinet_task_id', $task->id)->count());
        $this->assertSame(2, CabinetChecklist::onlyTrashed()->count(), 'and their Checklists');
        $this->assertSame($before, $this->templateCounts(), 'the template is untouched');
        $this->assertSame(['ประกอบ', 'ทดสอบ'], $this->template->taskTemplates()->orderBy('sequence')->pluck('name')->all());
        $this->assertSame(['ทดสอบ'], $this->cabinet->tasks()->pluck('name')->all());
        $this->assertSame(50, $this->cabinet->fresh()->progress, 'progress counts only what is left: the one Task left is 1 of 2 Sub Tasks done = 50 % (it was 25 % with both Tasks)');

        // a new cabinet from the same template still gets the whole tree
        app(PlanningSync::class)->apply($this->user(null, 'admin'), [['type' => 'cabinet_add', 'taskId' => 'p'.$this->project->id, 'mo' => 'MO-2', 'text' => 'Cab 2']]);
        $this->assertSame(['ประกอบ', 'ทดสอบ'], Cabinet::where('mo_no', 'MO-2')->first()->tasks()->orderBy('sequence')->pluck('name')->all());
    }

    public function test_a_single_sub_task_can_go_and_the_others_stay(): void
    {
        $before = $this->templateCounts();
        $sub = CabinetSubtask::where('name', 'ประกอบ-ก')->firstOrFail();

        $this->actingAs($this->user($this->busbar))->delete(route('cabinet-subtasks.destroy', $sub))->assertForbidden();
        $this->actingAs($this->user(null, 'admin'))->delete(route('cabinet-subtasks.destroy', $sub))
            ->assertRedirect(route('cabinets.show', $this->cabinet));

        $this->assertSoftDeleted($sub);
        $this->assertSame(['ประกอบ-ข'], CabinetSubtask::where('cabinet_task_id', $sub->cabinet_task_id)->pluck('name')->all());
        $this->assertSame(1, CabinetChecklist::onlyTrashed()->count());
        $this->assertSame($before, $this->templateCounts());
    }

    public function test_a_closed_project_refuses(): void
    {
        $sub = CabinetSubtask::where('name', 'ประกอบ-ก')->firstOrFail();
        $this->project->update(['status' => 'completed']);

        $this->actingAs($this->user(null, 'admin'))->delete(route('cabinet-subtasks.destroy', $sub))->assertSessionHas('error');
        $this->assertNotSoftDeleted($sub);
    }

    public function test_the_trash_lists_them_and_brings_back_exactly_what_went(): void
    {
        $admin = $this->user(null, 'admin');
        $task = $this->cabinet->tasks()->where('name', 'ประกอบ')->firstOrFail();
        $alone = CabinetSubtask::where('name', 'ทดสอบ-ก')->firstOrFail();
        $earlier = CabinetSubtask::where('name', 'ประกอบ-ข')->firstOrFail();

        $this->actingAs($admin)->delete(route('cabinet-subtasks.destroy', $earlier));   // deleted on its own first
        $this->travel(1)->seconds();
        $this->actingAs($admin)->delete(route('cabinet-tasks.destroy', $task));
        $this->actingAs($admin)->delete(route('cabinet-subtasks.destroy', $alone));

        $page = $this->actingAs($admin)->get(route('trash.index'))->assertOk();
        $page->assertSee('Task ที่ถูกลบ')->assertSee('ประกอบ')->assertSee('ทดสอบ-ก');
        $this->assertSame(['ทดสอบ-ก'], $page->viewData('subtasks')->pluck('name')->all(), 'a Sub Task gone with its Task is not listed on its own');

        $this->actingAs($admin)->post(route('trash.tasks.restore', $task->id))->assertRedirect(route('cabinets.show', $this->cabinet));
        $this->assertSame(['ประกอบ-ก'], CabinetSubtask::where('cabinet_task_id', $task->id)->pluck('name')->all(), 'the one deleted earlier stays in the trash');

        $this->actingAs($admin)->post(route('trash.subtasks.restore', $alone->id));
        $this->assertNotSoftDeleted($alone);
        $this->actingAs($this->user($this->busbar))->post(route('trash.subtasks.restore', $earlier->id))->assertForbidden();
    }

    public function test_the_cabinet_page_shows_the_delete_buttons_to_pmo_only(): void
    {
        $this->actingAs($this->user(null, 'project_manager'))->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertSee('ลบ Task นี้')->assertSee('ลบ Sub Task นี้')->assertSee('id="delete-task-'.$this->cabinet->tasks->first()->id.'"', false);
        $this->actingAs($this->user($this->busbar))->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertDontSee('ลบ Task นี้')->assertDontSee('ลบ Sub Task นี้')->assertDontSee('id="delete-task-', false)->assertDontSee('id="delete-subtask-', false);   // (update and delete share one URL, so look for the form)
    }
}
