<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);
    }

    private function user(string $role, string $name = 'User'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => $name, 'email' => 'dispatch'.++$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'is_active' => true]);
    }

    private function subtask(string $projectNo, string $mo, string $name, ?Department $department, string $status, ?string $dueDate = null): CabinetSubtask
    {
        $project = Project::firstOrCreate(['project_no' => $projectNo], ['project_name' => $projectNo, 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $cabinet = Cabinet::firstOrCreate(['mo_no' => $mo], ['project_id' => $project->id, 'cabinet_name' => $mo, 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::firstOrCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task'], ['status' => 'not_started', 'progress' => 0]);

        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $task->id,
            'name' => $name,
            'department_id' => $department?->id,
            'assignment_status' => $status,
            'due_date' => $dueDate,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    public function test_only_admin_or_pm_can_view_the_dispatch_page(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index'))->assertOk();
        $this->actingAs($this->user(User::ROLE_PROJECT_MANAGER))->get(route('assignments.index'))->assertOk();

        foreach ([User::ROLE_MEMBER, User::ROLE_PRODUCTION, User::ROLE_SALES] as $role) {
            $this->actingAs($this->user($role))->get(route('assignments.index'))->assertForbidden();
        }
    }

    public function test_lists_subtasks_across_every_project_with_an_editable_department_control(): void
    {
        $unassigned = $this->subtask('P-1', 'MO-1', 'Prepare Busbar', null, 'UNASSIGNED');
        $this->subtask('P-2', 'MO-2', 'Wire Panel', $this->wiring, 'ACCEPTED');

        $html = $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index'))
            ->assertOk()->assertSee('P-1')->assertSee('P-2')->assertSee('Prepare Busbar')->assertSee('Wire Panel')
            ->getContent();

        // UNASSIGNED row carries the real PATCH endpoint (assignable); the ACCEPTED
        // row is locked and must not offer one.
        $this->assertStringContainsString(route('cabinet-subtasks.department', $unassigned), $html);
    }

    public function test_shows_who_accepted_the_work(): void
    {
        $somchai = $this->user(User::ROLE_MEMBER, 'Somchai');
        $accepted = $this->subtask('P-1', 'MO-1', 'Prepare Busbar', $this->busbar, 'ACCEPTED');
        $accepted->forceFill(['accepted_by' => $somchai->id, 'accepted_at' => '2026-09-28 09:15:00'])->save();
        $waiting = $this->subtask('P-1', 'MO-1', 'Wire Panel', $this->busbar, 'ASSIGNED');

        $html = $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index'))
            ->assertOk()->assertSee('รับงานโดย Somchai · 28/09/2569 09:15')
            ->getContent();

        // The not-yet-accepted row's placeholder stays empty - no "accepted by" text for it.
        $start = strpos($html, 'data-accepted-by="'.$waiting->id.'"');
        $segment = substr($html, $start, strpos($html, '</p>', $start) - $start);
        $this->assertStringNotContainsString('รับงานโดย', $segment);
    }

    public function test_pending_work_is_listed_before_locked_work(): void
    {
        $this->subtask('P-1', 'MO-1', 'Already accepted', $this->busbar, 'ACCEPTED');
        $this->subtask('P-1', 'MO-1', 'Still unassigned', null, 'UNASSIGNED');

        $content = $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index'))->getContent();

        $this->assertLessThan(
            strpos($content, 'Already accepted'),
            strpos($content, 'Still unassigned')
        );
    }

    public function test_filters_by_search_status_and_department(): void
    {
        $this->subtask('P-1', 'MO-1', 'Prepare Busbar', $this->busbar, 'ASSIGNED');
        $this->subtask('P-2', 'MO-2', 'Wire Panel', $this->wiring, 'ACCEPTED');

        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('assignments.index', ['q' => 'MO-2']))
            ->assertDontSee('Prepare Busbar')->assertSee('Wire Panel');

        $this->actingAs($admin)->get(route('assignments.index', ['status' => 'ACCEPTED']))
            ->assertDontSee('Prepare Busbar')->assertSee('Wire Panel');

        $this->actingAs($admin)->get(route('assignments.index', ['department_id' => $this->busbar->id]))
            ->assertSee('Prepare Busbar')->assertDontSee('Wire Panel');
    }

    public function test_urgent_filter_shows_only_overdue_or_near_due_unfinished_work(): void
    {
        $this->travelTo('2026-09-28 09:00:00');

        $overdue = $this->subtask('P-1', 'MO-1', 'Overdue job', $this->busbar, 'ACCEPTED', '2026-09-20');
        $nearDue = $this->subtask('P-1', 'MO-1', 'Near due job', $this->busbar, 'ASSIGNED', '2026-09-30');
        $farOut = $this->subtask('P-1', 'MO-1', 'Far out job', $this->busbar, 'ASSIGNED', '2026-10-15');
        $noDueDate = $this->subtask('P-1', 'MO-1', 'No due date job', null, 'UNASSIGNED');
        $overdueButDone = $this->subtask('P-1', 'MO-1', 'Overdue but done job', $this->busbar, 'COMPLETED', '2026-09-01');

        $response = $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index', ['urgent' => 1]))->assertOk();

        $response->assertSee('Overdue job')->assertSee('Near due job');
        $response->assertDontSee('Far out job')->assertDontSee('No due date job')->assertDontSee('Overdue but done job');

        // Off by default, and combinable with the other filters.
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('assignments.index'))->assertSee('Far out job');
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get(route('assignments.index', ['urgent' => 1, 'department_id' => $this->wiring->id]))
            ->assertDontSee('Overdue job'); // right due date, wrong department
    }

    public function test_dispatching_from_this_page_uses_the_same_locking_rule(): void
    {
        $subtask = $this->subtask('P-1', 'MO-1', 'Prepare Busbar', null, 'UNASSIGNED');
        $pm = $this->user(User::ROLE_PROJECT_MANAGER);

        $this->actingAs($pm)
            ->patchJson(route('cabinet-subtasks.department', $subtask), ['department_id' => $this->busbar->id])
            ->assertOk();
        $this->assertSame('ASSIGNED', $subtask->fresh()->assignment_status);
    }

    public function test_sidebar_link_only_shows_for_admin_and_pm(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('dashboard'))->assertSee(route('assignments.index'), false);
        $this->actingAs($this->user(User::ROLE_MEMBER))->get(route('dashboard'))->assertDontSee(route('assignments.index'), false);
    }
}
