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

/** The project page's "ภาพรวม" tab: delivery risk and why, cabinets by step and delivery batch, work per department. */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Department $draw;

    private Department $buy;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 14)->startOfDay());

        $this->draw = Department::create(['name' => 'เขียนแบบ', 'code' => 'DRW', 'is_active' => true]);
        $this->buy = Department::create(['name' => 'จัดซื้อ', 'code' => 'BUY', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'ถนนวิทยุ', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'urgent',
            'start_date' => '2026-09-08', 'due_date' => '2027-02-15']);
    }

    /** A cabinet with two steps: drawing (late by $lateDays when > 0) and buying. */
    private function cabinet(string $mo, string $due, int $lateDays = 0, int $drawPct = 50): Cabinet
    {
        $c = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'RMU', 'quantity' => 1, 'status' => 'in_progress',
            'progress' => intdiv($drawPct, 2), 'due_date' => $due]);
        foreach ([['1. Drawing', $this->draw, $drawPct, $lateDays ? now()->subDays($lateDays) : now()->addDays(3)], ['2. สั่งซื้อ', $this->buy, 0, now()->addDays(30)]] as $i => [$name, $dept, $pct, $subDue]) {
            $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => $name, 'status' => 'in_progress', 'progress' => $pct, 'sequence' => $i]);
            CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => $name.' ย่อย', 'department_id' => $dept->id, 'assignment_status' => 'ASSIGNED',
                'status' => 'in_progress', 'progress' => $pct, 'due_date' => $subDue, 'assigned_at' => now()->subDays(20)]);
        }

        return $c;
    }

    private function build(User $user): array
    {
        $this->project->load('cabinets.tasks.subtasks.department', 'cabinets.cabinetTemplate');

        return app(ProjectOverview::class)->build($this->project, $user);
    }

    private function user(string $role, ?Department $d = null): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'O'.++$n, 'email' => 'ov-'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_late_steps_make_an_amber_warning_even_when_every_cabinet_is_on_time(): void
    {
        $this->cabinet('ถนนวิทยุ Modify RMU โรงแรม Indigo', '2026-12-18', lateDays: 14);
        $this->cabinet('ถนนวิทยุ Modify RMU อาคารสินทร', '2026-12-18', lateDays: 9);

        $o = $this->build($this->user('admin'));

        $this->assertSame('amber', $o['health']['tone']);
        $this->assertStringContainsString('งานย่อยเลยกำหนด 2 งาน', $o['health']['title']);
        $this->assertSame('ขั้น 1. Drawing', $o['health']['items'][0]['label']);
        $this->assertStringContainsString('2 ตู้ · ช้าสุด 14 วัน · เขียนแบบ', $o['health']['items'][0]['detail']);
        $this->assertSame(['เขียนแบบ', 2, 2], [$o['departments'][0]['name'], $o['departments'][0]['late'], $o['departments'][0]['open']]);
        $this->assertSame(2, $o['startedNotAccepted'], 'two drawing sub tasks are ticked but nobody pressed รับงาน');
    }

    public function test_a_cabinet_due_after_the_project_is_red(): void
    {
        $this->cabinet('MO-1', '2027-02-20');

        $o = $this->build($this->user('admin'));

        $this->assertSame('red', $o['health']['tone']);
        $this->assertStringContainsString('เสร็จหลังวันส่งโครงการ 5 วัน', $o['groups'][0]['batches'][0]['rows'][0]['status']);
    }

    public function test_rows_drop_the_words_every_mo_shares_and_are_grouped_by_delivery_date(): void
    {
        $this->cabinet('ถนนวิทยุ Modify RMU โรงแรม Indigo', '2027-01-20');
        $this->cabinet('ถนนวิทยุ Modify RMU อาคารสินทร', '2026-12-18');
        $this->cabinet('ถนนวิทยุ Modify RMU GPF Building', '2026-12-18');

        $group = $this->build($this->user('admin'))['groups'][0];

        $this->assertTrue($group['showBatches']);
        $this->assertSame(['18/12/2569', '20/01/2570'], array_column($group['batches'], 'due'), 'earliest batch first');
        $this->assertSame(['อาคารสินทร', 'GPF Building'], array_column($group['batches'][0]['rows'], 'short'));
        $this->assertSame(['1. Drawing', '2. สั่งซื้อ'], array_column($group['steps'], 'label'));
    }

    public function test_a_department_member_only_sees_their_own_departments_load(): void
    {
        $this->cabinet('MO-1', '2026-12-18', lateDays: 3);

        $o = $this->build($this->user('member', $this->buy));

        $this->assertSame(['จัดซื้อ'], array_column($o['departments'], 'name'));
        $this->assertStringNotContainsString('เขียนแบบ', $o['health']['items'][0]['detail'], 'no other department named in the warning');
    }

    public function test_the_page_opens_on_the_merged_tab_and_shows_the_cabinet_rows(): void
    {
        $c = $this->cabinet('ถนนวิทยุ Modify RMU โรงแรม Indigo', '2026-12-18', lateDays: 2);
        $this->cabinet('ถนนวิทยุ Modify RMU อาคารสินทร', '2026-12-18');
        $admin = $this->user('admin');

        foreach ([null, 'project', 'cabinets'] as $tab) {
            $this->actingAs($admin)->get(route('projects.show', array_filter([$this->project, 'tab' => $tab])))->assertOk()
                ->assertSee("tab: 'overview'", false)
                ->assertSee('ภาพรวม')
                ->assertSee('href="'.route('cabinets.show', $c).'"', false)
                ->assertSee('โรงแรม Indigo')
                ->assertSee('งานตามแผนก');
        }
    }
}
