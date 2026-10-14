<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTask;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\CabinetTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $engineering;

    private Department $wiring;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->engineering = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(Department $department, string $name = 'User', string $role = User::ROLE_MEMBER): User
    {
        static $n = 0;

        return User::forceCreate(['name' => $name, 'email' => 'user'.++$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department->id, 'is_active' => true]);
    }

    private function admin(Department $department, string $name = 'Admin'): User
    {
        return $this->user($department, $name, User::ROLE_ADMIN);
    }

    private function subtask(?Department $department, string $status = CabinetSubtask::ASSIGNMENT_ASSIGNED): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id,
            'name' => 'Prepare Busbar',
            'department_id' => $department?->id,
            'assignment_status' => $department ? $status : CabinetSubtask::ASSIGNMENT_UNASSIGNED,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    public function test_department_can_be_changed_while_assigned_and_is_logged(): void
    {
        $admin = $this->admin($this->engineering);
        $subtask = $this->subtask($this->busbar);

        $this->actingAs($admin)
            ->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => $this->engineering->id])
            ->assertOk()
            ->assertJsonPath('message', 'เปลี่ยนแผนกเรียบร้อย')
            ->assertJsonPath('assignment.department_name', 'Engineering');

        $subtask->refresh();
        $this->assertSame($this->engineering->id, $subtask->department_id);
        $this->assertSame('ASSIGNED', $subtask->assignment_status);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $subtask->id, 'action' => 'department_changed', 'user_id' => $admin->id]);
    }

    public function test_unassigned_gets_assigned_when_department_chosen(): void
    {
        $subtask = $this->subtask(null);

        $this->actingAs($this->admin($this->engineering))
            ->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => $this->busbar->id])
            ->assertOk();

        $this->assertSame('ASSIGNED', $subtask->fresh()->assignment_status);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $subtask->id, 'action' => 'assigned']);
    }

    public function test_only_own_department_may_accept_and_department_locks_immediately(): void
    {
        $subtask = $this->subtask($this->busbar);

        $this->actingAs($this->user($this->wiring))
            ->postJson(route('cabinet-subtasks.accept', $subtask))
            ->assertForbidden();
        $this->assertSame('ASSIGNED', $subtask->fresh()->assignment_status);

        $somchai = $this->user($this->busbar, 'Somchai');
        $this->actingAs($somchai)
            ->postJson(route('cabinet-subtasks.accept', $subtask))
            ->assertOk()
            ->assertJsonPath('assignment.assignment_status', 'ACCEPTED')
            ->assertJsonPath('assignment.is_department_locked', true);

        $subtask->refresh();
        $this->assertSame($somchai->id, $subtask->accepted_by);
        $this->assertNotNull($subtask->accepted_at);

        // Locked already at ACCEPTED, before "start". (Admin actor - so this is
        // testing the lock, not tripping the separate admin-only permission check.)
        $this->actingAs($this->admin($this->engineering, 'PM'))
            ->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => $this->engineering->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากงานนี้ถูกรับแล้ว');

        $this->assertSame($this->busbar->id, $subtask->fresh()->department_id);
    }

    public function test_department_stays_locked_through_in_progress_and_completed(): void
    {
        // Admin + a Busbar member in one, so this actor can both work the task
        // (accept/start/complete require department membership) and attempt
        // the department changes being tested (require the admin permission).
        $user = $this->admin($this->busbar);
        $subtask = $this->subtask($this->busbar);

        $this->actingAs($user);
        $this->postJson(route('cabinet-subtasks.start', $subtask))->assertStatus(422); // must accept first
        $this->postJson(route('cabinet-subtasks.accept', $subtask))->assertOk();
        $this->postJson(route('cabinet-subtasks.accept', $subtask))->assertStatus(422); // already accepted
        $this->postJson(route('cabinet-subtasks.start', $subtask))->assertOk();

        $subtask->refresh();
        $this->assertSame('IN_PROGRESS', $subtask->assignment_status);
        $this->assertSame($user->id, $subtask->started_by);
        $this->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => $this->wiring->id])->assertStatus(422);

        $this->postJson(route('cabinet-subtasks.complete', $subtask))->assertOk();
        $subtask->refresh();
        $this->assertSame('COMPLETED', $subtask->assignment_status);
        $this->assertSame($user->id, $subtask->completed_by);
        $this->assertNotNull($subtask->completed_at);
        $this->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => null])->assertStatus(422);

        $this->assertSame($this->busbar->id, $subtask->fresh()->department_id);
        $this->assertSame(3, ActivityLog::whereIn('action', ['accepted', 'started', 'completed'])->count());
    }

    public function test_edit_modal_endpoint_cannot_bypass_the_lock(): void
    {
        $subtask = $this->subtask($this->busbar, CabinetSubtask::ASSIGNMENT_ACCEPTED);
        $this->actingAs($this->admin($this->engineering));

        $payload = ['status' => 'not_started', 'remark' => 'x'];

        $this->put(route('cabinet-subtasks.update', $subtask), $payload + ['department_id' => $this->engineering->id])
            ->assertSessionHas('error', 'ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากงานนี้ถูกรับแล้ว');
        $this->assertSame($this->busbar->id, $subtask->fresh()->department_id);
        $this->assertNull($subtask->fresh()->remark);

        // Unchanged department (or field omitted, as a locked form does) still saves other fields.
        $this->put(route('cabinet-subtasks.update', $subtask), $payload + ['department_id' => $this->busbar->id])->assertSessionHas('success');
        $this->put(route('cabinet-subtasks.update', $subtask), $payload)->assertSessionHas('success');
        $this->assertSame('x', $subtask->fresh()->remark);
    }

    public function test_only_admin_or_pm_can_change_department(): void
    {
        $notPrivileged = $this->user($this->engineering, 'Production Lead', User::ROLE_PRODUCTION);
        $this->cabinet->project->assignees()->attach($notPrivileged->id);   // sees the project (in charge of it) - the department rule must still stop it

        // Dedicated endpoint, on an UNASSIGNED sub task (no lock in play - permission alone must stop it).
        $unassigned = $this->subtask(null);
        $this->actingAs($notPrivileged)
            ->patchJson(route('cabinet-subtasks.department', $unassigned), ['department_id' => $this->busbar->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก');
        $this->assertSame('UNASSIGNED', $unassigned->fresh()->assignment_status);

        // Same, via the Edit modal's shared update endpoint.
        $assigned = $this->subtask($this->busbar);
        $this->actingAs($notPrivileged)
            ->put(route('cabinet-subtasks.update', $assigned), ['status' => 'not_started', 'department_id' => $this->wiring->id])
            ->assertForbidden();   // the whole edit form is Admin / PM only now (route middleware 'pmo')
        $this->assertSame($this->busbar->id, $assigned->fresh()->department_id);

        // An admin (even from another department) may.
        $this->actingAs($this->admin($this->engineering))
            ->patchJson(route('cabinet-subtasks.department', $unassigned), ['department_id' => $this->busbar->id])
            ->assertOk();

        // A Project Manager may too - not just Admin.
        $pm = $this->user($this->engineering, 'PM', User::ROLE_PROJECT_MANAGER);
        $this->actingAs($pm)
            ->patchJson(route('cabinet-subtasks.department', $unassigned), ['department_id' => $this->wiring->id])
            ->assertOk();
        $this->assertSame($this->wiring->id, $unassigned->fresh()->department_id);
    }

    public function test_template_default_department_is_copied_and_template_is_untouched(): void
    {
        $template = CabinetTemplate::forceCreate(['code' => 'T-1', 'name' => 'T', 'is_active' => true]);
        $taskTemplate = CabinetTaskTemplate::forceCreate(['cabinet_template_id' => $template->id, 'name' => 'Task', 'sequence' => 1]);
        CabinetSubtaskTemplate::forceCreate(['cabinet_task_template_id' => $taskTemplate->id, 'name' => 'Prepare Busbar', 'department_id' => $this->busbar->id, 'sequence' => 1]);
        CabinetSubtaskTemplate::forceCreate(['cabinet_task_template_id' => $taskTemplate->id, 'name' => 'No dept', 'sequence' => 2]);

        $cabinet = Cabinet::forceCreate(['project_id' => $this->cabinet->project_id, 'mo_no' => 'MO-2', 'cabinet_name' => 'C2', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        app(CabinetTemplateService::class)->applyToCabinet($cabinet, $template);

        $subtasks = $cabinet->tasks()->first()->subtasks;
        $this->assertSame('ASSIGNED', $subtasks[0]->assignment_status);
        $this->assertSame($this->busbar->id, $subtasks[0]->department_id);
        $this->assertSame('UNASSIGNED', $subtasks[1]->assignment_status);

        $this->actingAs($this->admin($this->engineering))
            ->patchJson(route('cabinet-subtasks.department', $subtasks[0]), ['department_id' => $this->engineering->id])->assertOk();
        $this->assertSame($this->busbar->id, CabinetSubtaskTemplate::where('name', 'Prepare Busbar')->first()->department_id);
    }

    public function test_cabinet_copy_keeps_department_but_resets_workflow(): void
    {
        $user = $this->user($this->busbar);
        $source = $this->subtask($this->busbar);
        $source->update(['start_date' => '2026-10-01', 'due_date' => '2026-10-10']);
        $this->task->update(['start_date' => '2026-09-20', 'due_date' => '2026-10-15']);
        $this->actingAs($user);
        $this->postJson(route('cabinet-subtasks.accept', $source))->assertOk();
        $this->postJson(route('cabinet-subtasks.start', $source))->assertOk();
        $this->subtask(null);

        $target = Cabinet::forceCreate(['project_id' => $this->cabinet->project_id, 'mo_no' => 'MO-3', 'cabinet_name' => 'C3', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        app(CabinetCopyService::class)->copyFrom($this->cabinet->load('tasks.subtasks.checklists'), $target);

        $copiedTask = $target->tasks()->first();
        [$copied, $noDept] = $copiedTask->subtasks->all();
        $this->assertSame($this->busbar->id, $copied->department_id);
        $this->assertSame('ASSIGNED', $copied->assignment_status);
        foreach (['accepted_by', 'accepted_at', 'started_by', 'started_at', 'completed_by', 'completed_at'] as $column) {
            $this->assertNull($copied->$column, $column);
        }
        $this->assertSame('UNASSIGNED', $noDept->assignment_status);

        // The schedule (start/due date) carries over at every level, but never a completed_date - the copy hasn't done any of this work yet.
        $this->assertSame('2026-09-20', $copiedTask->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-15', $copiedTask->due_date->format('Y-m-d'));
        $this->assertNull($copiedTask->completed_date);
        $this->assertSame('2026-10-01', $copied->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-10', $copied->due_date->format('Y-m-d'));
        $this->assertNull($copied->completed_date);
    }

    public function test_cabinet_page_renders_editable_and_locked_states(): void
    {
        $admin = $this->admin($this->busbar);
        $editable = $this->subtask($this->wiring);
        $locked = $this->subtask($this->busbar, CabinetSubtask::ASSIGNMENT_ACCEPTED);

        $html = $this->actingAs($admin)->get(route('cabinets.show', $this->cabinet))->assertOk()->getContent();

        $this->assertStringContainsString(route('cabinet-subtasks.department', $editable), $html);
        $this->assertStringNotContainsString(route('cabinet-subtasks.department', $locked), $html);
        $this->assertStringContainsString('ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว', $html);
        $this->assertStringContainsString(route('cabinet-subtasks.start', $locked), $html);
    }

    public function test_cabinet_page_hides_department_dropdown_from_non_privileged_users(): void
    {
        // Same department as the work, so the accept button (unrelated permission) still shows -
        // only the department dropdown itself should be gated by canDispatchWork().
        $member = $this->user($this->busbar);
        $editable = $this->subtask($this->wiring);
        $this->subtask($this->busbar);   // Busbar has work here too, so the page opens for them

        $html = $this->actingAs($member)->get(route('cabinets.show', $this->cabinet))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('cabinet-subtasks.department', $editable), $html);
        $this->assertStringContainsString('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก', $html);
        $this->assertStringContainsString('Wiring', $html);
    }

    public function test_cabinet_page_shows_department_dropdown_to_pm(): void
    {
        $pm = $this->user($this->busbar, 'PM', User::ROLE_PROJECT_MANAGER);
        $editable = $this->subtask($this->wiring);

        $html = $this->actingAs($pm)->get(route('cabinets.show', $this->cabinet))->assertOk()->getContent();

        $this->assertStringContainsString(route('cabinet-subtasks.department', $editable), $html);
    }
}
