<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ProductionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "รายงานการผลิต": the six questions over the open production orders. */
class ProductionReportTest extends TestCase
{
    use RefreshDatabase;

    private Department $prod;

    private Department $ship;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(8, 30));   // a Wednesday
        $this->prod = Department::create(['name' => 'Production', 'code' => 'PRO', 'is_active' => true]);
        $this->ship = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SHP', 'is_active' => true]);
    }

    private function project(string $no, string $status = 'in_progress', ?float $value = null): Project
    {
        return Project::forceCreate(['project_no' => $no, 'project_name' => 'P '.$no, 'customer_name' => 'ลูกค้า '.$no, 'status' => $status, 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => '2026-12-31', 'contract_value' => $value]);
    }

    /**
     * A cabinet with two steps: ผลิต (Production) then ส่งมอบ (the template's last). $at: 'make' = still producing,
     * 'ship' = produced (waiting for the customer), 'done' = handed over.
     */
    private function cabinet(Project $p, string $mo, string $due, int $progress, string $at = 'make', ?string $start = null): Cabinet
    {
        $c = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => $mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'progress' => $progress,
            'status' => $at === 'done' ? 'completed' : 'in_progress', 'due_date' => $due, 'start_date' => $start]);
        foreach ([[1, 'ผลิต', $this->prod, $at !== 'make'], [2, 'ส่งมอบ / ติดตั้ง', $this->ship, $at === 'done']] as [$seq, $name, $dept, $done]) {
            $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => $name, 'status' => 'in_progress', 'progress' => 0, 'sequence' => $seq]);
            CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => $name.' 1', 'department_id' => $dept->id, 'progress' => $done ? 100 : 0,
                'assignment_status' => $done ? 'COMPLETED' : 'ACCEPTED', 'status' => $done ? 'completed' : 'in_progress',
                'completed_at' => $done ? now()->subDays(9) : null, 'due_date' => $due]);
        }

        return $c;
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'prod-admin'], ['name' => 'A', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_it_answers_the_six_questions(): void
    {
        $a = $this->project('SO-A', value: 1_000_000);
        $this->cabinet($a, 'LATE', '2026-10-15', 60);                    // past due
        $this->cabinet($a, 'RUSH', '2026-10-25', 40);                    // due in 4 days, under 80%
        $this->cabinet($a, 'RISK', '2026-11-20', 10, start: '2026-10-01');   // ~38% of the time used, 10% made
        $this->cabinet($a, 'OK', '2026-11-05', 90, start: '2026-10-01');
        $b = $this->project('SO-B');
        $this->cabinet($b, 'READY', '2026-11-30', 95, 'ship');           // produced, the hand-over left
        $this->cabinet($b, 'GONE', '2026-10-10', 100, 'done');           // handed over: not late
        $closed = $this->project('SO-C');
        $this->cabinet($closed, 'CLOSED', '2026-10-01', 50);
        $closed->forceFill(['status' => 'completed'])->saveQuietly();   // closed project: left out

        $r = app(ProductionReport::class)->build($this->admin());

        $this->assertSame([2, 6, 6], [$r['so'], $r['mo'], $r['cabinets']]);
        $this->assertSame([1_000_000.0, 1], [$r['value'], $r['valueMissing']]);
        $this->assertSame(['LATE'], array_column($r['late'], 'mo'));
        $this->assertSame(['RUSH'], array_column($r['rush'], 'mo'));
        $this->assertSame(['RISK'], array_column($r['risk'], 'mo'));
        $this->assertSame(['Production', 'จัดส่งสินค้า'], $r['late'][0]['holders'], 'who to chase: the departments with late work in it');
        $this->assertSame(['READY'], array_column($r['ready'], 'mo'));
        $this->assertSame([9, 'ส่งมอบ / ติดตั้ง'], [$r['ready'][0]['waitDays'], $r['ready'][0]['stage']]);
        $this->assertSame([7 => 1, 15 => 2, 30 => 3], $r['dueCount']);   // RUSH · OK · RISK (READY is 40 days out)
        $this->assertSame(['ผลิต' => 4, 'รอลูกค้ารับ' => 1], array_column($r['stages'], 'count', 'name'));
        // late first, then the most open work: both have one late (LATE's two steps), จัดส่ง holds more
        $depts = collect($r['departments'])->keyBy('name');
        $this->assertSame(['จัดส่งสินค้า', 'Production'], $depts->keys()->all());
        $this->assertSame(['late' => 1, 'doing' => 3, 'wait' => 0], array_intersect_key($depts['Production'], array_flip(['late', 'doing', 'wait'])));
    }

    public function test_the_page_opens_on_the_tab_asked_for_and_hides_the_value_without_finance(): void
    {
        $this->cabinet($this->project('SO-A', value: 2_500_000), 'MO-1', '2026-10-15', 60);
        $this->actingAs($this->admin())->get(route('reports.production', ['tab' => 'late']))->assertOk()
            ->assertSee('รายงานการผลิต')->assertSee('ตู้ที่ล่าช้า หรือเสี่ยงส่งไม่ทัน')->assertSee('MO-1')->assertSee('2.5')
            ->assertSee("tab: 'late'", false);

        $member = User::forceCreate(['name' => 'M', 'email' => 'prod-m', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->prod->id, 'is_active' => true]);
        $this->actingAs($member)->get(route('reports.production'))->assertOk()->assertDontSee('มูลค่างานที่กำลังทำ');
    }

    public function test_it_reads_per_project_and_for_the_projects_picked(): void
    {
        $a = $this->project('SO-A');
        $this->cabinet($a, 'A-LATE', '2026-10-15', 60);
        $this->cabinet($a, 'A-OK', '2026-11-05', 90, start: '2026-10-01');
        $b = $this->project('SO-B');
        $this->cabinet($b, 'B-READY', '2026-11-30', 95, 'ship');
        $this->cabinet($b, 'B-RUSH', '2026-10-25', 40);

        $r = app(ProductionReport::class)->build($this->admin());
        $rows = collect($r['projects'])->keyBy('so');
        $this->assertSame(['SO-A', 'SO-B'], $rows->keys()->all(), 'the late one first');
        $this->assertSame(['late', 'ล่าช้า 1 ตู้ จาก 2 ตู้', ['Production', 'จัดส่งสินค้า']], [$rows['SO-A']['state'], $rows['SO-A']['why'], $rows['SO-A']['holders']]);
        $this->assertSame(['rush', 1, 4], [$rows['SO-B']['state'], $rows['SO-B']['ready'], $rows['SO-B']['nextDays']]);
        $this->assertSame(['ผลิต' => 1, 'รอลูกค้ารับ' => 1], $rows['SO-B']['stages']);
        $this->assertSame(['late' => 1, 'watch' => 1, 'rush' => 1, 'risk' => 0, 'ready' => 1, 'due' => [7 => 1, 15 => 2, 30 => 2]], $r['projectCount']);

        // only SO-B
        $only = app(ProductionReport::class)->build($this->admin(), projectIds: [$b->id]);
        $this->assertSame([1, 2, [$b->id]], [$only['so'], $only['mo'], $only['selected']]);
        $this->assertSame([], $only['late']);
        $this->assertCount(2, $only['projectOptions'], 'the picker still lists every project');

        $this->actingAs($this->admin())->get(route('reports.production', ['by' => 'project', 'projects' => [$b->id]]))->assertOk()
            ->assertSee("by: 'project'", false)->assertSee('เฉพาะ 1 โครงการที่เลือก')->assertSee('B-RUSH');
    }

    public function test_work_past_its_own_date_makes_a_cabinet_at_risk_before_its_due_date(): void
    {
        $c = $this->cabinet($this->project('SO-A'), 'FAR', '2026-12-20', 50, start: '2026-10-01');   // made on pace, due far ahead
        $sub = $c->tasks()->orderBy('sequence')->first()->subtasks()->first();
        $sub->forceFill(['due_date' => '2026-10-18'])->saveQuietly();   // but its production step was due three days ago

        $r = app(ProductionReport::class)->build($this->admin());
        $this->assertSame(['FAR'], array_column($r['risk'], 'mo'));
        $this->assertSame(['งานย่อยเลยกำหนด 1 งาน · ผลิต 50%', ['Production']], [$r['risk'][0]['why'], $r['risk'][0]['holders']]);
        $this->assertSame(['เลยกำหนด 1 งาน', ['Production' => 1]], [$r['risk'][0]['brief'], $r['risk'][0]['lateByDept']]);
    }

    public function test_the_ceo_report_reads_by_project_or_by_cabinet_with_a_written_summary(): void
    {
        $a = $this->project('SO-A', value: 3_000_000);
        $this->cabinet($a, 'A-LATE', '2026-10-15', 60);
        $this->cabinet($a, 'A-RUSH', '2026-10-25', 40);
        $b = $this->project('SO-B');
        $this->cabinet($b, 'B-READY', '2026-11-30', 95, 'ship');   // waiting 9 days

        $admin = $this->admin();
        $byProject = $this->actingAs($admin)->get(route('reports.ceo'))->assertOk();
        $byProject->assertSee('บทสรุปผู้บริหาร')->assertSee('แยกเป็นรายโครงการ')
            ->assertSee('ขณะนี้มีงานสั่งผลิตที่ยังไม่ปิด 2 โครงการ รวม 3 ตู้ มูลค่า 3.0 ล้านบาท')
            ->assertSee('มี 1 โครงการที่มีตู้เลยวันส่งแล้ว')
            ->assertSee('สถานะโครงการที่กำลังดำเนินการ')
            ->assertSee('ประสานลูกค้านัดรับตู้ที่รอเกิน 7 วัน')
            ->assertSee('กรอกมูลค่างานใน 1 SO');

        $byCabinet = $this->actingAs($admin)->get(route('reports.ceo', ['by' => 'cabinet']))->assertOk();
        $byCabinet->assertSee('แยกเป็นรายตู้')->assertSee('ตู้เลยวันส่งแล้ว 1 ตู้')
            ->assertSee('ตู้ที่ล่าช้าหรือเสี่ยงส่งไม่ทัน (2 ตู้)')->assertSee('A-RUSH')
            ->assertSee('เลยวันส่ง 6 วัน · เลยกำหนด 2 งาน')->assertSeeInOrder(['>Production 1<', '>จัดส่งสินค้า 1<'], false)
            ->assertDontSee('สถานะโครงการที่กำลังดำเนินการ');
    }

    public function test_the_report_filter_limits_to_projects_departments_and_a_period(): void
    {
        $a = $this->project('SO-A');
        $this->cabinet($a, 'A-OCT', '2026-10-15', 60);   // late, both its steps late (Production + จัดส่ง)
        $this->cabinet($a, 'A-DEC', '2026-12-20', 50, start: '2026-10-01');
        $b = $this->project('SO-B');
        $this->cabinet($b, 'B-NOV', '2026-11-05', 90, start: '2026-10-01');
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $c = $this->project('SO-C');
        $other = Cabinet::forceCreate(['project_id' => $c->id, 'mo_no' => 'C-QC', 'cabinet_name' => 'Cab', 'quantity' => 1, 'progress' => 0, 'status' => 'in_progress', 'due_date' => '2026-11-01']);
        CabinetSubtask::forceCreate(['cabinet_task_id' => CabinetTask::forceCreate(['cabinet_id' => $other->id, 'name' => 'QC', 'status' => 'in_progress', 'progress' => 0, 'sequence' => 1])->id,
            'name' => 'ตรวจ', 'department_id' => $qc->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0, 'due_date' => '2026-10-10']);
        $build = fn (array $q) => app(ProductionReport::class)->build($this->admin(), filter: \App\Services\ReportFilter::fromRequest(\Illuminate\Http\Request::create('/', 'GET', $q)));
        $mos = fn (array $r) => collect($r['projects'])->pluck('so')->sort()->values()->all();

        // SO
        $this->assertSame(['SO-B'], $mos($build(['projects' => [$b->id]])));
        // a department: only the projects it has work in, and only its late work counts
        $r = $build(['departments' => [$qc->id]]);
        $this->assertSame(['SO-C'], $mos($r));
        $this->assertSame(['QC'], array_column($r['departments'], 'name'));
        $r = $build(['departments' => [$this->ship->id]]);
        $this->assertSame(['A-OCT'], array_column($r['late'], 'mo'));
        $this->assertSame(['จัดส่งสินค้า'], $r['late'][0]['holders'], 'Production\'s late step is not counted');
        // a period: only the cabinets due in it (the dates swapped are put right)
        $r = $build(['period' => 'custom', 'from' => '2026-11-30', 'to' => '2026-11-01']);
        $this->assertSame(['SO-B', 'SO-C'], $mos($r));
        $this->assertSame(2, $r['mo'], 'B-NOV and C-QC, not A-OCT / A-DEC');
        $this->assertSame([], $mos($build(['period' => 'next30', 'projects' => [$a->id]])), 'SO-A has no cabinet due 21/10 – 20/11');

        // some cabinets: only them, and the SO they belong to
        $r = $build(['cabinets' => [Cabinet::where('mo_no', 'A-DEC')->value('id'), $other->id]]);
        $this->assertSame(['SO-A', 'SO-C'], $mos($r));
        $this->assertSame(2, $r['mo']);
        $this->assertSame([], $r['late'], 'A-OCT is not one of them');
        // a whole SO plus single cabinets of another add up
        $r = $build(['projects' => [$b->id], 'cabinets' => [Cabinet::where('mo_no', 'A-DEC')->value('id')]]);
        $this->assertSame(['SO-A', 'SO-B'], $mos($r));
        $this->assertSame(2, $r['mo'], 'B-NOV (all of SO-B) and A-DEC, not A-OCT');
        $this->assertSame('ตู้ A-DEC, C-QC', \App\Services\ReportFilter::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['cabinets' => [Cabinet::where('mo_no', 'A-DEC')->value('id'), $other->id]]))->describe());

        $f = \App\Services\ReportFilter::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['projects' => [$b->id], 'departments' => [$qc->id], 'period' => 'month']));
        $this->assertSame('เฉพาะ SO-B · แผนก QC · กำหนดส่ง เดือนนี้ (01/10/2569 – 31/10/2569)', $f->describe());
        $this->assertSame('ทุกโครงการที่ยังไม่ปิด · ทุกแผนก · ทุกช่วงเวลา', (new \App\Services\ReportFilter)->describe());
    }

    public function test_the_ceo_report_prints_only_the_parts_ticked_for_the_scope_chosen(): void
    {
        $this->cabinet($this->project('SO-A'), 'A-1', '2026-10-15', 60);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.ceo', ['pick' => 1, 'sections' => ['summary', 'kpis']]))->assertOk()
            ->assertSeeInOrder(['1. บทสรุปผู้บริหาร', '2. ตัวเลขหลัก'])
            ->assertDontSee('3. ')->assertDontSee('แผนส่งมอบตู้รายเดือน (')->assertDontSee('ผู้จัดทำรายงาน');

        $this->actingAs($admin)->get(route('reports.ceo', ['departments' => [$this->ship->id], 'period' => 'next30']))->assertOk()
            ->assertSee('ขอบเขตรายงาน')->assertSee('แผนก จัดส่งสินค้า · กำหนดส่ง 30 วันข้างหน้า (21/10/2569 – 20/11/2569)')
            ->assertSee('ตัวเลือกรายงาน (มีการเลือก)');
        $this->actingAs($admin)->get(route('reports.ceo'))->assertOk()->assertSee('ทุกโครงการที่ยังไม่ปิด · ทุกแผนก · ทุกช่วงเวลา')
            ->assertSee('ค้นหา SO / MO / ชื่อตู้ / ลูกค้า')->assertSee('name="projects[]" value="'.Project::where('project_no', 'SO-A')->value('id').'" data-cab-all', false)
            ->assertSee('0/1');   // SO-A's one cabinet, none chosen
    }
}
