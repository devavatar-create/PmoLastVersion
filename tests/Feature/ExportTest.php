<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private CabinetSubtask $subtask;

    private Department $wiring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'exp@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->actingAs($this->admin);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => '=HYPERLINK("x")', 'customer_name' => 'บริษัท ก', 'status' => 'planning']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
        $this->subtask = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'Power Wiring', 'status' => 'not_started', 'progress' => 0,
            'department_id' => $this->wiring->id, 'assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED, 'start_date' => '2026-10-01', 'due_date' => '2026-10-10']);
    }

    private function csv($response): string
    {
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Excel needs the UTF-8 BOM for Thai text');

        return $body;
    }

    public function test_projects_csv_follows_the_list_filters_and_neutralises_formulas(): void
    {
        Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'Other', 'customer_name' => 'X', 'status' => 'completed']);

        $body = $this->csv($this->get(route('projects.export', ['status' => 'planning'])));

        $this->assertStringContainsString('P-1', $body);
        $this->assertStringNotContainsString('P-2', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringContainsString('บริษัท ก', $body);
    }

    public function test_my_department_csv_matches_the_screen_scope(): void
    {
        $body = $this->csv($this->get(route('my-department.export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'department_id' => $this->wiring->id])));

        $this->assertStringContainsString('Power Wiring', $body);
        $this->assertStringContainsString('10/10/2569', $body);

        $member = User::forceCreate(['name' => 'W', 'email' => 'w2@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_MEMBER, 'department_id' => $this->wiring->id, 'is_active' => true]);
        $other = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->actingAs($member)
            ->get(route('my-department.export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'department_id' => $other->id]))
            ->assertForbidden();
    }

    public function test_activity_csv_flattens_the_tree_with_its_path(): void
    {
        $checklist = $this->subtask->checklists()->create(['name' => 'Done', 'is_completed' => false, 'sequence' => 1]);
        app(ProgressService::class)->toggleChecklist($checklist, true, $this->admin);

        $body = $this->csv($this->get(route('projects.activity-export', $this->project)));

        $this->assertStringContainsString('MO-1 › Task › Power Wiring › Done', $body);
        $this->assertStringContainsString('ทำเครื่องหมายเสร็จ', $body);
        $this->assertStringContainsString('0/1 → 1/1', $body);

        $this->assertStringNotContainsString('MO-1 › Task', $this->csv($this->get(route('projects.activity-export', [$this->project, 'q' => 'zzz-none']))));
    }
}
