<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The project page's "ข้อมูลโครงการ" tab: progress and cabinet counts, the alert icons, the cabinet / MO table. */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Department $draw;

    private Department $buy;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 14)->startOfDay());   // a Wednesday

        $this->draw = Department::create(['name' => 'เขียนแบบ', 'code' => 'DRW', 'is_active' => true]);
        $this->buy = Department::create(['name' => 'จัดซื้อ', 'code' => 'BUY', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'ถนนวิทยุ', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'urgent',
            'start_date' => '2026-09-08', 'due_date' => '2026-10-23']);
    }

    /** A cabinet with two steps: drawing (its sub task late by $lateDays when > 0, else due in 2 days) and buying (not dispatched). */
    private function cabinet(string $mo, ?string $due, string $status = 'in_progress', int $lateDays = 0, ?User $owner = null): Cabinet
    {
        $done = $status === 'completed';
        $c = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'RMU', 'cabinet_type' => 'LOCAL', 'quantity' => 1,
            'status' => $status, 'progress' => $done ? 100 : 25, 'due_date' => $due]);
        $draw = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => '1. Drawing', 'status' => 'in_progress', 'progress' => $done ? 100 : 50, 'sequence' => 0]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $draw->id, 'name' => '1.1 แบบ', 'department_id' => $this->draw->id, 'owner_id' => $owner?->id,
            'assignment_status' => $done ? 'COMPLETED' : 'ASSIGNED', 'status' => $done ? 'completed' : 'in_progress', 'progress' => 50,
            'due_date' => $lateDays ? now()->subDays($lateDays) : now()->addDays(2), 'assigned_at' => now()->subDays(5)]);
        $buy = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => 'สั่งซื้อ', 'status' => 'not_started', 'progress' => $done ? 100 : 0, 'sequence' => 1]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $buy->id, 'name' => '2.1 ออก PO', 'assignment_status' => $done ? 'COMPLETED' : 'UNASSIGNED',
            'status' => $done ? 'completed' : 'not_started', 'progress' => 0, 'due_date' => now()->addDays(30)]);

        return $c;
    }

    private function build(User $user): array
    {
        $this->project->load('cabinets.tasks.subtasks.department', 'cabinets.tasks.subtasks.owner', 'cabinets.cabinetTemplate', 'projectTasks');

        return app(ProjectOverview::class)->build($this->project, $user);
    }

    private function user(string $role, ?Department $d = null, string $name = ''): User
    {
        static $n = 0;
        $n++;

        return User::forceCreate(['name' => $name ?: 'O'.$n, 'email' => 'ov-'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_cabinet_counts_come_from_status_and_delayed_is_counted_on_its_own(): void
    {
        $this->cabinet('MO-DONE', '2026-10-01', 'completed');
        $this->cabinet('MO-LATE', '2026-10-10');          // in progress and past due: in progress AND delayed
        $this->cabinet('MO-OK', '2026-10-20');
        $this->cabinet('MO-NEW', '2026-10-20', 'not_started');

        $s = $this->build($this->user('admin'))['summary'];

        $this->assertSame([4, 1, 2, 1, 1], [$s['total'], $s['completed'], $s['inProgress'], $s['notStarted'], $s['delayed']]);
        $this->assertSame($s['total'], $s['completed'] + $s['inProgress'] + $s['notStarted'] + $s['onHold'], 'delayed is not a fourth status');
        $this->assertSame(8, $s['workingDaysLeft'], 'Wed 14 → Fri 23: 8 weekdays');
    }

    public function test_the_alerts_list_real_work_and_lead_to_it(): void
    {
        $late = $this->cabinet('MO-LATE', '2026-10-10', lateDays: 6);
        $this->cabinet('MO-OK', '2026-10-20');

        $alerts = collect($this->build($this->user('admin'))['alerts'])->keyBy('key');

        $this->assertSame(1, $alerts['delayed']['count']);
        $this->assertSame(['MO-LATE', 'ล่าช้า 4 วัน', route('cabinets.show', $late)], [$alerts['delayed']['items'][0]['label'], $alerts['delayed']['items'][0]['note'], $alerts['delayed']['items'][0]['url']]);
        $this->assertSame(1, $alerts['late']['count']);
        $this->assertStringEndsWith('#subtask-'.$late->tasks()->first()->subtasks()->first()->id, $alerts['late']['items'][0]['url'], 'opens the cabinet at that sub task');
        $this->assertSame(1, $alerts['soon']['count'], 'the other drawing is due in 2 days');
        $this->assertSame(['เขียนแบบ', 2], [$alerts['waiting']['departments'][0]['name'], $alerts['waiting']['departments'][0]['count']]);
        $this->assertSame(2, $alerts['unassigned']['count']);
    }

    public function test_a_department_member_only_gets_their_own_departments_work_in_the_alerts(): void
    {
        $this->cabinet('MO-1', '2026-10-20', lateDays: 3);

        $alerts = collect($this->build($this->user('member', $this->buy))['alerts'])->keyBy('key');

        $this->assertSame([0, 0, 0, 0], [$alerts['late']['count'], $alerts['soon']['count'], $alerts['waiting']['count'], $alerts['unassigned']['count']], 'drawing work is not theirs');
        $this->assertSame(1, collect($this->build($this->user('member', $this->draw))['alerts'])->keyBy('key')['late']['count']);
    }

    public function test_the_table_rows_carry_risk_pending_work_and_who_is_on_it(): void
    {
        $somchai = $this->user('member', $this->draw, 'Somchai');
        $this->cabinet('MO-LATE', '2026-10-10', lateDays: 2, owner: $somchai);
        $this->cabinet('MO-AFTER', '2026-11-01');   // finishes after the project's 23/10
        $this->cabinet('MO-OK', '2026-10-21');
        $this->cabinet('MO-DONE', '2026-10-01', 'completed');

        $rows = collect($this->build($this->user('admin'))['rows'])->keyBy('mo');

        $this->assertSame(['high', true, 1, 2, 'Somchai', '1. Drawing'], [$rows['MO-LATE']['risk'], $rows['MO-LATE']['delayed'], $rows['MO-LATE']['lateTasks'], $rows['MO-LATE']['pending'], $rows['MO-LATE']['responsible'], $rows['MO-LATE']['current']]);
        $this->assertSame('high', $rows['MO-AFTER']['risk']);
        $this->assertSame(['watch', 'เขียนแบบ'], [$rows['MO-OK']['risk'], $rows['MO-OK']['responsible']], 'due in 7 days at 25%; no owner = the department');
        $this->assertSame(['none', null, 0], [$rows['MO-DONE']['risk'], $rows['MO-DONE']['daysLeft'], $rows['MO-DONE']['pending']]);
        $this->assertSame('RMU', $rows['MO-OK']['model']);
    }

    public function test_the_page_opens_on_project_information_with_the_tabs_and_no_overview_tab(): void
    {
        $this->cabinet('ถนนวิทยุ Modify RMU โรงแรม Indigo', '2026-10-20');
        $admin = $this->user('admin');

        foreach ([null, 'project', 'cabinets', 'overview'] as $tab) {
            $html = $this->actingAs($admin)->get(route('projects.show', array_filter([$this->project, 'tab' => $tab])))->assertOk()
                ->assertSee("tab: 'info'", false)
                ->assertSeeInOrder(['ข้อมูลโครงการ', 'ไฟล์ (', 'Comment', 'Activity Log'])
                ->assertSee('ความคืบหน้าโครงการ')->assertSee('ตู้ล่าช้ากว่ากำหนด')->assertSee('ตู้ / ใบสั่งผลิต (MO)')
                ->getContent();
            $this->assertStringNotContainsString('>ภาพรวม<', $html, 'no ภาพรวม tab');
            // the table is filled from @js() data (quotes and Thai escaped): look for the row the same way
            $this->assertStringContainsString(substr((string) \Illuminate\Support\Js::from(['mo' => 'ถนนวิทยุ Modify RMU โรงแรม Indigo']), strlen("JSON.parse('{"), -strlen("}')")), $html);
        }
    }
}
