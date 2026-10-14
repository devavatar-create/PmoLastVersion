<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Project data and dates: Admin / Project Manager only, on every page. Departments keep doing their work. */
class EditPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetSubtask $subtask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        $this->subtask = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'S', 'department_id' => $this->busbar->id, 'assignment_status' => 'ASSIGNED', 'status' => 'not_started', 'progress' => 0]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->subtask->id, 'name' => 'c', 'is_completed' => false, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'E'.++$n, 'email' => 'perm'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_every_route_that_changes_project_data_is_admin_or_pm_only(): void
    {
        $guarded = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('pmo', $r->gatherMiddleware(), true))->map->getName()->values();
        foreach (['projects.create', 'projects.store', 'projects.edit', 'projects.update', 'projects.destroy', 'project-tasks.store', 'project-tasks.update', 'project-tasks.destroy',
            'project-attachments.store', 'project-attachments.destroy', 'cabinets.store', 'cabinets.copy', 'cabinets.edit', 'cabinets.update', 'cabinets.destroy',
            'cabinet-attachments.store', 'cabinet-attachments.destroy', 'cabinet-tasks.update', 'cabinet-subtasks.update'] as $name) {
            $this->assertContains($name, $guarded, "$name must be Admin / PM only");
        }
        $templates = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('perm:edit.templates', $r->gatherMiddleware(), true))->map->getName()->values();
        foreach (['cabinet-templates.create', 'cabinet-templates.store', 'cabinet-templates.edit', 'cabinet-templates.update', 'cabinet-templates.destroy',
            'task-templates.store', 'task-templates.update', 'task-templates.destroy', 'subtask-templates.store', 'checklist-templates.store', 'cabinet-templates.import'] as $name) {
            $this->assertContains($name, $templates, "$name needs edit.templates (Settings > สิทธิ์การใช้งาน)");
        }
        foreach (['projects.index', 'projects.show', 'cabinets.show', 'cabinet-templates.index', 'cabinet-templates.show', 'projects.notes.store', 'cabinet-subtasks.accept', 'checklists.toggle', 'checklists.store'] as $name) {
            $this->assertNotContains($name, $guarded, "$name stays open (reading / the department's work)");
        }
    }

    public function test_a_member_is_refused_and_does_not_see_the_buttons(): void
    {
        $member = $this->user($this->busbar);
        $this->actingAs($member);

        $this->put(route('projects.update', $this->project), ['project_name' => 'x'])->assertForbidden();
        $this->delete(route('projects.destroy', $this->project))->assertForbidden();
        $this->put(route('cabinet-subtasks.update', $this->subtask), ['status' => 'not_started', 'due_date' => '2026-12-01'])->assertForbidden();
        $this->get(route('cabinet-templates.create'))->assertForbidden();
        $this->assertNotNull(Project::find($this->project->id));

        $this->get(route('projects.show', $this->project))->assertOk()->assertDontSee(route('projects.edit', $this->project))->assertDontSee('+ Add Cabinet');
        $this->get(route('cabinets.show', $this->cabinet))->assertOk()->assertDontSee(route('cabinets.edit', $this->cabinet));
        $this->get(route('projects.index'))->assertOk()->assertDontSee(route('projects.create'));
        $this->assertFalse($this->get(route('planning.board'))->viewData('config')['canEdit']);

        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->get(route('projects.show', $this->project))->assertSee(route('projects.edit', $this->project))->assertSee('+ Add Cabinet');
    }

    public function test_the_department_still_does_its_work(): void
    {
        $member = $this->user($this->busbar);
        $sync = app(PlanningSync::class);
        $pid = 'p'.$this->project->id;
        $field = DepartmentField::create(['department_id' => $this->busbar->id, 'name' => 'รถ', 'type' => 'text']);

        $this->assertTrue($sync->apply($member, [['type' => 'stage_step', 'taskId' => $pid, 'deptId' => 'd_'.$this->busbar->id, 'step' => 'accept']])[0]['ok'], 'accept');
        $item = $this->subtask->checklists()->first();
        $this->assertTrue($sync->apply($member, [['type' => 'checklist', 'itemId' => 'k'.$item->id, 'done' => true]])[0]['ok'], 'tick');
        $this->assertTrue($sync->apply($member, [['type' => 'checklist_add', 'taskId' => $pid, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => 'd_'.$this->busbar->id, 'text' => 'เพิ่ม']])[0]['ok'], 'manage checklist');
        $this->assertTrue($sync->apply($member, [['type' => 'checklist_rename', 'itemId' => 'k'.$item->id, 'text' => 'ใหม่']])[0]['ok']);
        $this->assertTrue($sync->apply($member, [['type' => 'stage_field', 'taskId' => $pid, 'deptId' => 'd_'.$this->busbar->id, 'fieldId' => 'f'.$field->id, 'value' => 'กข-1']])[0]['ok'], 'extra fields');
        $this->assertTrue($sync->apply($member, [['type' => 'comment', 'taskId' => $pid, 'text' => 'hi']])[0]['ok'], 'comment');

        foreach ([['type' => 'project', 'taskId' => $pid, 'fields' => ['date' => '2026-12-01']], ['type' => 'stage_due', 'taskId' => $pid, 'deptId' => 'd_'.$this->busbar->id, 'due' => '2026-12-01'],
            ['type' => 'labels_set', 'taskId' => $pid, 'labels' => []], ['type' => 'cabinet_status', 'taskId' => $pid, 'cabinetId' => 'c'.$this->cabinet->id, 'statusId' => 's_done']] as $op) {
            $this->assertFalse($sync->apply($member, [$op])[0]['ok'], $op['type'].' is Admin / PM only');
        }
    }
}
