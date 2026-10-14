<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\StatusRule;
use App\Models\User;
use App\Services\AutomationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Everything the original page did inside one page: create / copy / restore / import projects, checklist editing, comment tools, alerts, people in charge, status rules, work order, avatar, push. */
class PlanningFullPageTest extends TestCase
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
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'due_date' => '2026-10-31']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'G'.++$n, 'email' => 'full'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $status = 'ACCEPTED', array $items = ['a', 'b']): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'due_date' => '2026-10-07', 'status' => 'not_started', 'progress' => 0]);
        foreach ($items as $i => $name) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $name, 'is_completed' => false, 'sequence' => $i + 1]);
        }

        return $s;
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    private function task(User $u, ?Project $p = null): ?array
    {
        return collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.($p ?? $this->project)->id);
    }

    private function pid(): string
    {
        return 'p'.$this->project->id;
    }

    // ---- projects ----

    public function test_pmo_creates_a_project_in_the_page_and_it_opens(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'project_create', 'so' => 'N-1', 'title' => 'x', 'customer' => 'y'])['ok']);
        $this->assertFalse($this->apply($pm, ['type' => 'project_create', 'so' => 'P-1', 'title' => 'x', 'customer' => 'y'])['ok'], 'numbers are unique');
        $this->assertFalse($this->apply($pm, ['type' => 'project_create', 'so' => 'N-1', 'title' => 'x', 'customer' => 'y', 'startDate' => '2026-11-02', 'date' => '2026-11-01'])['ok']);

        $res = $this->actingAs($pm)->postJson(route('planning.sync'), ['ops' => [['type' => 'project_create', 'so' => 'N-1', 'title' => 'งานใหม่', 'customer' => 'ลูกค้า', 'date' => '2026-11-30', 'priority' => 'urgent', 'managerId' => $pm->id]]])->assertOk();
        $new = Project::where('project_no', 'N-1')->first();
        $this->assertSame(['งานใหม่', 'ลูกค้า', 'urgent', 'planning', $pm->id], [$new->project_name, $new->customer_name, $new->priority, $new->status, $new->project_manager_id]);
        $this->assertSame('p'.$new->id, $res->json('created'), 'the page opens it');
        $this->assertContains('p'.$new->id, array_column($res->json('root.boards.pmo.tasks'), 'id'));
    }

    public function test_a_project_is_copied_with_its_cabinets_and_checklists(): void
    {
        $this->subtask($this->busbar);
        $pm = $this->user(null, 'project_manager');

        $this->assertTrue($this->apply($pm, ['type' => 'project_duplicate', 'taskId' => $this->pid(), 'so' => 'P-2', 'title' => 'MDB ชุด 2'])['ok']);
        $copy = Project::where('project_no', 'P-2')->first();
        $this->assertSame('MDB ชุด 2', $copy->project_name);
        $cab = $copy->cabinets()->first();
        $this->assertSame('MO-1-P-2', $cab->mo_no);
        $this->assertSame(['a', 'b'], $cab->tasks()->first()->subtasks()->first()->checklists()->orderBy('sequence')->pluck('name')->all());
        $this->assertFalse($this->apply($pm, ['type' => 'project_duplicate', 'taskId' => $this->pid(), 'so' => 'P-2'])['ok'], 'number taken');
        $this->assertSame('MO-1-P-3', (function () use ($pm) { $this->apply($pm, ['type' => 'project_duplicate', 'taskId' => $this->pid(), 'so' => 'P-3']); return Project::where('project_no', 'P-3')->first()->cabinets()->first()->mo_no; })());
    }

    public function test_trash_works_from_the_page(): void
    {
        $pm = $this->user(null, 'project_manager');
        $member = $this->user($this->busbar);
        $this->project->delete();

        $this->assertFalse($this->apply($member, ['type' => 'project_restore', 'taskId' => $this->pid()])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'project_restore', 'taskId' => $this->pid()])['ok']);
        $this->assertNotNull(Project::find($this->project->id));
    }

    // ---- checklist items ----

    public function test_checklist_items_are_renamed_remarked_and_reordered_by_the_working_department(): void
    {
        $s = $this->subtask($this->busbar, 'ACCEPTED', ['a', 'b', 'c']);
        [$a, $b] = [$s->checklists()->where('name', 'a')->first(), $s->checklists()->where('name', 'b')->first()];
        $member = $this->user($this->busbar);

        $this->assertFalse($this->apply($this->user($this->wiring), ['type' => 'checklist_rename', 'itemId' => 'k'.$a->id, 'text' => 'x'])['ok']);
        $this->assertTrue($this->apply($member, ['type' => 'checklist_rename', 'itemId' => 'k'.$a->id, 'text' => 'ประกอบ'])['ok']);
        $this->assertTrue($this->apply($member, ['type' => 'checklist_remark', 'itemId' => 'k'.$a->id, 'remark' => 'รอของ'])['ok']);
        $this->assertTrue($this->apply($member, ['type' => 'checklist_move', 'itemId' => 'k'.$b->id, 'dir' => 'up'])['ok']);

        $this->assertSame(['b', 'ประกอบ', 'c'], $s->checklists()->orderBy('sequence')->pluck('name')->all());
        $item = collect($this->task($member)['subtasks'][0]['checklist'])->firstWhere('id', 'k'.$a->id);
        $this->assertSame('รอของ', $item['remark']);

        $s->update(['assignment_status' => 'ASSIGNED']);
        $this->assertFalse($this->apply($member, ['type' => 'checklist_rename', 'itemId' => 'k'.$a->id, 'text' => 'y'])['ok'], 'only after accepting');
    }

    // ---- comments ----

    public function test_comments_are_edited_deleted_and_answered(): void
    {
        $this->subtask($this->busbar);
        $me = $this->user($this->busbar);
        $other = $this->user($this->busbar);
        $this->apply($me, ['type' => 'comment', 'taskId' => $this->pid(), 'text' => 'first']);
        $first = ActivityLog::where('description', 'first')->first();

        $this->assertFalse($this->apply($other, ['type' => 'comment_edit', 'taskId' => $this->pid(), 'commentId' => 'a'.$first->id, 'text' => 'hacked'])['ok']);
        $this->assertTrue($this->apply($me, ['type' => 'comment_edit', 'taskId' => $this->pid(), 'commentId' => 'a'.$first->id, 'text' => 'first!'])['ok']);
        $this->assertTrue($this->apply($other, ['type' => 'comment', 'taskId' => $this->pid(), 'text' => 'answer', 'replyTo' => 'a'.$first->id])['ok']);

        $comments = collect($this->task($me)['comments'])->keyBy('text');
        $this->assertTrue($comments['first!']['edited'] && $comments['first!']['mine']);
        $this->assertSame('a'.$first->id, $comments['answer']['replyTo']);
        $this->assertFalse($comments['answer']['mine']);

        $this->assertFalse($this->apply($me, ['type' => 'comment_delete', 'taskId' => $this->pid(), 'commentId' => 'a'.ActivityLog::where('description', 'answer')->value('id')])['ok']);
        $this->assertTrue($this->apply($me, ['type' => 'comment_delete', 'taskId' => $this->pid(), 'commentId' => 'a'.$first->id])['ok']);
        $this->assertNull(ActivityLog::find($first->id));
    }

    public function test_an_alert_is_acknowledged_by_the_department_it_was_sent_to(): void
    {
        $this->subtask($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $worker = $this->user($this->busbar);
        $this->apply($pm, ['type' => 'comment', 'taskId' => $this->pid(), 'text' => 'ด่วน', 'alertDept' => 'd_'.$this->busbar->id]);
        $alert = ActivityLog::where('description', 'ด่วน')->first();

        $t = $this->task($worker);
        $this->assertTrue($t['alertPending'], 'a pin on the bar until acknowledged');
        $this->assertTrue($t['comments'][0]['alert']['forMe']);
        $this->assertSame(1, $worker->unreadNotifications()->count());

        $this->assertFalse($this->apply($pm, ['type' => 'alert_ack', 'taskId' => $this->pid(), 'commentId' => 'a'.$alert->id])['ok'], 'not their department');
        $this->assertTrue($this->apply($worker, ['type' => 'alert_ack', 'taskId' => $this->pid(), 'commentId' => 'a'.$alert->id])['ok']);
        $this->assertTrue($this->apply($worker, ['type' => 'alert_ack', 'taskId' => $this->pid(), 'commentId' => 'a'.$alert->id])['ok'], 'twice is harmless');

        $t = $this->task($worker);
        $this->assertFalse($t['alertPending']);
        $this->assertSame([$worker->name], array_column($t['comments'][0]['alert']['acks'], 'name'));
        $this->assertSame(0, $worker->unreadNotifications()->count(), 'its notice is read');
    }

    // ---- people in charge, cabinet status ----

    public function test_people_in_charge_see_the_project_and_are_told(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);
        $outsider = $this->user($this->wiring);
        $this->assertNull($this->task($outsider));

        $pm = $this->user(null, 'project_manager');
        $this->assertFalse($this->apply($member, ['type' => 'assignees_set', 'taskId' => $this->pid(), 'userIds' => [$outsider->id]])['ok'], 'Admin / PM only');
        $this->assertTrue($this->apply($pm, ['type' => 'assignees_set', 'taskId' => $this->pid(), 'userIds' => [$outsider->id, 99999]])['ok']);
        $this->assertSame([$outsider->id], $this->task($member)['owners']);
        $this->assertNotNull($this->task($outsider), 'a person in charge sees the project');
        $this->assertSame(1, $outsider->notifications()->count());
        $this->assertContains($this->project->id, app(PlanningBoard::class)->mineProjectIds($outsider));

        $this->assertTrue($this->apply($pm, ['type' => 'assignees_set', 'taskId' => $this->pid(), 'userIds' => [$outsider->id]])['ok']);
        $this->assertSame(1, $outsider->notifications()->count(), 'not told again');
    }

    public function test_a_cabinet_gets_its_own_status(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);
        $done = BoardStatus::where('key', 'done')->first();

        $pm = $this->user(null, 'project_manager');
        $this->assertFalse($this->apply($member, ['type' => 'cabinet_status', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'statusId' => 's_done'])['ok'], 'Admin / PM only');
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_status', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'statusId' => 's_done'])['ok']);
        $c = $this->task($member)['subtasks'][0];
        $this->assertSame(['s_done', true], [$c['statusId'], $c['manualStatus']]);
        $this->assertSame($done->id, $this->cabinet->fresh()->board_status_id);
        $this->assertFalse($this->apply($this->user($this->wiring), ['type' => 'cabinet_status', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'statusId' => ''])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_status', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$this->cabinet->id, 'statusId' => ''])['ok']);
        $this->assertFalse($this->task($member)['subtasks'][0]['manualStatus']);
    }

    // ---- status rules ----

    public function test_status_rules_set_the_cabinet_and_project_status_after_a_tick(): void
    {
        $s = $this->subtask($this->busbar, 'IN_PROGRESS', ['ทดสอบ', 'แพ็ค']);
        $worker = $this->user($this->busbar);
        $review = BoardStatus::create(['name' => 'รอตรวจ', 'color' => '#111111', 'sort' => 9]);
        $done = BoardStatus::where('key', 'done')->first();
        StatusRule::create(['target' => 'cabinet', 'logic' => 'and', 'conditions' => [['when' => 'item', 'department_id' => $this->busbar->id, 'item' => 'ทดสอบ']], 'board_status_id' => $review->id]);
        StatusRule::create(['target' => 'project', 'logic' => 'and', 'conditions' => [['when' => 'all_done'], ['when' => 'not_status', 'status_id' => $done->id]], 'board_status_id' => $done->id]);

        $tick = fn (string $name) => $this->apply($worker, ['type' => 'checklist', 'itemId' => 'k'.$s->checklists()->where('name', $name)->value('id'), 'done' => true]);
        $tick('ทดสอบ');
        $this->assertSame($review->id, $this->cabinet->fresh()->board_status_id);
        $this->assertNull($this->project->fresh()->board_status_id, 'not all done yet');

        AppSetting::put('status_rules_locked', []);
        $tick('แพ็ค');
        $this->assertSame($done->id, $this->project->fresh()->board_status_id);
        $this->assertSame('in_progress', $this->project->fresh()->status, "PMO's own status is untouched");
    }

    public function test_a_locked_status_is_never_overwritten_and_admin_manages_rules(): void
    {
        $s = $this->subtask($this->busbar, 'IN_PROGRESS', ['x']);
        $hold = BoardStatus::create(['name' => 'พัก', 'color' => '#222222', 'sort' => 9]);
        $done = BoardStatus::where('key', 'done')->first();
        $this->cabinet->forceFill(['board_status_id' => $hold->id])->save();

        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->put(route('settings.status-rules.locked'), ['locked' => [$hold->id]])->assertRedirect();
        $this->post(route('settings.status-rules.store'), ['target' => 'cabinet', 'logic' => 'and', 'board_status_id' => $done->id, 'conditions' => [['when' => 'dept_done', 'department_id' => $this->busbar->id]]])->assertRedirect();
        $this->post(route('settings.status-rules.store'), ['target' => 'cabinet', 'logic' => 'and', 'board_status_id' => $done->id, 'conditions' => [['when' => 'item']]])->assertSessionHasErrors('conditions');
        $this->assertSame(1, StatusRule::count());
        $this->get(route('settings.status-rules.index'))->assertOk()->assertSee('เช็คลิสต์ Busbar ครบ');

        $s->checklists()->update(['is_completed' => true]);
        app(AutomationService::class)->afterChecklistTicked($s->fresh(), $this->user($this->busbar));
        $this->assertSame($hold->id, $this->cabinet->fresh()->board_status_id, 'locked');

        $this->actingAs($this->user($this->busbar))->get(route('settings.status-rules.index'))->assertForbidden();
    }

    // ---- work order, avatar, field chips, push ----

    public function test_the_work_order_lists_the_departments_work_due_by_that_day(): void
    {
        $this->subtask($this->busbar, 'ACCEPTED', ['ประกอบบัสบาร์']);
        $member = $this->user($this->busbar);

        $this->actingAs($member)->get(route('planning.work-order', ['dept' => 'd_'.$this->busbar->id, 'date' => '2026-10-07']))
            ->assertOk()->assertSee('ใบสั่งงาน · Busbar')->assertSee('MO-1')->assertSee('ประกอบบัสบาร์');
        $this->actingAs($member)->get(route('planning.work-order', ['dept' => 'd_'.$this->busbar->id, 'date' => '2026-10-06']))->assertOk()->assertSee('ไม่มีงานค้าง');
        $this->actingAs($member)->get(route('planning.work-order', ['dept' => 'd_'.$this->wiring->id, 'date' => '2026-10-07']))->assertForbidden();
    }

    public function test_a_profile_picture_shows_on_comments(): void
    {
        $this->subtask($this->busbar);
        $me = $this->user($this->busbar);
        $dir = public_path('uploads/avatars');
        $before = is_dir($dir) ? scandir($dir) : [];

        try {
            $this->actingAs($me)->post(route('profile.avatar'), ['avatar' => UploadedFile::fake()->image('me.png')])->assertRedirect(route('profile.edit'));
            $this->assertNotNull($me->fresh()->avatar_url);
            $this->apply($me->fresh(), ['type' => 'comment', 'taskId' => $this->pid(), 'text' => 'hi']);
            $this->assertSame($me->fresh()->avatar_url, $this->task($me)['comments'][0]['avatar']);
            $this->post(route('profile.avatar'), ['avatar' => UploadedFile::fake()->create('x.pdf', 5)])->assertSessionHasErrors('avatar');
            $this->post(route('profile.avatar'), ['remove' => 1])->assertRedirect();
            $this->assertNull($me->fresh()->avatar_path);
        } finally {
            foreach (array_diff(is_dir($dir) ? scandir($dir) : [], $before) as $f) {
                @unlink($dir.'/'.$f);
            }
        }
    }

    public function test_a_field_can_be_shown_on_the_calendar(): void
    {
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->post(route('settings.fields.store'), ['department_id' => $this->busbar->id, 'name' => 'รถ', 'type' => 'text']);
        $field = \App\Models\DepartmentField::first();
        $this->patch(route('settings.fields.chip', $field))->assertRedirect();
        $this->assertTrue($field->fresh()->show_on_chip);
        $dept = collect(app(PlanningBoard::class)->build($admin)['boards']['pmo']['departments'])->firstWhere('id', 'd_'.$this->busbar->id);
        $this->assertTrue($dept['fields'][0]['showOnChip']);
    }

    public function test_a_device_subscribes_to_push_and_push_is_off_without_keys(): void
    {
        $me = $this->user($this->busbar);
        $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'BPkey', 'auth' => 'authkey']];

        $this->actingAs($me)->postJson(route('push.subscribe'), $sub)->assertOk();
        $this->actingAs($me)->postJson(route('push.subscribe'), $sub)->assertOk();
        $this->assertSame(1, PushSubscription::count(), 'the same device once');
        $this->actingAs($me)->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.example', 'keys' => $sub['keys']])->assertStatus(422);

        config(['services.webpush.public_key' => null]);
        $this->postJson(route('push.test'))->assertStatus(422);
        $this->deleteJson(route('push.unsubscribe'), ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }
}
