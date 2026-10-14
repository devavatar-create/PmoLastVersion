<?php

namespace Tests\Feature;

use App\Http\Middleware\RunDailyReminders;
use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningSync;
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Notices that reach people: a live bell, grouped dispatch notices, progress to whoever follows the work, comments, moved dates, reminders without cron. */
class NotificationsLiveTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'due_date' => '2026-10-31']);
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'N'.++$n, 'email' => 'live'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function sub(string $status = 'UNASSIGNED', ?Department $d = null, ?string $due = null): CabinetSubtask
    {
        return CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'งาน', 'department_id' => $d?->id, 'assignment_status' => $status, 'status' => 'not_started', 'progress' => 0, 'due_date' => $due]);
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    private function kinds(User $u): array
    {
        return $u->fresh()->notifications()->get()->map(fn ($n) => $n->data['kind'])->sort()->values()->all();
    }

    public function test_the_bell_feed_lists_the_latest_and_the_unread_urgent_ones(): void
    {
        $worker = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $this->apply($pm, ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'ด่วน', 'alertDept' => 'd_'.$this->busbar->id]);

        $feed = $this->actingAs($worker)->getJson(route('notifications.feed'))->assertOk()->json();
        $this->assertSame(1, $feed['unread']);
        $this->assertSame('alert', $feed['items'][0]['kind']);
        $this->assertSame([$feed['items'][0]['id'].':1'], $feed['urgent']);
        $this->get(route('dashboard'))->assertOk()->assertSee('pmoBell(', false);   // every page carries the live bell

        $this->get(route('notifications.index', ['filter' => 'unread']))->assertOk()->assertSee('ยังไม่อ่าน (1)')->assertSee('ด่วน');
        $worker->unreadNotifications->markAsRead();
        $this->get(route('notifications.index', ['filter' => 'unread']))->assertOk()->assertSee('อ่านครบหมดแล้ว');
    }

    public function test_handing_out_many_sub_tasks_sends_one_notice_per_department(): void
    {
        $worker = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $ids = collect(range(1, 3))->map(fn () => 's'.$this->sub()->id);

        $this->assertTrue($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id,
            'assign' => $ids->mapWithKeys(fn ($id) => [$id => ['dept' => 'd_'.$this->busbar->id, 'due' => '2026-10-20']])->all()])['ok']);

        $notes = $worker->fresh()->notifications;
        $this->assertCount(1, $notes);
        $this->assertSame('P-1 · MDB', $notes[0]->data['title'], 'the notice is the project');
        $this->assertSame('งานใหม่ 3 งาน', $notes[0]->data['body']);
        $this->assertStringContainsString('20/10/2569', $notes[0]->data['latest']);
        $this->assertSame($this->project->id, $notes[0]->data['project_id']);

        // a refused batch tells nobody
        $this->assertFalse($this->apply($pm, ['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id, 'assign' => ['s'.$this->sub()->id => 'd_'.$this->busbar->id, 's99999' => 'd_'.$this->busbar->id]])['ok']);
        $this->assertCount(1, $worker->fresh()->notifications);
    }

    public function test_progress_reaches_whoever_follows_the_work_even_without_a_pm(): void
    {
        $worker = $this->user($this->busbar);
        $dispatcher = $this->user(null, 'project_manager');
        $admin = $this->user(null, 'admin');

        // handed out by $dispatcher on the page: they hear "รับงาน", the other admin does not
        $s = $this->sub();
        $this->apply($dispatcher, ['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id, 'assign' => ['s'.$s->id => 'd_'.$this->busbar->id]]);
        app(SubtaskAssignmentService::class)->accept($s->fresh(), $worker);
        $this->assertSame(['accepted'], $this->kinds($dispatcher));
        $this->assertSame([], $this->kinds($admin));

        // nobody follows it (no PM, no people in charge, no recorded dispatcher): every Admin / PM hears it
        $orphan = $this->sub('ASSIGNED', $this->busbar);
        app(SubtaskAssignmentService::class)->accept($orphan->fresh(), $worker);
        $this->assertSame(['accepted'], $this->kinds($admin));
        $this->assertNotContains('accepted', $this->kinds($worker), 'never the person who did it');
    }

    public function test_the_last_finished_work_tells_the_people_in_charge_once(): void
    {
        $worker = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        $s = $this->sub('IN_PROGRESS', $this->busbar);
        $other = $this->sub('IN_PROGRESS', $this->busbar);

        app(SubtaskAssignmentService::class)->complete($s->fresh(), $worker);
        $this->assertSame(['completed'], $this->kinds($pm));
        app(SubtaskAssignmentService::class)->complete($other->fresh(), $worker);
        $notes = $pm->fresh()->notifications;
        $this->assertCount(1, $notes, 'both "เสร็จ" and "ทุกแผนกเสร็จ" are in the project\'s one notice');
        $this->assertSame('Busbar เสร็จ 2 · ✅ งานทุกแผนกเสร็จแล้ว', $notes[0]->data['body']);
    }

    public function test_a_comment_reaches_people_following_the_project(): void
    {
        $this->sub('ACCEPTED', $this->busbar);
        $a = $this->user($this->busbar);
        $b = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        $pid = 'p'.$this->project->id;

        $this->apply($a, ['type' => 'comment', 'taskId' => $pid, 'text' => 'first']);
        $this->assertSame(['comment'], $this->kinds($pm), 'the PM hears it');
        $this->assertSame([], $this->kinds($a), 'not the writer');
        $this->assertSame([], $this->kinds($b), 'b never wrote here and is not in charge');

        $first = ActivityLog::where('description', 'first')->first();
        $this->apply($b, ['type' => 'comment', 'taskId' => $pid, 'text' => 'answer @'.$a->name, 'replyTo' => 'a'.$first->id, 'mentions' => [$a->id]]);
        $this->assertSame(['mention'], $this->kinds($a), 'tagged: one notice, not two');
        $this->assertSame(['comment'], $this->kinds($pm), 'two comments while the first is unread: one notice');
        $this->assertStringStartsWith('คอมเมนต์ใหม่ 2', $pm->fresh()->notifications[0]->data['body']);
        $this->assertStringContainsString('answer', $pm->fresh()->notifications[0]->data['body'], 'it shows the latest one');

        $pm->unreadNotifications->markAsRead();   // read: the next comment starts a new notice
        $this->apply($a, ['type' => 'comment', 'taskId' => $pid, 'text' => 'third', 'replyTo' => 'a'.$first->id]);
        $this->assertSame(['comment', 'comment'], $this->kinds($pm));
        $this->assertTrue($pm->fresh()->notifications->contains(fn ($n) => str_starts_with($n->data['latest'] ?? '', $a->name.' ตอบกลับใน P-1')));
    }

    public function test_moved_due_dates_reach_the_department_unless_it_moved_them_itself(): void
    {
        $s = $this->sub('ACCEPTED', $this->busbar, '2026-10-20');
        $worker = $this->user($this->busbar);
        $colleague = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $op = fn (string $due) => ['type' => 'stage_due', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$this->busbar->id, 'due' => $due];

        $this->assertTrue($this->apply($pm, $op('2026-10-22'))['ok']);
        $this->assertSame(['due_changed'], $this->kinds($worker));
        $this->assertStringContainsString('22/10/2569', $worker->fresh()->notifications[0]->data['latest']);

        $this->assertFalse($this->apply($worker, $op('2026-10-23'))['ok'], 'dates are Admin / PM only');
        $this->assertSame(['due_changed'], $this->kinds($colleague));

        $this->assertTrue($this->apply($pm, ['type' => 'project_shift', 'taskId' => 'p'.$this->project->id, 'days' => 2])['ok']);
        $this->assertSame(['due_changed'], $this->kinds($colleague), 'still unread: the second change folds into the first notice');
        $this->assertSame('เลื่อนวันส่ง 2 ครั้ง', $colleague->fresh()->notifications[0]->data['body']);
    }

    public function test_a_department_with_nobody_is_flagged_to_the_sender(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->sub('ACCEPTED', $this->busbar);
        $empty = Department::create(['name' => 'ว่าง', 'code' => 'EMP', 'is_active' => true]);

        $r = $this->apply($pm, ['type' => 'comment', 'taskId' => 'p'.$this->project->id, 'text' => 'x', 'alertDept' => 'd_'.$empty->id]);
        $this->assertStringContainsString('แผนก ว่าง ยังไม่มีผู้ใช้', $r['message']);
        $this->actingAs($this->user(null, 'admin'))->get(route('settings.departments.index'))->assertSee('ยังไม่มีผู้ใช้');
        $this->get(route('settings.notifications.edit'))->assertSee('แผนกที่ยังไม่มีผู้ใช้')->assertSee('ว่าง');
    }

    public function test_daily_reminders_go_out_on_the_first_page_of_the_day_without_cron(): void
    {
        $worker = $this->user($this->busbar);
        $this->sub('ACCEPTED', $this->busbar, '2026-10-09');   // due tomorrow
        RunDailyReminders::$evenInTests = true;
        Cache::flush();

        try {
            $this->travelTo('2026-10-08 06:30:00');
            $this->actingAs($worker)->get(route('dashboard'))->assertOk();
            $this->assertSame([], $this->kinds($worker), 'not before 07:00');

            $this->travelTo('2026-10-08 08:10:00');
            $this->actingAs($worker)->get(route('dashboard'));
            $this->actingAs($worker)->get(route('dashboard'));
            $this->assertSame(['due_soon'], $this->kinds($worker), 'once, however many pages');
        } finally {
            RunDailyReminders::$evenInTests = false;
        }
    }

    public function test_the_new_personal_switches_turn_those_notices_off(): void
    {
        $s = $this->sub('ACCEPTED', $this->busbar, '2026-10-20');
        $worker = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $this->actingAs($worker)->put(route('settings.notifications.update'), ['enabled' => 1, 'progress' => 1, 'completed' => 1, 'reminders' => 1, 'comments' => 1])->assertRedirect();

        $this->apply($pm, ['type' => 'stage_due', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$this->busbar->id, 'due' => '2026-10-22']);
        $this->assertSame([], $this->kinds($worker), 'due_changes switched off');
    }

    public function test_the_key_command_prints_a_key_pair(): void
    {
        $this->artisan('webpush:keys')->expectsOutputToContain('WEBPUSH_PUBLIC_KEY=')->assertSuccessful();
    }

    public function test_a_department_accepting_many_pieces_is_one_notice_and_reminders_are_one_per_project(): void
    {
        $worker = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $this->project->update(['project_manager_id' => $pm->id]);
        foreach (range(1, 18) as $i) {
            $this->sub('ASSIGNED', $this->busbar, $i <= 10 ? '2026-10-01' : '2026-10-09');   // 10 late, 8 due tomorrow
        }

        $this->assertTrue($this->apply($worker, ['type' => 'stage_step', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$this->busbar->id, 'step' => 'accept'])['ok']);
        $notes = $pm->fresh()->notifications;
        $this->assertCount(1, $notes, 'not 18');
        $this->assertSame(['P-1 · MDB', 'Busbar รับงาน 18'], [$notes[0]->data['title'], $notes[0]->data['body']]);

        app(\App\Services\NotificationService::class)->sendReminders();
        app(\App\Services\NotificationService::class)->sendReminders();   // a second run the same day changes nothing
        $mine = $worker->fresh()->notifications;
        $this->assertCount(1, $mine, 'the worker: the project\'s one notice');
        $this->assertSame('⚠ เกินกำหนด 10 งาน (สุด 7 วัน) · ใกล้ครบกำหนด 8 งาน', $mine[0]->data['body']);
        $this->assertTrue($mine[0]->data['urgent']);
        $lead = $pm->fresh()->notifications;
        $this->assertCount(1, $lead, 'the PM: still one notice for the project');
        $this->assertSame('Busbar รับงาน 18 · ⚠ เกินกำหนด 10 งาน (สุด 7 วัน)', $lead[0]->data['body']);
    }
}
