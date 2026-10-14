<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Making "รับงาน" easy: the waiting list, notices that lead to the button (and push's own button), "waiting too long" reminders, Admin / PM acting for a department. */
class AcceptFlowTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'A'.++$n, 'email' => 'acc'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    /** A free Sub Task handed to Busbar the way the page does it. */
    private function dispatch(User $pm, string $name = 'บัส'): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $name, 'department_id' => null, 'assignment_status' => 'UNASSIGNED', 'status' => 'not_started', 'progress' => 0, 'due_date' => '2026-10-20']);
        $r = app(PlanningSync::class)->apply($pm, [['type' => 'subtask_assign', 'taskId' => 'p'.$this->project->id, 'assign' => ['s'.$s->id => 'd_'.$this->busbar->id]]])[0];
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        return $s->fresh();
    }

    private function stage(User $u): array
    {
        $t = collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.$this->project->id);

        return collect($t['deptStages'])->firstWhere('deptId', 'd_'.$this->busbar->id);
    }

    public function test_handing_out_work_stamps_when_and_the_department_sees_it_waiting(): void
    {
        $pm = $this->user(null, 'project_manager');
        $member = $this->user($this->busbar);
        $s = $this->dispatch($pm);

        $this->assertSame('2026-10-07 09:00:00', $s->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame([1, true, '2026-10-07'], [$this->stage($member)['pmo']['waiting'], $this->stage($member)['pmo']['canAccept'], $this->stage($member)['pmo']['waitingSince']]);
        $this->assertFalse($this->stage($pm)['pmo']['canAccept'], 'Admin / PM may not accept for the department unless the setting says so');
        $this->assertNotNull(CabinetSubtask::initialAssignment($this->busbar->id)['assigned_at'], 'a cabinet made from a template stamps it too');
        $this->assertNull(CabinetSubtask::initialAssignment(null)['assigned_at']);
    }

    public function test_the_new_work_notice_leads_to_the_departments_window_and_push_offers_a_button(): void
    {
        $sent = new class extends WebPushService
        {
            public array $calls = [];

            public function send(Collection $users, string $title, string $body, string $url, ?string $tag = null, array $extra = []): void
            {
                $this->calls[] = compact('url', 'extra');
            }
        };
        $this->app->instance(WebPushService::class, $sent);
        $member = $this->user($this->busbar);
        $this->dispatch($this->user(null, 'admin'));

        $url = $member->notifications()->first()->data['url'];
        $this->assertStringContainsString('project='.$this->project->id, $url);
        $this->assertStringContainsString('stage=d_'.$this->busbar->id, $url);
        $this->assertSame(['accept' => true], $sent->calls[0]['extra'], 'the push notice gets a "รับงาน" button');
        $this->assertStringContainsString("action: 'accept'", file_get_contents(public_path('sw.js')));

        $config = $this->actingAs($member)->get(route('planning.board', ['project' => $this->project->id, 'stage' => 'd_'.$this->busbar->id, 'accept' => 1]))->viewData('config');
        $this->assertSame(['d_'.$this->busbar->id, true], [$config['openStage'], $config['autoAccept']]);
        $this->assertNull($this->actingAs($member)->get(route('planning.board', ['stage' => 'x<script>']))->viewData('config')['openStage']);
    }

    public function test_work_waiting_too_long_is_reminded_once_a_day_to_the_department_and_the_pm(): void
    {
        $pm = $this->user(null, 'project_manager');
        $member = $this->user($this->busbar);
        $this->project->update(['project_manager_id' => $pm->id]);
        $this->dispatch($pm);
        $member->notifications()->delete();

        $this->travel(1)->days();
        app(NotificationService::class)->sendReminders();
        $this->assertSame(0, $member->notifications()->where('data', 'like', '%waiting%')->count(), 'not yet: 2 days by default');

        $this->travel(1)->days();
        app(NotificationService::class)->sendReminders();
        $this->assertStringContainsString('📥 รอรับงาน 1 งาน (นานสุด 2 วัน)', $member->notifications()->first()->data['body']);
        $this->assertStringContainsString('stage=d_'.$this->busbar->id, $member->notifications()->first()->data['url']);
        $this->assertStringContainsString('ยังไม่มีคนรับงาน 1 งาน', $pm->notifications()->latest()->first()->data['latest']);

        $before = [$member->notifications()->count(), $pm->notifications()->count()];
        app(NotificationService::class)->sendReminders();
        $this->assertSame($before, [$member->notifications()->count(), $pm->notifications()->count()], 'once a day');

        AppSetting::put('waiting_reminder_days', 0);
        $this->travel(1)->days();
        $member->notifications()->update(['read_at' => now()]);
        app(NotificationService::class)->sendReminders();
        $this->assertSame(0, $member->notifications()->whereNull('read_at')->count(), '0 = off');
    }

    public function test_admin_or_pm_can_act_for_a_department_only_when_allowed_and_it_is_logged(): void
    {
        $pm = $this->user(null, 'project_manager');
        $this->dispatch($pm);
        $op = ['type' => 'stage_step', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$this->busbar->id, 'step' => 'accept'];

        $this->assertFalse(app(PlanningSync::class)->apply($pm, [$op])[0]['ok']);

        AppSetting::put('pmo_acts_for_departments', true);
        $this->assertTrue($this->stage($pm)['pmo']['canAccept']);
        $this->assertTrue(app(PlanningSync::class)->apply($pm, [$op])[0]['ok']);
        $this->assertSame('ACCEPTED', CabinetSubtask::first()->assignment_status);
        $this->assertStringContainsString("(ทำแทนโดย {$pm->name})", ActivityLog::where('action', 'accepted')->latest('id')->value('description'));
        $this->assertFalse(app(PlanningSync::class)->apply($this->user(Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true])), [$op])[0]['ok'], 'another department still may not');
    }

    public function test_the_settings_page_saves_both_switches(): void
    {
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->get(route('settings.rules.edit'))->assertOk()->assertSee('การรับงาน')->assertSee('ทำแทนโดย');
        $this->actingAs($admin)->put(route('settings.rules.update'), ['waiting_reminder_days' => 5, 'pmo_acts_for_departments' => 1])->assertSessionHasNoErrors();
        $this->assertSame([5, true], [AppSetting::get('waiting_reminder_days'), AppSetting::get('pmo_acts_for_departments')]);
        $this->actingAs($admin)->put(route('settings.rules.update'), ['waiting_reminder_days' => 99])->assertSessionHasErrors('waiting_reminder_days');
    }
}
