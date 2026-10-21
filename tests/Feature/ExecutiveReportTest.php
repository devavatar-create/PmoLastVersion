<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ExecutiveReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The dashboard's "ภาพรวมผู้บริหาร" view and the printable CEO report. */
class ExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    private Department $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(8, 30));
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
    }

    private function project(string $no, string $status, ?string $due, ?string $start = '2026-09-01', string $priority = 'normal'): Project
    {
        return Project::forceCreate(['project_no' => $no, 'project_name' => 'P '.$no, 'customer_name' => 'C', 'status' => $status, 'priority' => $priority,
            'start_date' => $start, 'due_date' => $due]);
    }

    /** One cabinet with one step and one sub task (late by $lateDays when > 0). */
    private function cabinet(Project $p, string $mo, ?string $due, int $progress, string $status = 'in_progress', int $lateDays = 0): Cabinet
    {
        $c = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => $mo, 'cabinet_name' => 'RMU '.$mo, 'quantity' => 1, 'status' => $status, 'progress' => $progress, 'due_date' => $due]);
        $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => '1. QC', 'status' => 'in_progress', 'progress' => $progress, 'sequence' => 0]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => '1.1 ตรวจ', 'department_id' => $this->qc->id, 'assignment_status' => 'ASSIGNED',
            'status' => $status === 'completed' ? 'completed' : 'in_progress', 'progress' => $progress,
            'due_date' => $lateDays ? now()->subDays($lateDays) : now()->addDays(5)]);

        return $c;
    }

    private function admin(): User
    {
        return User::forceCreate(['name' => 'A', 'email' => 'exec-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_it_reads_late_watch_ok_and_finished_projects_and_says_so_first(): void
    {
        $late = $this->project('LATE', 'in_progress', '2026-10-16');
        $this->cabinet($late, 'MO-L', '2026-10-08', 92);
        $watch = $this->project('WATCH', 'in_progress', '2027-02-15', priority: 'urgent');
        $this->cabinet($watch, 'MO-W1', '2026-12-18', 30, lateDays: 10);
        $this->cabinet($watch, 'MO-W2', '2026-12-18', 30, lateDays: 4);
        $ok = $this->project('OK', 'in_progress', '2026-12-30');
        $this->cabinet($ok, 'MO-O', '2026-11-10', 60);
        $done = $this->project('DONE', 'in_progress', '2026-10-06');
        $this->cabinet($done, 'MO-D', '2026-10-05', 100, 'completed');
        $this->project('PLAN', 'planning', '2026-12-01');
        $this->project('CLOSED', 'completed', '2026-09-01');

        $r = app(ExecutiveReport::class)->build($this->admin());

        $this->assertSame(['LATE' => 'late', 'WATCH' => 'watch', 'OK' => 'ok', 'DONE' => 'close'], collect($r['projects'])->pluck('state', 'no')->all(), 'late first, finished last');
        $this->assertSame('เลย 3 วัน', $r['projects'][0]['dueNote']);
        $this->assertSame('งานย่อยเลยกำหนด 2 งาน ใน 2 ตู้', $r['projects'][1]['issue']);

        $this->assertSame(['red', 'orange', 'blue'], array_column($r['highlights'], 'tone'));
        $this->assertSame('P LATE เลยกำหนดส่ง 3 วัน', $r['highlights'][0]['title']);
        $this->assertStringContainsString('คอขวดที่ QC', $r['highlights'][1]['text']);

        $k = $r['kpis'];
        $this->assertSame([4, 1, 1, 1, 4, 1, 5, 2, 2, 1], [$k['projects'], $k['urgent'], $k['notStarted'], $k['lateProjects'], $k['cabinetsOpen'], $k['cabinetsDone'],
            $k['cabinetsTotal'], $k['lateSubtasks'], $k['lateCabinets'], $k['lateInProjects']], 'planning / closed projects are not running');
        $this->assertSame(['ต.ค.', 2, 1, 1], [$k['monthLabel'], $k['monthPlanned'], $k['monthDone'], $k['monthOverdue']]);

        $this->assertSame([['QC', 2, 2, 10]], array_map(fn ($d) => [$d['name'], $d['late'], $d['cabinets'], $d['days']], $r['departments']));
        $this->assertSame(['ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.', 'ม.ค.', 'ก.พ.'], array_column($r['months'], 'label'));
        $this->assertSame([0, 2, 1, 2, 0, 0], array_column($r['months'], 'planned'));
        $this->assertSame(['MO-L', 'MO-O'], array_column($r['follow'], 'mo'), 'late or due within 30 days, not finished');
        $this->assertSame('ตู้เลยกำหนด 11 วัน', $r['follow'][0]['why']);
        $this->assertSame('P CLOSED (0 ตู้)', $r['lastClosed']);

        // the dashboard overview: each project in one bucket, so they add up to the total. Delayed = in progress and past
        // its due date, so DONE (production 100% but still open) counts as late until someone closes it.
        $this->assertSame(['total' => 6, 'inProgress' => 2, 'delayed' => 2, 'completed' => 1, 'onHold' => 0, 'notStarted' => 1], $r['statusCounts']);
        $this->assertSame([['QC', 3]], array_map(fn ($d) => [$d['name'], $d['projects']], $r['deptProjects']), 'running projects with unfinished QC work (DONE is finished)');
    }

    public function test_the_dashboard_opens_on_the_executive_view_for_pmo_and_prints_the_report(): void
    {
        $p = $this->project('LATE', 'in_progress', '2026-10-16');
        $this->cabinet($p, 'MO-L', '2026-10-08', 92);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['ทั้งหมด', 'ตามแผน', 'ล่าช้า', 'รอเริ่ม', 'พักดำเนินการ', 'เสร็จสิ้น', 'โครงการที่ควรติดตาม', 'P LATE', 'งานค้างตามแผนก', 'ส่งมอบตู้รายเดือน'])
            ->assertSee(route('projects.index', ['due' => 'late']), false)
            ->assertSee(route('reports.ceo'), false);
        $this->actingAs($admin)->get(route('dashboard', ['view' => 'dept']))->assertOk()->assertDontSee('โครงการที่ควรติดตาม')->assertSee('ภาระงานรายสัปดาห์');

        $this->actingAs($admin)->get(route('reports.ceo'))->assertOk()
            ->assertSee('รายงานสถานะโครงการและการผลิต')->assertSee('ข้อมูล ณ วันที่ 19 ตุลาคม 2569 เวลา 08:30 น.')
            ->assertSee('images/report/letterhead-header.png', false)->assertSee('images/report/letterhead-footer.png', false)
            ->assertSeeInOrder(['1. บทสรุปผู้บริหาร', '2. ประเด็นสำคัญรายโครงการ', '3. ตัวเลขหลัก', '4. สถานะโครงการ', '5. กำหนดส่งมอบใน 30 วัน',
                '6. ผลิตเสร็จแล้ว รอลูกค้ารับ', '7. แผนส่งมอบตู้รายเดือน', '8. คอขวดตามแผนก', '9. ตู้ที่ต้องติดตาม'])
            ->assertSee('P LATE');
    }

    public function test_the_late_and_on_track_cards_open_a_project_list_with_the_same_count(): void
    {
        $this->project('LATE', 'in_progress', '2026-10-16');
        $this->project('DUE-TODAY', 'in_progress', '2026-10-19');
        $this->project('NO-DATE', 'in_progress', null);
        $this->project('PAUSED-LATE', 'on_hold', '2026-10-01');
        $admin = $this->admin();
        $counts = app(ExecutiveReport::class)->build($admin)['statusCounts'];

        $late = $this->actingAs($admin)->get(route('projects.index', ['due' => 'late']))->assertOk()->assertSee('เฉพาะล่าช้า (เลยวันส่ง)');
        $this->assertSame(['LATE'], $late->viewData('projects')->pluck('project_no')->all());
        $this->assertSame(1, $counts['delayed']);
        $onTrack = $this->actingAs($admin)->get(route('projects.index', ['status' => 'in_progress', 'due' => 'ontrack']))->assertOk();
        $this->assertEqualsCanonicalizing(['DUE-TODAY', 'NO-DATE'], $onTrack->viewData('projects')->pluck('project_no')->all());
        $this->assertSame(2, $counts['inProgress'], 'due today is not late yet');
    }

    public function test_someone_outside_pmo_lands_on_their_department_view(): void
    {
        $member = User::forceCreate(['name' => 'M', 'email' => 'exec-member', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->qc->id, 'is_active' => true]);

        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertViewHas('view', 'dept')->assertViewHas('exec', null);
    }
}
