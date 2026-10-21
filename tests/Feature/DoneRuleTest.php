<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\DepartmentDashboard;
use App\Services\ExecutiveReport;
use App\Services\ProjectOverview;
use App\Services\StatusReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One rule for "done" and "late" on every page (PRODUCT.md "one number everywhere"): a sub task is done when its
 * checklist is complete OR its department pressed "เสร็จงาน", and late only from the day after its due date.
 */
class DoneRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_counts_the_same_done_and_late_work(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(9, 0));
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $admin = User::forceCreate(['name' => 'A', 'email' => 'done-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-09-01', 'due_date' => '2026-12-30']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'RMU', 'quantity' => 1, 'status' => 'in_progress', 'progress' => 50, 'due_date' => '2026-12-01']);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => '1. QC', 'status' => 'in_progress', 'progress' => 50, 'sequence' => 0]);
        $sub = fn (string $name, string $status, string $assignment, string $due) => CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $name,
            'department_id' => $qc->id, 'status' => $status, 'assignment_status' => $assignment, 'progress' => $status === 'completed' ? 100 : 50, 'due_date' => $due]);

        $ticked = $sub('checklist complete, button not pressed', 'completed', 'ASSIGNED', '2026-10-09');
        $pressed = $sub('button pressed, checklist not complete', 'in_progress', 'COMPLETED', '2026-10-09');
        $late = $sub('late', 'in_progress', 'IN_PROGRESS', '2026-10-16');
        $today = $sub('due today', 'in_progress', 'ASSIGNED', '2026-10-19');

        $this->assertSame([true, true, false, false], [$ticked->isDone(), $pressed->isDone(), $late->isDone(), $today->isDone()]);
        $this->assertSame([false, false, true, false], [$ticked->is_overdue, $pressed->is_overdue, $late->is_overdue, $today->is_overdue], 'the due day itself is not late');

        $exec = app(ExecutiveReport::class)->build($admin);
        $this->assertSame(1, $exec['kpis']['lateSubtasks'], 'dashboard overview / CEO report');
        $this->assertSame([['QC', 1]], array_map(fn ($d) => [$d['name'], $d['late']], $exec['departments']));

        $overview = app(ProjectOverview::class)->build($project, $admin);
        $this->assertSame(1, collect($overview['alerts'])->firstWhere('key', 'late')['count'], 'project page');

        $dept = app(DepartmentDashboard::class)->build(null, today())['totals'];
        $this->assertSame([1, 2], [$dept['overdue'], $dept['completed']], 'department tab');

        $popup = app(StatusReport::class)->build($admin);
        $this->assertEqualsCanonicalizing([$late->id, $today->id], array_column($popup['items'], 'id'), 'the popup chases only unfinished work');
    }
}
