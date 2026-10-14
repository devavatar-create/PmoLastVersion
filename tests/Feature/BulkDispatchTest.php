<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dispatching many sub tasks at once: ticked rows on the cabinet page, or a whole step on the project page. */
class BulkDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Department $buy;

    private Department $qc;

    private Project $project;

    /** @var list<CabinetSubtask> */
    private array $subs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buy = Department::create(['name' => 'จัดซื้อ', 'code' => 'BUY', 'is_active' => true]);
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-B', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);

        foreach (['MO-1', 'MO-2', 'MO-3'] as $i => $mo) {
            $c = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
            $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => '9. สั่งซื้อ', 'status' => 'not_started', 'progress' => 0]);
            $this->subs[] = CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => '9.1 ออก PO', 'department_id' => $i === 2 ? $this->qc->id : null,
                'assignment_status' => $i === 2 ? 'ACCEPTED' : 'UNASSIGNED', 'status' => 'not_started', 'progress' => 0]);
        }
    }

    private function user(string $role, ?Department $d = null): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'B'.++$n, 'email' => 'bulk-'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_a_pm_dispatches_many_at_once_skipping_accepted_work_with_one_notice(): void
    {
        $buyer = $this->user('member', $this->buy);

        $this->actingAs($this->user('project_manager'))
            ->postJson(route('cabinet-subtasks.bulk-department'), ['subtask_ids' => collect($this->subs)->pluck('id')->all(), 'department_id' => $this->buy->id])
            ->assertOk()
            ->assertJson(['changed' => 2, 'locked' => 1])
            ->assertJsonPath('message', 'จ่ายงาน 2 งานให้แผนก จัดซื้อ แล้ว · ข้าม 1 งานที่แผนกรับไปแล้ว (เปลี่ยนแผนกไม่ได้)')
            ->assertJsonCount(2, 'rows');

        $this->assertSame(['ASSIGNED', 'ASSIGNED', 'ACCEPTED'], collect($this->subs)->map(fn ($s) => $s->fresh()->assignment_status)->all());
        $this->assertSame($this->qc->id, $this->subs[2]->fresh()->department_id, 'accepted work keeps its department');
        $this->assertSame(2, ActivityLog::where('action', 'assigned')->count(), 'one log line per sub task, as one by one');
        $this->assertSame(1, $buyer->notifications()->count(), 'the department hears it once');
        $this->assertStringContainsString('2 งาน', json_encode($buyer->notifications()->first()->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_only_dispatchers_may_bulk_dispatch(): void
    {
        $this->actingAs($this->user('member', $this->qc))
            ->postJson(route('cabinet-subtasks.bulk-department'), ['subtask_ids' => [$this->subs[0]->id], 'department_id' => $this->buy->id])
            ->assertForbidden();
        $this->assertSame('UNASSIGNED', $this->subs[0]->fresh()->assignment_status);
    }

    public function test_the_project_page_offers_a_step_without_the_work_already_accepted(): void
    {
        $this->project->load('cabinets.tasks.subtasks.department', 'cabinets.cabinetTemplate');
        $pm = app(ProjectOverview::class)->build($this->project, $this->user('project_manager'));
        $part = $pm['groups'][0]['steps'][0]['parts'][0];

        $this->assertTrue($pm['canDispatch']);
        $this->assertSame(['9.1 ออก PO', [$this->subs[0]->id, $this->subs[1]->id], 1], [$part['name'], $part['ids'], $part['locked']]);
        $this->assertFalse(app(ProjectOverview::class)->build($this->project, $this->user('member', $this->qc))['canDispatch']);
    }
}
