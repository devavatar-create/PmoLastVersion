<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $wirer;

    private User $otherDept;

    private Department $wiring;

    private Project $project;

    private CabinetSubtask $subtask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);
        $busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);

        $this->pm = $this->user('pm', User::ROLE_PROJECT_MANAGER);
        $this->wirer = $this->user('wirer', User::ROLE_MEMBER, $this->wiring);
        $this->otherDept = $this->user('busbar', User::ROLE_MEMBER, $busbar);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'T', 'customer_name' => 'C', 'status' => 'in_progress', 'project_manager_id' => $this->pm->id]);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
        $this->subtask = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'Power Wiring', 'status' => 'not_started', 'progress' => 0, 'due_date' => today()->addDay()]);
    }

    private function user(string $n, string $role, ?Department $d = null): User
    {
        return User::forceCreate(['name' => $n, 'email' => "{$n}@example.com", 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_assigning_notifies_only_that_departments_users_not_the_actor(): void
    {
        app(SubtaskAssignmentService::class)->changeDepartment($this->subtask, $this->wiring->id, $this->pm);

        $this->assertSame(1, $this->wirer->notifications()->count());
        $this->assertStringContainsString('งานใหม่ 1 งาน', $this->wirer->notifications->first()->data['body'], 'one notice per project, summarising it');
        $this->assertStringContainsString('Power Wiring', $this->wirer->notifications->first()->data['latest'], 'and what happened last');
        $this->assertSame(0, $this->otherDept->notifications()->count());
        $this->assertSame(0, $this->pm->notifications()->count());
    }

    public function test_department_progress_notifies_the_project_manager(): void
    {
        $service = app(SubtaskAssignmentService::class);
        $service->changeDepartment($this->subtask, $this->wiring->id, $this->pm);
        $service->accept($this->subtask->fresh(), $this->wirer);

        $this->assertSame('accepted', $this->pm->notifications->first()->data['kind']);
        $this->assertSame(0, $this->wirer->notifications()->where('data', 'like', '%"kind":"accepted"%')->count());
    }

    public function test_reminders_are_sent_once_and_skip_closed_projects(): void
    {
        $this->subtask->forceFill(['department_id' => $this->wiring->id, 'assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED])->saveQuietly();

        $this->artisan('pmo:send-reminders')->assertSuccessful();
        $this->artisan('pmo:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->wirer->notifications()->count());
        $this->assertSame('due_soon', $this->wirer->notifications->first()->data['kind']);

        $this->wirer->notifications()->delete();
        $this->project->update(['status' => 'completed']);
        $this->artisan('pmo:send-reminders')->assertSuccessful();
        $this->assertSame(0, $this->wirer->notifications()->count());
    }

    public function test_overdue_reminder_also_reaches_the_pm_and_pages_render(): void
    {
        $this->subtask->forceFill(['department_id' => $this->wiring->id, 'assignment_status' => CabinetSubtask::ASSIGNMENT_ACCEPTED, 'due_date' => today()->subDays(2)])->saveQuietly();

        $this->artisan('pmo:send-reminders');

        $this->assertSame('overdue', $this->pm->notifications->first()->data['kind']);

        $this->actingAs($this->pm)->get(route('notifications.index'))->assertOk()->assertSee('เกินกำหนด');
        $id = $this->pm->notifications->first()->id;
        $this->actingAs($this->pm)->post(route('notifications.read', $id))->assertRedirect();
        $this->assertSame(0, $this->pm->unreadNotifications()->count());
    }

    public function test_a_user_cannot_open_someone_elses_notification(): void
    {
        $this->artisan('pmo:send-reminders');
        $this->subtask->forceFill(['department_id' => $this->wiring->id, 'assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED])->saveQuietly();
        $this->artisan('pmo:send-reminders');
        $id = $this->wirer->notifications()->firstOrFail()->id;

        $this->actingAs($this->otherDept)->post(route('notifications.read', $id))->assertNotFound();
    }
}
