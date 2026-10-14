<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\JobType;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Filling in everything PMO keeps from the Planning page: dates when handing out work, the project / cabinet / sub task records, what is missing, moving a whole project. */
class PlanningRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'R'.++$n, 'email' => 'rec'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function free(string $name = 'งาน'): CabinetSubtask
    {
        return CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $name, 'department_id' => null, 'assignment_status' => 'UNASSIGNED', 'status' => 'not_started', 'progress' => 0]);
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    private function pid(): string
    {
        return 'p'.$this->project->id;
    }

    public function test_work_is_handed_out_with_its_dates_and_the_date_rules_hold(): void
    {
        $pm = $this->user(null, 'project_manager');
        [$a, $b] = [$this->free('a'), $this->free('b')];

        $this->assertFalse($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => $this->pid(), 'assign' => ['s'.$a->id => ['dept' => 'd_'.$this->busbar->id, 'start' => '2026-10-20', 'due' => '2026-10-10']]])['ok'], 'due before start');
        $this->assertTrue($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => $this->pid(), 'assign' => [
            's'.$a->id => ['dept' => 'd_'.$this->busbar->id, 'start' => '2026-10-05', 'due' => '2026-10-15'],
            's'.$b->id => 'd_'.$this->wiring->id,   // the plain form still works (no dates)
        ]])['ok']);
        $this->assertSame(['2026-10-05', '2026-10-15', 'ASSIGNED'], [$a->fresh()->start_date->format('Y-m-d'), $a->fresh()->due_date->format('Y-m-d'), $a->fresh()->assignment_status]);
        $this->assertNull($b->fresh()->due_date);

        // a date-limit rule: Busbar may not be later than Wiring - refused, and nothing of the batch is handed out
        $b->update(['due_date' => '2026-10-12']);
        DateLimitRule::create(['anchor_department_id' => $this->wiring->id, 'blocked_department_id' => $this->busbar->id, 'is_active' => true]);
        $c = $this->free('c');
        $r = $this->apply($pm, ['type' => 'subtask_assign', 'taskId' => $this->pid(), 'assign' => ['s'.$c->id => ['dept' => 'd_'.$this->busbar->id, 'due' => '2026-10-20']]]);
        $this->assertFalse($r['ok']);
        $this->assertNull($c->fresh()->department_id);

        $this->assertTrue($this->apply($pm, ['type' => 'stage_add', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->busbar->id, 'start' => '2026-10-02', 'due' => '2026-10-09'])['ok'] === false, 'Busbar is already in the project');
    }

    public function test_the_project_record_is_edited_by_pmo_roles_only(): void
    {
        $this->free();
        $pm = $this->user(null, 'project_manager');
        $member = $this->user($this->busbar);
        $job = JobType::firstOrCreate(['name' => 'Service'], ['is_active' => true]);   // (the migration adds Service / ตู้ / Trading)
        $op = fn (array $f) => ['type' => 'project', 'taskId' => $this->pid(), 'fields' => $f];

        $this->assertFalse($this->apply($member, $op(['poNo' => 'PO-1']))['ok']);
        $this->assertTrue($this->apply($pm, $op(['customer' => 'ลูกค้าใหม่', 'poNo' => 'PO-1', 'soNo' => 'SO-9', 'salesPerson' => 'คุณเซลส์', 'owner' => 'คุณเจ้าของ',
            'paymentTerms' => '30 วัน', 'managerId' => (string) $pm->id, 'jobTypeId' => (string) $job->id, 'pmoStatus' => 'on_hold']))['ok']);
        $p = $this->project->fresh();
        $this->assertSame(['ลูกค้าใหม่', 'PO-1', 'SO-9', 'คุณเซลส์', 'คุณเจ้าของ', '30 วัน', $pm->id, $job->id, 'on_hold'],
            [$p->customer_name, $p->po_no, $p->sales_order_no, $p->sales_person, $p->project_owner, $p->payment_terms, $p->project_manager_id, $p->job_type_id, $p->status]);

        $this->assertFalse($this->apply($pm, $op(['customer' => '']))['ok'], 'a customer is required');
        $this->assertFalse($this->apply($pm, $op(['pmoStatus' => 'bogus']))['ok']);
        $this->assertFalse($this->apply($pm, $op(['managerId' => '99999']))['ok']);

        $info = collect(app(PlanningBoard::class)->tasks($pm))->firstWhere('id', $this->pid())['info'];
        $this->assertSame(['ลูกค้าใหม่', 'PO-1', $pm->id, 'on_hold'], [$info['customer'], $info['poNo'], $info['managerId'], $info['pmoStatus']]);
    }

    public function test_a_cabinet_and_its_sub_tasks_are_filled_in_from_the_page(): void
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'ประกอบ', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        $pm = $this->user(null, 'project_manager');
        $worker = $this->user($this->busbar);
        $colleague = $this->user($this->busbar);
        $stranger = $this->user($this->wiring);

        $this->assertFalse($this->apply($worker, ['type' => 'cabinet_info', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'fields' => ['quantity' => 3]])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_info', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'fields' => [
            'startDate' => '2026-10-02', 'dueDate' => '2026-10-28', 'expectedDate' => '2026-10-25', 'quantity' => '3', 'type' => 'MDB', 'size' => '2000x800', 'description' => 'ตู้หลัก']])['ok']);
        $c = $this->cabinet->fresh();
        $this->assertSame(['2026-10-28', 3, 'MDB', '2000x800'], [$c->due_date->format('Y-m-d'), (int) $c->quantity, $c->cabinet_type, $c->size]);
        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_info', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'fields' => ['expectedDate' => '2026-11-30']])['ok'], 'expected after due');

        $this->assertFalse($this->apply($worker, ['type' => 'subtask_info', 'taskId' => $this->pid(), 'subtaskId' => 's'.$s->id, 'fields' => ['dueDate' => '2026-10-20']])['ok'], 'dates / person / remark: Admin / PM only');
        $this->assertTrue($this->apply($pm, ['type' => 'subtask_info', 'taskId' => $this->pid(), 'subtaskId' => 's'.$s->id, 'fields' => ['startDate' => '2026-10-03', 'dueDate' => '2026-10-20', 'ownerId' => (string) $colleague->id, 'remark' => 'รอของ']])['ok']);
        $s->refresh();
        $this->assertSame(['2026-10-20', $colleague->id, 'รอของ'], [$s->due_date->format('Y-m-d'), $s->owner_id, $s->remark]);
        $this->assertFalse($this->apply($pm, ['type' => 'subtask_info', 'taskId' => $this->pid(), 'subtaskId' => 's'.$s->id, 'fields' => ['ownerId' => (string) $stranger->id]])['ok'], 'the person must be of that department');
        $this->assertFalse($this->apply($this->user($this->wiring), ['type' => 'subtask_info', 'taskId' => $this->pid(), 'subtaskId' => 's'.$s->id, 'fields' => ['remark' => 'x']])['ok']);

        // the expanded row reads it back
        $json = $this->actingAs($worker)->getJson(route('planning.cabinet', $this->cabinet))->assertOk();
        $this->assertSame('2026-10-28', $json->json('cabinet.dueDate'));
        $this->assertSame([['ประกอบ', '2026-10-20', $colleague->id, false]], array_map(fn ($r) => [$r['name'], $r['dueDate'], $r['ownerId'], $r['editable']], $json->json('subtasks')));
        $this->actingAs($this->user($this->wiring))->getJson(route('planning.cabinet', $this->cabinet))->assertForbidden();
    }

    public function test_what_is_missing_is_listed(): void
    {
        $this->free();
        CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'x', 'department_id' => $this->busbar->id, 'assignment_status' => 'ASSIGNED', 'status' => 'not_started', 'progress' => 0]);
        $w = collect(app(PlanningBoard::class)->tasks($this->user(null, 'project_manager')))->firstWhere('id', $this->pid())['warnings'];

        $this->assertSame(['undated' => 1, 'free' => 1, 'cabNoDue' => 1, 'noManager' => 1], $w);
    }

    public function test_a_whole_project_moves_together(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->cabinet->update(['start_date' => '2026-10-02', 'due_date' => '2026-10-28']);
        $open = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'o', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0, 'start_date' => '2026-10-05', 'due_date' => '2026-10-15']);
        $done = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'd', 'department_id' => $this->busbar->id, 'assignment_status' => 'COMPLETED', 'status' => 'completed', 'progress' => 100, 'due_date' => '2026-10-06']);

        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'project_shift', 'taskId' => $this->pid(), 'days' => 7])['ok']);
        $this->assertFalse($this->apply($pm, ['type' => 'project_shift', 'taskId' => $this->pid(), 'days' => 0])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'project_shift', 'taskId' => $this->pid(), 'days' => 7])['ok']);

        $this->assertSame(['2026-10-08', '2026-11-07'], [$this->project->fresh()->start_date->format('Y-m-d'), $this->project->fresh()->due_date->format('Y-m-d')]);
        $this->assertSame('2026-11-04', $this->cabinet->fresh()->due_date->format('Y-m-d'));
        $this->assertSame(['2026-10-12', '2026-10-22'], [$open->fresh()->start_date->format('Y-m-d'), $open->fresh()->due_date->format('Y-m-d')]);
        $this->assertSame('2026-10-06', $done->fresh()->due_date->format('Y-m-d'), 'finished work keeps its dates');

        $this->assertTrue($this->apply($pm, ['type' => 'project_shift', 'taskId' => $this->pid(), 'days' => -7])['ok']);
        $this->assertSame('2026-10-15', $open->fresh()->due_date->format('Y-m-d'));
    }

    public function test_a_department_has_one_range_per_project_and_new_work_inherits_it(): void
    {
        $pm = $this->user(null, 'project_manager');
        $a = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'a', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0, 'start_date' => '2026-10-02', 'due_date' => '2026-10-10']);
        $b = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'b', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        $done = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'd', 'department_id' => $this->busbar->id, 'assignment_status' => 'COMPLETED', 'status' => 'completed', 'progress' => 100, 'due_date' => '2026-10-03']);

        $stage = collect(app(\App\Services\PlanningBoard::class)->tasks($pm))->firstWhere('id', $this->pid())['deptStages'][0];
        $this->assertSame(['2026-10-02', '2026-10-10', 1], [$stage['start'], $stage['end'], $stage['undated']], 'the range of its work; one piece still has no date');

        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'stage_dates', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->busbar->id, 'start' => '2026-10-05', 'due' => '2026-10-20'])['ok']);
        $this->assertFalse($this->apply($pm, ['type' => 'stage_dates', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->busbar->id, 'start' => '2026-10-21', 'due' => '2026-10-20'])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'stage_dates', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->busbar->id, 'start' => '2026-10-05', 'due' => '2026-10-20'])['ok']);
        foreach ([$a, $b] as $s) {
            $this->assertSame(['2026-10-05', '2026-10-20'], [$s->fresh()->start_date->format('Y-m-d'), $s->fresh()->due_date->format('Y-m-d')], 'all open work of the department: one range');
        }
        $this->assertSame('2026-10-03', $done->fresh()->due_date->format('Y-m-d'), 'finished work keeps its date');

        // more work for Busbar in this project, handed out without dates: it takes the department's range
        $c = $this->free('c');
        $this->assertTrue($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => $this->pid(), 'assign' => ['s'.$c->id => 'd_'.$this->busbar->id]])['ok']);
        $this->assertSame(['2026-10-05', '2026-10-20'], [$c->fresh()->start_date->format('Y-m-d'), $c->fresh()->due_date->format('Y-m-d')]);

        $cab = collect(app(\App\Services\PlanningBoard::class)->tasks($pm))->firstWhere('id', $this->pid())['subtasks'][0];
        $this->assertSame('2026-10-20', ((array) $cab['deptRanges'])['d_'.$this->busbar->id]['end']);
    }

    public function test_work_without_a_due_date_is_reported_to_the_people_who_set_dates(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'x', 'department_id' => $this->busbar->id, 'assignment_status' => 'ASSIGNED', 'status' => 'not_started', 'progress' => 0]);

        app(\App\Services\NotificationService::class)->sendReminders();
        app(\App\Services\NotificationService::class)->sendReminders();
        $n = $pm->fresh()->notifications;
        $this->assertCount(1, $n);
        $this->assertSame('📅 ยังไม่มีวันส่ง 1 งาน', $n[0]->data['body']);
    }
}
