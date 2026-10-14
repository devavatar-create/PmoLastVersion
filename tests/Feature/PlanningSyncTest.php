<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The edits made on /planning go through PMO's own rules: what is allowed is applied, what is not bounces back with a reason. */
class PlanningSyncTest extends TestCase
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
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'sync'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $status = 'ACCEPTED', string $due = '2026-10-20', int $checks = 2): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'start_date' => '2026-10-02', 'due_date' => $due, 'status' => 'not_started', 'progress' => 0]);
        for ($i = 1; $i <= $checks; $i++) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => "c$i", 'is_completed' => false, 'sequence' => $i]);
        }

        return $s;
    }

    private function apply(User $user, array $op): array
    {
        return app(PlanningSync::class)->apply($user, [$op])[0];
    }

    private function dept(Department $d): string
    {
        return 'd_'.$d->id;
    }

    private function stateOf(CabinetSubtask $s): string
    {
        return $s->fresh()->assignment_status;
    }

    public function test_ticking_a_checklist_item_follows_the_workflow_rules(): void
    {
        $accepted = $this->subtask($this->busbar, 'ACCEPTED');
        $waiting = $this->subtask($this->wiring, 'ASSIGNED');
        $busbarUser = $this->user($this->busbar);
        $item = $accepted->checklists[0];

        $this->assertTrue($this->apply($busbarUser, ['type' => 'checklist', 'itemId' => 'k'.$item->id, 'done' => true])['ok']);
        $this->assertTrue($item->fresh()->is_completed);
        $this->assertSame($busbarUser->id, $item->fresh()->completed_by);
        $this->assertTrue($this->apply($busbarUser, ['type' => 'checklist', 'itemId' => 'k'.$item->id, 'done' => false])['ok']);
        $this->assertFalse($item->fresh()->is_completed);

        // not accepted yet / someone else's department / unknown id: refused, nothing changes
        $r = $this->apply($this->user($this->wiring), ['type' => 'checklist', 'itemId' => 'k'.$waiting->checklists[0]->id, 'done' => true]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('รับงาน', $r['message']);
        $this->assertFalse($this->apply($busbarUser, ['type' => 'checklist', 'itemId' => 'k'.$waiting->checklists[0]->id, 'done' => true])['ok']);
        $this->assertFalse($this->apply($busbarUser, ['type' => 'checklist', 'itemId' => 'k999999', 'done' => true])['ok']);
        $this->assertFalse($this->apply($busbarUser, ['type' => 'checklist', 'itemId' => 'oops', 'done' => true])['ok']);
        $this->assertFalse($waiting->checklists[0]->fresh()->is_completed);
    }

    public function test_checklist_items_can_be_added_and_removed_under_the_checklist_screens_rule(): void
    {
        $accepted = $this->subtask($this->busbar, 'ACCEPTED');
        $waiting = $this->subtask($this->wiring, 'ASSIGNED');
        $member = $this->user($this->busbar);
        $add = fn (Department $d, string $text) => ['type' => 'checklist_add', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => $this->dept($d), 'text' => $text];

        $this->assertTrue($this->apply($member, $add($this->busbar, '  ตรวจสี  '))['ok']);
        $new = $accepted->checklists()->where('name', 'ตรวจสี')->sole();
        $this->assertSame(3, $new->sequence, 'goes to the end of the list');
        $this->assertFalse($new->is_completed);
        $this->assertSame(0, $accepted->fresh()->progress);

        // refused: blank text / not accepted yet / another department's cabinet work / unknown department
        $this->assertFalse($this->apply($member, $add($this->busbar, '   '))['ok']);
        $r = $this->apply($this->user($this->wiring), $add($this->wiring, 'x'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('รับงาน', $r['message']);
        $this->assertFalse($this->apply($member, $add($this->wiring, 'x'))['ok']);
        $this->assertSame(0, $waiting->checklists()->where('name', 'x')->count());
        $this->assertFalse($this->apply($member, ['deptId' => 'd_9999'] + $add($this->busbar, 'x'))['ok']);

        // PMO roles may add to any department's list (the screens' rule), and long text is cut to the column size
        $pm = $this->user($this->busbar, 'project_manager');
        $this->assertTrue($this->apply($pm, $add($this->wiring, str_repeat('ก', 400)))['ok']);
        $this->assertSame(255, mb_strlen(CabinetChecklist::where('cabinet_subtask_id', $waiting->id)->orderByDesc('id')->first()->name));

        // delete
        $del = fn (int $id) => ['type' => 'checklist_delete', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$id];
        $this->assertFalse($this->apply($this->user($this->wiring), $del($new->id))['ok'], "someone else's department");
        $this->assertNotNull(CabinetChecklist::find($new->id));
        $this->assertFalse($this->apply($this->user($this->wiring), $del($waiting->checklists[0]->id))['ok'], 'not accepted yet');
        $this->assertTrue($this->apply($member, $del($new->id))['ok']);
        $this->assertNull(CabinetChecklist::find($new->id));
        $this->assertFalse($this->apply($member, $del($new->id))['ok'], 'already gone');

        // progress follows: tick everything left, then add one -> no longer 100%
        foreach ($accepted->fresh()->checklists as $item) {
            $this->apply($member, ['type' => 'checklist', 'itemId' => 'k'.$item->id, 'done' => true]);
        }
        $this->assertSame(100, $accepted->fresh()->progress);
        $this->apply($member, $add($this->busbar, 'อีกหนึ่งข้อ'));
        $this->assertLessThan(100, $accepted->fresh()->progress);
    }

    public function test_adding_or_removing_checklist_items_is_blocked_on_a_closed_project(): void
    {
        $accepted = $this->subtask($this->busbar, 'ACCEPTED');
        $pm = $this->user($this->busbar, 'project_manager');
        $this->project->update(['status' => 'completed']);

        $r = $this->apply($pm, ['type' => 'checklist_add', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => $this->dept($this->busbar), 'text' => 'x']);
        $this->assertFalse($r['ok']);
        $this->assertFalse($this->apply($pm, ['type' => 'checklist_delete', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$accepted->checklists[0]->id])['ok']);
        $this->assertSame(2, $accepted->checklists()->count());
    }

    public function test_the_last_tick_runs_the_admins_automation_rules(): void
    {
        AutomationRule::create(['name' => 'r', 'department_id' => null, 'trigger' => 'checklist_all_done', 'action' => 'complete', 'is_active' => true]);
        $s = $this->subtask($this->busbar, 'ACCEPTED');
        $user = $this->user($this->busbar);

        foreach ($s->checklists as $item) {
            $this->apply($user, ['type' => 'checklist', 'itemId' => 'k'.$item->id, 'done' => true]);
        }

        $this->assertSame('COMPLETED', $this->stateOf($s));
    }

    public function test_workflow_buttons_accept_start_complete_for_the_department_only(): void
    {
        $a = $this->subtask($this->busbar, 'ASSIGNED');
        $b = $this->subtask($this->busbar, 'ASSIGNED');
        $other = $this->subtask($this->wiring, 'ASSIGNED');
        $member = $this->user($this->busbar);
        $op = fn (string $step) => ['type' => 'stage_step', 'taskId' => 'p'.$this->project->id, 'deptId' => $this->dept($this->busbar), 'step' => $step];

        $this->assertFalse($this->apply($member, $op('start'))['ok'], 'cannot start what is not accepted');
        $r = $this->apply($member, $op('accept'));
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('2 รายการ', $r['message']);
        $this->assertSame(['ACCEPTED', 'ACCEPTED', 'ASSIGNED'], [$this->stateOf($a), $this->stateOf($b), $this->stateOf($other)]);

        // a PM of ANOTHER department may see the stage but the workflow is the department's own
        $pm = $this->user($this->wiring, 'project_manager');
        $r = $this->apply($pm, $op('start'));
        $this->assertFalse($r['ok']);
        $this->assertSame('ACCEPTED', $this->stateOf($a));

        $this->assertTrue($this->apply($member, $op('start'))['ok']);
        $this->assertTrue($this->apply($member, $op('complete'))['ok']);
        $this->assertSame(['COMPLETED', 'COMPLETED'], [$this->stateOf($a), $this->stateOf($b)]);
        $this->assertFalse($this->apply($member, $op('complete'))['ok']);
        $this->assertFalse($this->apply($member, ['step' => 'explode'] + $op('start'))['ok']);
    }

    public function test_ticking_a_stage_done_finishes_the_whole_department_or_nothing(): void
    {
        $a = $this->subtask($this->busbar, 'ASSIGNED');
        $b = $this->subtask($this->busbar, 'IN_PROGRESS');
        $op = ['type' => 'stage_done', 'taskId' => 'p'.$this->project->id, 'deptId' => $this->dept($this->busbar)];

        // someone outside the department: refused, and the partial work is rolled back
        $this->assertFalse($this->apply($this->user($this->wiring, 'project_manager'), $op)['ok']);
        $this->assertSame(['ASSIGNED', 'IN_PROGRESS'], [$this->stateOf($a), $this->stateOf($b)]);

        $r = $this->apply($this->user($this->busbar), $op);
        $this->assertTrue($r['ok']);
        $this->assertSame(['COMPLETED', 'COMPLETED'], [$this->stateOf($a), $this->stateOf($b)]);
    }

    public function test_project_header_edits_are_for_admin_and_pm_with_priority_mapping(): void
    {
        $this->subtask($this->busbar);
        $pm = $this->user($this->busbar, 'project_manager');
        $member = $this->user($this->busbar);
        $op = fn (array $fields) => ['type' => 'project', 'taskId' => 'p'.$this->project->id, 'fields' => $fields];

        $this->assertFalse($this->apply($this->user($this->wiring), $op(['title' => 'Hacked']))['ok'], 'a department with no work in the project cannot touch it');
        $this->assertSame('MDB', $this->project->fresh()->project_name);
        $this->assertFalse($this->apply($member, $op(['description' => 'by a member']))['ok'], 'project data: Admin / PM only, even for a department working on it');

        $this->assertTrue($this->apply($pm, $op(['title' => 'New name', 'priority' => 'urgent', 'description' => 'desc', 'date' => '2026-11-15', 'startDate' => '2026-10-05']))['ok']);
        $p = $this->project->fresh();
        $this->assertSame(['New name', 'urgent', 'desc', '2026-11-15', '2026-10-05'], [$p->project_name, $p->priority, $p->description, $p->due_date->format('Y-m-d'), $p->start_date->format('Y-m-d')]);

        $this->assertTrue($this->apply($pm, $op(['priority' => 'none']))['ok']);
        $this->assertSame('normal', $this->project->fresh()->priority, 'the board\'s "none" is PMO\'s "normal"');

        $this->assertFalse($this->apply($pm, $op(['title' => '   ']))['ok'], 'a blank name is refused');
        $this->assertFalse($this->apply($pm, $op(['date' => '2026-09-01']))['ok'], 'due before start is refused');
        $this->assertFalse($this->apply($pm, $op(['priority' => 'bogus']))['ok']);
        $this->assertFalse($this->apply($pm, $op(['date' => '31/10/2026']))['ok']);
        $this->assertFalse($this->apply($pm, $op(['date' => '2026-02-30']))['ok'], 'an impossible calendar date is refused');
        $this->assertFalse($this->apply($pm, $op(['date' => ['2026-10-10']]))['ok'], 'a wrong type is refused, not an exception');
        $this->assertSame('2026-11-15', $this->project->fresh()->due_date->format('Y-m-d'));
    }

    public function test_due_dates_move_open_sub_tasks_only_and_respect_date_limits_and_locks(): void
    {
        $open = $this->subtask($this->busbar, 'ACCEPTED', '2026-10-20');
        $done = $this->subtask($this->busbar, 'COMPLETED', '2026-10-05');
        $qc = $this->subtask($this->wiring, 'ACCEPTED', '2026-10-25');
        $pm = $this->user($this->busbar, 'project_manager');
        $due = fn (string $d, ?string $dept = null) => ['type' => 'stage_due', 'taskId' => 'p'.$this->project->id, 'deptId' => $this->dept($dept ? $this->wiring : $this->busbar), 'due' => $d];

        $this->assertFalse($this->apply($this->user($this->busbar), $due('2026-10-22', 'wiring'))['ok'], "a member cannot move another department's date");
        $this->assertSame('2026-10-25', $qc->fresh()->due_date->format('Y-m-d'));
        $this->assertFalse($this->apply($this->user($this->busbar), $due('2026-10-21'))['ok'], "dates are Admin / PM only - not even the department's own");
        $this->assertSame('2026-10-20', $open->fresh()->due_date->format('Y-m-d'));

        $this->assertTrue($this->apply($pm, $due('2026-10-22'))['ok']);
        $this->assertSame(['2026-10-22', '2026-10-05', '2026-10-25'], [$open->fresh()->due_date->format('Y-m-d'), $done->fresh()->due_date->format('Y-m-d'), $qc->fresh()->due_date->format('Y-m-d')]);

        // per cabinet / per department-in-cabinet
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_dept_due', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => $this->dept($this->wiring), 'due' => '2026-10-26'])['ok']);
        $this->assertSame('2026-10-26', $qc->fresh()->due_date->format('Y-m-d'));
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_due', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'due' => '2026-10-23'])['ok']);
        $this->assertSame(['2026-10-23', '2026-10-05', '2026-10-23'], [$open->fresh()->due_date->format('Y-m-d'), $done->fresh()->due_date->format('Y-m-d'), $qc->fresh()->due_date->format('Y-m-d')]);

        // a date-limit rule: Busbar may not be later than Wiring's date in the same cabinet
        DateLimitRule::create(['anchor_department_id' => $this->wiring->id, 'blocked_department_id' => $this->busbar->id, 'is_active' => true]);
        $r = $this->apply($pm, $due('2026-12-01'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Wiring', $r['message']);
        $this->assertSame('2026-10-23', $open->fresh()->due_date->format('Y-m-d'));

        // a closed project is read-only
        $this->project->update(['status' => 'completed']);
        $this->assertFalse($this->apply($pm, $due('2026-10-21'))['ok']);
        $this->assertSame('2026-10-23', $open->fresh()->due_date->format('Y-m-d'));
    }

    public function test_comments_alerts_and_attachments(): void
    {
        $this->subtask($this->busbar);
        $this->subtask($this->wiring);
        $member = $this->user($this->busbar);
        $mate = $this->user($this->busbar);
        $wiringUser = $this->user($this->wiring);
        $pm = $this->user($this->busbar, 'project_manager');
        $op = fn (array $extra) => $extra + ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'hello'];

        $this->assertTrue($this->apply($member, $op([]))['ok']);
        $this->assertSame(['hello'], ActivityLog::where('project_id', $this->project->id)->where('action', 'note')->pluck('description')->all());

        $this->assertFalse($this->apply($member, $op(['text' => '  ']))['ok']);
        $r = $this->apply($member, $op(['text' => 'with photo', 'hadAttachment' => true]));
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('ยังไม่รองรับ', $r['message']);

        // a member's alert still saves the comment but notifies nobody; a PM's alert reaches the department (not the sender)
        $r = $this->apply($member, $op(['text' => 'alert?', 'alertDept' => $this->dept($this->wiring)]));
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $wiringUser->notifications()->count());
        $r = $this->apply($pm, $op(['text' => 'please check', 'alertDept' => $this->dept($this->busbar)]));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $mate->notifications()->count());
        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame(0, $pm->notifications()->count());

        // a department with no work in the project cannot comment
        $this->assertFalse($this->apply($this->user($this->wiring), ['taskId' => 'p999'] + $op([]))['ok']);
        $stranger = $this->user(Department::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]));
        $this->assertFalse($this->apply($stranger, $op([]))['ok']);
    }

    public function test_tagging_people_in_a_comment_notifies_them_under_the_visibility_rules(): void
    {
        $this->subtask($this->busbar);
        $this->subtask($this->wiring);   // so a Wiring user can SEE this project (a department only sees projects it has work in)
        $stranger = $this->user(Department::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]));   // no work in this project
        $sender = $this->user($this->busbar);
        $mate = $this->user($this->busbar);
        $wiringUser = $this->user($this->wiring);
        $pm = $this->user($this->wiring, 'project_manager');           // PMO role: sees every project
        $inactive = $this->user($this->busbar);
        $inactive->update(['is_active' => false]);
        $tag = fn (array $ids, string $text = 'ดูหน่อยครับ') => ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => $text, 'mentions' => $ids];

        // a department member may tag their own department and PMO roles - not themselves, not another department, not someone without access
        $r = $this->apply($sender, $tag([$mate->id, $pm->id, $sender->id, $wiringUser->id, $stranger->id, $inactive->id, 999999, 'x', null]));
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('แจ้งเตือนผู้ถูกแท็ก 2 คน', $r['message']);
        $this->assertStringContainsString('ไม่ได้แจ้ง', $r['message']);
        $this->assertSame([1, 1, 0, 0, 0, 0], [$mate->notifications()->count(), $pm->notifications()->count(), $sender->notifications()->count(), $wiringUser->notifications()->count(), $stranger->notifications()->count(), $inactive->notifications()->count()]);

        $n = $mate->notifications()->first()->data;
        $this->assertSame('mention', $n['kind']);
        $this->assertStringContainsString($sender->name, $n['title']);
        $this->assertStringContainsString('P-1', $n['title']);
        $this->assertSame('ดูหน่อยครับ', $n['body']);
        $this->assertStringContainsString('planning/board?project='.$this->project->id, $n['url'], 'the notice opens the project');
        $this->assertSame(1, ActivityLog::where('project_id', $this->project->id)->where('action', 'note')->count(), 'the comment itself is saved once');

        // a PM may tag anyone who can see the project (any department), but still not a person without access
        $r = $this->apply($pm, $tag([$wiringUser->id, $mate->id, $stranger->id]));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $wiringUser->notifications()->count());
        $this->assertSame(2, $mate->notifications()->count());
        $this->assertSame(0, $stranger->notifications()->count());

        // tags and a department alert can go in the same comment; no tags = no notices and no extra text
        $r = $this->apply($pm, $tag([$mate->id]) + ['alertDept' => $this->dept($this->busbar)]);
        $this->assertStringContainsString('แผนก Busbar', $r['message']);
        $this->assertStringContainsString('ผู้ถูกแท็ก 1 คน', $r['message']);
        $before = $mate->notifications()->count();
        $this->assertNull($this->apply($sender, ['mentions' => []] + $tag([]))['message']);
        $this->assertSame($before, $mate->notifications()->count());
        $this->assertTrue($this->apply($sender, ['mentions' => 'not-a-list'] + $tag([]))['ok'], 'a malformed list is ignored, never an exception');
    }

    public function test_a_tag_pops_up_as_an_urgent_alert_for_the_tagged_person(): void
    {
        $this->subtask($this->busbar);
        $sender = $this->user($this->busbar, 'project_manager');
        $mate = $this->user($this->busbar);

        $this->apply($sender, ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'ด่วน', 'mentions' => [$mate->id]]);

        $alerts = $this->actingAs($mate)->getJson(route('status-report.show'))->json('alerts');
        $this->assertSame(['mention'], array_column($alerts, 'kind'));
        $this->actingAs($mate)->postJson(route('status-report.acknowledge'), ['ids' => array_column($alerts, 'id')])->assertOk();
        $this->assertSame([], $this->actingAs($mate)->getJson(route('status-report.show'))->json('alerts'));
    }

    public function test_delete_unsupported_and_unknown_operations(): void
    {
        $this->subtask($this->busbar);
        $pm = $this->user($this->busbar, 'project_manager');

        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'delete', 'taskId' => 'p'.$this->project->id])['ok']);
        $this->assertNotNull(Project::find($this->project->id));

        $this->assertTrue($this->apply($pm, ['type' => 'delete', 'taskId' => 'p'.$this->project->id])['ok']);
        $this->assertNull(Project::find($this->project->id));
        $this->assertNotNull(Project::withTrashed()->find($this->project->id), 'it went to the trash, not away');

        $r = $this->apply($pm, ['type' => 'unsupported', 'what' => 'เพิ่ม/ลบตู้ทำในหน้านี้ไม่ได้']);
        $this->assertFalse($r['ok']);
        $this->assertSame('เพิ่ม/ลบตู้ทำในหน้านี้ไม่ได้', $r['message']);
        $this->assertFalse($this->apply($pm, ['type' => 'drop_database'])['ok']);
        $this->assertFalse($this->apply($pm, [])['ok']);
    }

    public function test_each_operation_is_independent(): void
    {
        $s = $this->subtask($this->busbar, 'ACCEPTED');
        $member = $this->user($this->busbar);

        $results = app(PlanningSync::class)->apply($member, [
            ['type' => 'delete', 'taskId' => 'p'.$this->project->id],                                   // refused (not PMO)
            ['type' => 'checklist', 'itemId' => 'k'.$s->checklists[0]->id, 'done' => true],              // fine
            'garbage',                                                                                    // refused
            ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'still saved'],           // fine
        ]);

        $this->assertSame([false, true, false, true], array_column($results, 'ok'));
        $this->assertTrue($s->checklists[0]->fresh()->is_completed);
        $this->assertSame('MDB', $this->project->fresh()->project_name);
    }

    public function test_the_sync_endpoint_answers_with_only_the_edited_projects(): void
    {
        $s = $this->subtask($this->busbar, 'ACCEPTED');
        $otherProject = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'Other', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cab2 = Cabinet::forceCreate(['project_id' => $otherProject->id, 'mo_no' => 'MO-2', 'cabinet_name' => 'Cab2', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task2 = CabinetTask::forceCreate(['cabinet_id' => $cab2->id, 'name' => 'T2', 'status' => 'not_started', 'progress' => 0]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $task2->id, 'name' => 'x', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        $member = $this->user($this->busbar);

        $this->postJson(route('planning.sync'), ['ops' => []])->assertUnauthorized();

        $res = $this->actingAs($member)->postJson(route('planning.sync'), ['ops' => [
            ['type' => 'checklist', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$s->checklists[0]->id, 'done' => true],
            ['type' => 'delete', 'taskId' => 'p'.$this->project->id],
        ]])->assertOk();

        $this->assertSame([true, false], $res->json('results.*.ok'));
        $this->assertTrue($res->json('partial'));
        $this->assertNull($res->json('root'), 'no whole board in a partial answer');
        $this->assertSame(['p'.$this->project->id], array_column($res->json('tasks'), 'id'), 'only the edited project, not P-2');
        $this->assertSame([], $res->json('removed'));
        $items = $res->json('tasks.0.subtasks.0.checklist');
        $this->assertTrue(collect($items)->firstWhere('id', 'k'.$s->checklists[0]->id)['done'], 'the answer already shows the accepted tick');
        $this->assertSame('MDB', $res->json('tasks.0.title'), 'and the refused delete left the project alone');

        // a deleted / invisible project comes back as `removed`
        $pm = $this->user($this->busbar, 'project_manager');
        $res = $this->actingAs($pm)->postJson(route('planning.sync'), ['ops' => [['type' => 'delete', 'taskId' => 'p'.$otherProject->id]]])->assertOk();
        $this->assertSame([], $res->json('tasks'));
        $this->assertSame(['p'.$otherProject->id], $res->json('removed'));
        $res = $this->actingAs($this->user($this->wiring))->postJson(route('planning.sync'), ['ops' => [['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'x']]])->assertOk();
        $this->assertFalse($res->json('results.0.ok'));
        $this->assertSame(['p'.$this->project->id], $res->json('removed'), 'a project this user cannot see is reported as removed, never sent');

        // an operation that cannot be tied to a project -> the whole board, to stay correct
        $res = $this->actingAs($member)->postJson(route('planning.sync'), ['ops' => [['type' => 'unsupported', 'what' => 'x']]])->assertOk();
        $this->assertNull($res->json('partial'));
        $this->assertSame('pmo', $res->json('root.activeBoard'));

        $this->actingAs($member)->postJson(route('planning.sync'), [])->assertUnprocessable();
        $this->actingAs($member)->postJson(route('planning.sync'), ['ops' => array_fill(0, 201, ['type' => 'x'])])->assertUnprocessable();
    }

    public function test_data_endpoint_uses_an_etag_so_unchanged_polls_cost_nothing(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);
        $this->app->terminate();   // the set-up's saves belong to a request that has ended

        $first = $this->actingAs($member)->getJson(route('planning.data'))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame('P-1', $first->json('root.boards.pmo.tasks.0.so'));

        $again = $this->actingAs($member)->withHeaders(['If-None-Match' => $etag])->get(route('planning.data'));
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        $this->travel(1)->seconds();
        CabinetSubtask::query()->update(['due_date' => '2026-12-24']);   // something changed (a raw update: no model events) -> a fresh body and a new tag
        $changed = $this->actingAs($member)->withHeaders(['If-None-Match' => $etag])->getJson(route('planning.data'))->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->assertSame('2026-12-24', $changed->json('root.boards.pmo.tasks.0.deptStages.0.end'));

        // a change in the SAME second (same counts, same updated_at) through a model: the board version catches it
        $etag = $changed->headers->get('ETag');
        $item = CabinetChecklist::first();
        $item->forceFill(['remark' => 'same second'])->save();
        $this->app->terminate();   // the request that saved it ends
        $this->assertNotSame($etag, $this->actingAs($member)->withHeaders(['If-None-Match' => $etag])->getJson(route('planning.data'))->assertOk()->headers->get('ETag'));

        // and, whatever happens, a poll rebuilds at least every STAMP_MAX_AGE seconds
        $etag = $this->actingAs($member)->getJson(route('planning.data'))->headers->get('ETag');
        $this->assertSame(304, $this->actingAs($member)->withHeaders(['If-None-Match' => $etag])->get(route('planning.data'))->status());
        $this->travel(PlanningBoard::STAMP_MAX_AGE)->seconds();
        $this->assertSame(200, $this->actingAs($member)->withHeaders(['If-None-Match' => $etag])->get(route('planning.data'))->status());

        $this->app['auth']->forgetGuards();
        $this->getJson(route('planning.data'))->assertUnauthorized();
    }

    public function test_responses_are_gzipped_when_asked_and_carry_server_timing(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);

        $plain = $this->actingAs($member)->get(route('planning.spa'));
        $this->assertNull($plain->headers->get('Content-Encoding'));

        $zipped = $this->actingAs($member)->withHeaders(['Accept-Encoding' => 'gzip, deflate'])->get(route('planning.spa'));
        $this->assertSame('gzip', $zipped->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $zipped->headers->get('Vary'));
        $this->assertSame($plain->getContent(), gzdecode($zipped->getContent()), 'identical content, only compressed');
        $this->assertLessThan(strlen($plain->getContent()), strlen($zipped->getContent()));

        $this->assertMatchesRegularExpression('/^app;dur=\d+, build;dur=\d+, db;desc="\d+ queries"$/', $plain->headers->get('Server-Timing'));
        $this->assertNotNull($this->actingAs($member)->getJson(route('planning.data'))->headers->get('Server-Timing'));
    }

    public function test_the_board_tells_each_user_which_workflow_buttons_make_sense(): void
    {
        $this->subtask($this->busbar, 'ASSIGNED');
        $this->subtask($this->busbar, 'IN_PROGRESS');
        $stage = fn (User $u) => app(PlanningBoard::class)->build($u)['boards']['pmo']['tasks'][0]['deptStages'][0]['pmo'];

        $mine = $stage($this->user($this->busbar));
        $this->assertSame([1, 0, 1, 0], [$mine['waiting'], $mine['accepted'], $mine['working'], $mine['done']]);
        $this->assertTrue($mine['canAccept']);
        $this->assertFalse($mine['canStart']);
        $this->assertTrue($mine['canComplete']);

        $pmElsewhere = $stage($this->user($this->wiring, 'project_manager'));
        $this->assertFalse($pmElsewhere['canAccept'] || $pmElsewhere['canStart'] || $pmElsewhere['canComplete'], 'the workflow belongs to the department\'s own members');
    }

    public function test_the_checklist_window_gets_task_and_sub_task_and_adds_to_the_one_picked(): void
    {
        $first = $this->subtask($this->busbar, 'ACCEPTED');
        $second = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'ประกอบ', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0, 'sequence' => 9]);
        $member = $this->user($this->busbar);

        $cab = app(PlanningBoard::class)->build($member)['boards']['pmo']['tasks'][0]['subtasks'][0];
        $this->assertSame([['T', 'Busbar', 's'.$first->id], ['T', 'ประกอบ', 's'.$second->id]], array_map(fn ($w) => [$w['task'], $w['name'], $w['id']], $cab['works']));
        $this->assertSame(['s'.$first->id, 's'.$first->id], array_column($cab['checklist'], 'subId'));

        $this->assertTrue($this->apply($member, ['type' => 'checklist_add', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => $this->dept($this->busbar), 'subtaskId' => 's'.$second->id, 'text' => 'ใหม่'])['ok']);
        $this->assertSame(['ใหม่'], $second->checklists()->pluck('name')->all(), 'into the Sub Task picked, not the department\'s first one');
        $this->assertFalse($this->apply($member, ['type' => 'checklist_add', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'subtaskId' => 's99999', 'text' => 'x'])['ok']);
    }
}
