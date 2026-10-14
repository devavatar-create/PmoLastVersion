<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\DepartmentLink;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > การส่งต่องาน: จัดซื้อ → คลัง → Production follow each other's checklist (read only), dates and progress. */
class HandoffTest extends TestCase
{
    use RefreshDatabase;

    private Department $purchasing;

    private Department $store;

    private Department $production;

    private Department $other;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->purchasing = Department::create(['name' => 'จัดซื้อ', 'code' => 'PUR', 'is_active' => true]);
        $this->store = Department::create(['name' => 'คลัง', 'code' => 'STO', 'is_active' => true]);
        $this->production = Department::create(['name' => 'Production', 'code' => 'PRO', 'is_active' => true]);
        $this->other = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        DepartmentLink::create(['from_department_id' => $this->purchasing->id, 'to_department_id' => $this->store->id, 'date_label' => 'วันของเข้า']);
        DepartmentLink::create(['from_department_id' => $this->store->id, 'to_department_id' => $this->production->id]);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'H'.++$n, 'email' => 'hand'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    /** @return list<CabinetChecklist> */
    private function work(Department $d, array $items, string $status = 'ACCEPTED'): array
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'due_date' => '2026-10-20', 'status' => 'not_started', 'progress' => 0]);

        return array_map(fn ($name, $i) => CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $name, 'is_completed' => false, 'sequence' => $i + 1]), $items, array_keys($items));
    }

    private function cab(User $u): array
    {
        $t = collect(app(PlanningBoard::class)->build($u)['boards']['pmo']['tasks'])->firstWhere('id', 'p'.$this->project->id);

        return $t['subtasks'][0];
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    public function test_the_receiving_department_sees_the_senders_checklist_read_only_with_its_dates(): void
    {
        [$breaker, $busbar] = $this->work($this->purchasing, ['Breaker 100A', 'Busbar']);
        [$storeBreaker] = $this->work($this->store, ['breaker 100a ', 'Busbar 2']);
        $this->work($this->production, ['ติดตั้ง']);
        $breaker->update(['is_completed' => true, 'expected_date' => '2026-10-20', 'remark' => 'รอบเดียว']);
        $storeBreaker->update(['is_completed' => true]);

        $linked = $this->cab($this->user($this->store))['linked'];
        $this->assertSame(['d_'.$this->purchasing->id], array_column($linked, 'deptId'));
        $items = collect($linked[0]['items'])->keyBy('text');
        $this->assertSame(['2026-10-20', 'รอบเดียว', true, true], [$items['Breaker 100A']['expected'], $items['Breaker 100A']['remark'], $items['Breaker 100A']['done'], $items['Breaker 100A']['received']],
            'the same name in my checklist, ticked = I have dealt with it');
        $this->assertFalse($items['Busbar']['received']);
        $this->assertSame([1, 2], [$linked[0]['done'], $linked[0]['total']]);

        $prod = $this->cab($this->user($this->production))['linked'];
        $this->assertSame(['d_'.$this->store->id], array_column($prod, 'deptId'), 'Production follows the store, not purchasing');
        $this->assertSame([], $this->cab($this->user($this->purchasing))['linked'], 'nothing is passed backwards');
        $this->assertSame([], $this->cab($this->user(null, 'project_manager'))['linked'], 'PMO already sees every checklist');

        $board = app(PlanningBoard::class)->build($this->user($this->store))['boards']['pmo'];
        $depts = collect($board['departments'])->keyBy('id');
        $this->assertSame('วันของเข้า', $depts['d_'.$this->purchasing->id]['dateLabel']);
        $this->assertNull($depts['d_'.$this->store->id]['dateLabel']);
        $this->assertSame(['d_'.$this->production->id], $depts['d_'.$this->store->id]['passesTo']);
    }

    public function test_the_cabinet_page_checklists_show_the_handoff_both_ways(): void
    {
        [$breaker, $busbar] = $this->work($this->purchasing, ['Breaker 100A', 'Busbar']);
        [$storeBreaker] = $this->work($this->store, ['Breaker 100A', 'อื่น ๆ']);
        $breaker->update(['expected_date' => '2026-10-20', 'remark' => 'รอบเดียว']);
        $storeBreaker->update(['is_completed' => true]);

        // the sending side: each item says whether คลัง has got it
        $sub = CabinetSubtask::where('department_id', $this->purchasing->id)->with('cabinetTask')->first();
        $items = collect(app(\App\Services\ChecklistEditor::class)->items('subtask', $sub))->keyBy('name');
        $this->assertSame([['dept' => 'คลัง', 'received' => true]], $items['Breaker 100A']['handoff']);
        $this->assertSame([['dept' => 'คลัง', 'received' => false]], $items['Busbar']['handoff']);

        // the receiving side: what จัดซื้อ did, under คลัง's Sub Task - whoever opens the page (here: Admin)
        $linked = app(\App\Services\HandoffService::class)->linkedForDepartment($this->store->id, collect([$this->cabinet->id]))->get($this->cabinet->id);
        $this->assertSame(['จัดซื้อ', 'วันของเข้า', 0, 2], [$linked[0]['deptName'], $linked[0]['dateLabel'], $linked[0]['done'], $linked[0]['total']]);
        $this->assertSame([true, '2026-10-20', 'รอบเดียว'], [$linked[0]['items'][0]['received'], $linked[0]['items'][0]['expected'], $linked[0]['items'][0]['remark']]);
        $html = $this->actingAs($this->user(null, 'admin'))->get(route('cabinets.show', $this->cabinet))->assertOk()->getContent();
        $this->assertStringContainsString('ข้อมูลจาก', $html);
        // the page hands the list to the editor through @js() (quotes and Thai escaped): look for it the same way
        $inJs = fn (array $a) => substr((string) \Illuminate\Support\Js::from($a), strlen("JSON.parse('{"), -strlen("}')"));
        $this->assertStringContainsString($inJs(['deptName' => 'จัดซื้อ']), $html, 'the editor under คลัง gets จัดซื้อ\'s list');
        $this->assertStringContainsString($inJs(['received' => true]), $html);
    }

    public function test_a_department_with_no_work_on_the_project_sees_nothing_of_it(): void
    {
        $this->work($this->purchasing, ['Breaker']);
        $this->assertSame([], collect(app(PlanningBoard::class)->build($this->user($this->store))['boards']['pmo']['tasks'])->all());
    }

    public function test_the_sender_sets_dates_per_item_or_for_the_whole_cabinet_and_the_receiver_hears_it(): void
    {
        [$a, $b] = $this->work($this->purchasing, ['Breaker', 'Busbar']);
        $this->work($this->store, ['รับของ']);
        $buyer = $this->user($this->purchasing);
        $keeper = $this->user($this->store);
        $stranger = $this->user($this->other);

        $this->assertTrue($this->apply($buyer, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok']);
        $this->assertSame('2026-10-20', $a->fresh()->expected_date->format('Y-m-d'));
        $notice = $keeper->notifications()->first();
        $this->assertNotNull($notice, 'the store hears it');
        $this->assertStringContainsString('จัดซื้อ ลงวันของเข้า 1 รายการ', $notice->data['body']);
        $this->assertSame(0, $buyer->notifications()->count(), 'not the person who did it');

        $this->assertFalse($this->apply($stranger, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$b->id, 'date' => '2026-10-21'])['ok']);
        [$storeItem] = CabinetSubtask::where('department_id', $this->store->id)->first()->checklists->all();
        $this->assertFalse($this->apply($keeper, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$storeItem->id, 'date' => '2026-10-21'])['ok'], 'the store gives no date');

        $r = $this->apply($buyer, ['type' => 'checklist_date_all', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => 'd_'.$this->purchasing->id, 'date' => '2026-10-22']);
        $this->assertTrue($r['ok']);
        $this->assertSame(['2026-10-22', '2026-10-22'], [$a->fresh()->expected_date->format('Y-m-d'), $b->fresh()->expected_date->format('Y-m-d')]);
        $this->assertSame(1, $keeper->notifications()->count(), 'folded into the one project notice');
        $this->assertStringContainsString('จัดซื้อ ลงวันของเข้า 3 รายการ', $keeper->notifications()->first()->data['body']);
    }

    public function test_the_sender_must_have_accepted_its_work_before_giving_dates(): void
    {
        [$a] = $this->work($this->purchasing, ['Breaker'], 'ASSIGNED');
        $this->assertFalse($this->apply($this->user($this->purchasing), ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok']);
        $this->assertTrue($this->apply($this->user(null, 'admin'), ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok'], 'Admin / PM may');
    }

    public function test_ticking_tells_the_next_department_and_a_finished_cabinet_once_more(): void
    {
        [$a, $b] = $this->work($this->store, ['Breaker', 'Busbar']);
        $this->work($this->production, ['ติดตั้ง']);
        $keeper = $this->user($this->store);
        $maker = $this->user($this->production);
        $qc = $this->user($this->other);

        app(ProgressService::class)->toggleChecklist($a, true, $keeper);
        $this->assertStringContainsString('คลัง เสร็จ 1 รายการ', $maker->notifications()->first()->data['body']);
        app(ProgressService::class)->toggleChecklist($b, true, $keeper);
        $body = $maker->notifications()->first()->data['body'];
        $this->assertStringContainsString('คลัง เสร็จ 2 รายการ', $body);
        $this->assertStringContainsString('คลัง ครบ 1 ตู้', $body);
        $this->assertSame(1, $maker->notifications()->count());
        $this->assertSame(0, $qc->notifications()->count());
    }

    public function test_the_daily_round_reports_dates_due_today_and_late_until_the_receiver_ticks(): void
    {
        [$a, $b, $c] = $this->work($this->purchasing, ['Breaker', 'Busbar', 'CT']);
        [$storeBreaker] = $this->work($this->store, ['Breaker', 'อื่น ๆ']);
        $a->update(['expected_date' => '2026-10-07']);   // today
        $b->update(['expected_date' => '2026-10-05']);   // late
        $c->update(['expected_date' => '2026-10-30']);   // later: not yet
        $keeper = $this->user($this->store);
        $buyer = $this->user($this->purchasing);

        app(NotificationService::class)->sendReminders();
        $store = $keeper->notifications()->first()->data;
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $store['body']);
        $this->assertStringContainsString('📦 วันของเข้าวันนี้ 1 รายการ', $store['body']);
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $buyer->notifications()->first()->data['body'], 'the sender is chased about the late one');

        app(NotificationService::class)->sendReminders();
        $this->assertSame(1, $keeper->notifications()->count(), 'once a day');

        $storeBreaker->update(['is_completed' => true]);   // the store got the breaker
        $this->travelTo('2026-10-08 09:00:00');
        $keeper->notifications()->update(['read_at' => now()]);
        app(NotificationService::class)->sendReminders();
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $keeper->notifications()->latest()->first()->data['body'], 'only Busbar is still outstanding');
    }

    public function test_settings_page_is_admin_only_and_manages_the_pairs(): void
    {
        $this->actingAs($this->user($this->store))->get(route('settings.handoffs.index'))->assertForbidden();

        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->get(route('settings.handoffs.index'))->assertOk()->assertSee('จัดซื้อ')->assertSee('วันของเข้า');
        $this->actingAs($admin)->post(route('settings.handoffs.store'), ['from_department_id' => $this->other->id, 'to_department_id' => $this->other->id])->assertSessionHasErrors('to_department_id');
        $this->actingAs($admin)->post(route('settings.handoffs.store'), ['from_department_id' => $this->purchasing->id, 'to_department_id' => $this->store->id])->assertSessionHasErrors('to_department_id');
        $this->actingAs($admin)->post(route('settings.handoffs.store'), ['from_department_id' => $this->production->id, 'to_department_id' => $this->other->id, 'date_label' => ' '])->assertSessionHasNoErrors();
        $link = DepartmentLink::where('from_department_id', $this->production->id)->first();
        $this->assertNull($link->date_label);
        $this->actingAs($admin)->put(route('settings.handoffs.update', $link), ['date_label' => 'วันส่งตรวจ'])->assertSessionHasNoErrors();
        $this->assertSame('วันส่งตรวจ', $link->fresh()->date_label);
        $this->actingAs($admin)->delete(route('settings.handoffs.destroy', $link));
        $this->assertSame(2, DepartmentLink::count());
    }
}
