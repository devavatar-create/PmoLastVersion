<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTask;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\CabinetTemplateService;
use App\Services\HandoffService;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\ProgressService;
use App\Services\SubtaskPairs;
use App\Services\TemplateTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checklists compared side by side: pairs set on the cabinet template ("เทียบกับ…"), copied to its cabinets; the Sub Task
 * comparing sees the other one's checklist read only, with the dates its department gives (Settings > แผนก), and hears
 * about them. Replaces Settings > การส่งต่องาน, where a whole department passed everything to the next.
 */
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
        $this->purchasing = Department::create(['name' => 'จัดซื้อ', 'code' => 'PUR', 'is_active' => true, 'handoff_date_label' => 'วันของเข้า']);
        $this->store = Department::create(['name' => 'คลัง', 'code' => 'STO', 'is_active' => true]);
        $this->production = Department::create(['name' => 'Production', 'code' => 'PRO', 'is_active' => true]);
        $this->other = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'H'.++$n, 'email' => 'hand'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    /** One Sub Task of $d in the cabinet with these items. @return array{0: CabinetSubtask, 1: list<CabinetChecklist>} */
    private function work(Department $d, array $items, string $status = 'ACCEPTED', ?string $name = null): array
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $name ?? $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'due_date' => '2026-10-20', 'status' => 'not_started', 'progress' => 0]);

        return [$s, array_map(fn ($n, $i) => CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $n, 'is_completed' => false, 'sequence' => $i + 1]), $items, array_keys($items))];
    }

    private function pair(CabinetSubtask $to, CabinetSubtask $from): void
    {
        $to->pairSources()->syncWithoutDetaching([$from->id]);
        app(HandoffService::class)->forgetPairs();
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

    public function test_only_the_paired_sub_task_shows_next_to_mine_read_only_with_its_dates(): void
    {
        [$order, [$breaker, $busbar]] = $this->work($this->purchasing, ['Breaker 100A', 'Busbar'], name: 'สั่งซื้ออุปกรณ์');
        $this->work($this->purchasing, ['ขอใบเสนอราคา'], name: 'ติดตามใบเสนอราคา');   // not paired: never shown
        [$receive, [$storeBreaker]] = $this->work($this->store, ['breaker 100a ', 'Busbar 2'], name: 'ตรวจรับอุปกรณ์');
        $this->pair($receive, $order);
        $breaker->update(['is_completed' => true, 'expected_date' => '2026-10-20', 'remark' => 'รอบเดียว']);
        $storeBreaker->update(['is_completed' => true]);

        $linked = $this->cab($this->user($this->store))['linked'];
        $this->assertSame([['s'.$order->id, 'สั่งซื้ออุปกรณ์', 'd_'.$this->purchasing->id, 'ตรวจรับอุปกรณ์']], array_map(fn ($l) => [$l['id'], $l['title'], $l['deptId'], $l['for']], $linked));
        $items = collect($linked[0]['items'])->keyBy('text');
        $this->assertSame(['2026-10-20', 'รอบเดียว', true, true], [$items['Breaker 100A']['expected'], $items['Breaker 100A']['remark'], $items['Breaker 100A']['done'], $items['Breaker 100A']['received']],
            'the same name in my list, ticked = I have dealt with it');
        $this->assertFalse($items['Busbar']['received']);
        $this->assertSame([1, 2], [$linked[0]['done'], $linked[0]['total']]);

        $this->assertSame([], $this->cab($this->user($this->purchasing))['linked'], 'nothing is passed backwards');
        $this->assertSame([], $this->cab($this->user(null, 'project_manager'))['linked'], 'PMO already sees every checklist');

        $editor = app(\App\Services\ChecklistEditor::class);
        $this->assertSame('วันของเข้า', $editor->state('subtask', $order, $this->user(null, 'admin'))['dateLabel'], 'a date where something compares with it');
        $quotes = CabinetSubtask::where('name', 'ติดตามใบเสนอราคา')->first();
        $this->assertNull($editor->state('subtask', $quotes, $this->user(null, 'admin'))['dateLabel'], 'not on the other work of the same department');
    }

    public function test_a_sub_task_can_compare_with_several_and_the_cabinet_page_shows_both_sides(): void
    {
        [$order, [$breaker, $busbar]] = $this->work($this->purchasing, ['Breaker 100A', 'Busbar'], name: 'สั่งซื้ออุปกรณ์');
        [$cabinetBuy] = $this->work($this->purchasing, ['ตัวตู้'], name: 'สั่งซื้อตัวตู้');
        [$receive, [$storeBreaker]] = $this->work($this->store, ['Breaker 100A', 'อื่น ๆ'], name: 'ตรวจรับอุปกรณ์');
        $this->pair($receive, $order);
        $this->pair($receive, $cabinetBuy);
        $breaker->update(['expected_date' => '2026-10-20', 'remark' => 'รอบเดียว']);
        $storeBreaker->update(['is_completed' => true]);

        // the source side: each item says whether คลัง has got it
        $items = collect(app(\App\Services\ChecklistEditor::class)->items('subtask', $order->load('cabinetTask')))->keyBy('name');
        $this->assertSame([['dept' => 'คลัง', 'received' => true]], $items['Breaker 100A']['handoff']);
        $this->assertSame([['dept' => 'คลัง', 'received' => false]], $items['Busbar']['handoff']);

        // the receiving side: both lists, in the cabinet's order - whoever opens the page (here: Admin)
        $linked = app(HandoffService::class)->linkedForSubtask($receive->load('cabinetTask', 'checklists'));
        $this->assertSame(['สั่งซื้ออุปกรณ์', 'สั่งซื้อตัวตู้'], array_column($linked, 'title'));
        $this->assertSame(['จัดซื้อ', 'วันของเข้า', 0, 2], [$linked[0]['deptName'], $linked[0]['dateLabel'], $linked[0]['done'], $linked[0]['total']]);
        $html = $this->actingAs($this->user(null, 'admin'))->get(route('cabinets.show', $this->cabinet))->assertOk()->getContent();
        // where the other side's work is, and a link to it (so a stalled one can be chased) - both ways
        $this->assertSame(route('cabinets.show', $this->cabinet).'#subtask-'.$order->id, $linked[0]['href']);
        $this->assertNotSame('', $linked[0]['state']);
        $receivers = app(HandoffService::class)->receiversOf($order->load('cabinetTask'));
        $this->assertSame([['ตรวจรับอุปกรณ์', 'คลัง', route('cabinets.show', $this->cabinet).'#subtask-'.$receive->id]],
            array_map(fn ($r) => [$r['title'], $r['deptName'], $r['href']], $receivers));
        // the page hands the list to the editor through @js() (quotes and Thai escaped): look for it the same way
        $inJs = fn (array $a) => substr((string) \Illuminate\Support\Js::from($a), strlen("JSON.parse('{"), -strlen("}')"));
        $this->assertStringContainsString($inJs(['title' => 'สั่งซื้อตัวตู้']), $html);
    }

    public function test_pairs_come_from_the_template_follow_copies_and_go_to_older_cabinets(): void
    {
        $template = CabinetTemplate::create(['name' => 'DB', 'code' => 'DB', 'is_active' => true]);
        $buy = CabinetTaskTemplate::create(['cabinet_template_id' => $template->id, 'name' => '2. จัดซื้อ', 'sequence' => 1]);
        $make = CabinetTaskTemplate::create(['cabinet_template_id' => $template->id, 'name' => '3. ผลิต', 'sequence' => 2]);
        $order = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $buy->id, 'name' => 'สั่งซื้ออุปกรณ์', 'department_id' => $this->purchasing->id, 'sequence' => 1]);
        $quotes = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $buy->id, 'name' => 'ใบเสนอราคา', 'department_id' => $this->purchasing->id, 'sequence' => 2]);
        $receive = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $make->id, 'name' => 'ตรวจรับอุปกรณ์', 'department_id' => $this->production->id, 'sequence' => 1]);
        $pairs = app(SubtaskPairs::class);

        // an older cabinet, made before the pairs were set; one of its Sub Tasks has lost its template link (matched by name)
        $older = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-OLD', 'cabinet_name' => 'Old', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        app(CabinetTemplateService::class)->applyToCabinet($older, $template->fresh());
        CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $older->id))->where('name', 'ตรวจรับอุปกรณ์')->update(['cabinet_subtask_template_id' => null]);

        $pairs->setTemplatePairs($receive, [$order->id, $quotes->id]);
        $pairs->setTemplatePairs($receive, [$order->id]);   // changed its mind: one only
        $this->assertSame([$order->id], $receive->pairSources()->pluck('cabinet_subtask_templates.id')->all());
        $other = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => CabinetTaskTemplate::create(['cabinet_template_id' => CabinetTemplate::create(['name' => 'X', 'code' => 'X', 'is_active' => true])->id, 'name' => 'x', 'sequence' => 1])->id, 'name' => 'x', 'sequence' => 1]);
        $this->expectsOnlyOwnTemplate(fn () => $pairs->setTemplatePairs($receive, [$other->id]));

        $pairOf = fn (Cabinet $c) => CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $c->id))->where('name', 'ตรวจรับอุปกรณ์')->first()
            ->pairSources()->pluck('name')->all();

        $fresh = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-NEW', 'cabinet_name' => 'New', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        app(CabinetTemplateService::class)->applyToCabinet($fresh, $template->fresh());
        $this->assertSame(['สั่งซื้ออุปกรณ์'], $pairOf($fresh), 'a new cabinet takes the template pairs');

        $copy = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-COPY', 'cabinet_name' => 'Copy', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0,
            'cabinet_template_id' => $template->id]);   // as CabinetController::copy() and the project copy set it
        app(CabinetCopyService::class)->copyFrom($fresh->load('tasks.subtasks.checklists'), $copy);
        $this->assertSame(['สั่งซื้ออุปกรณ์'], $pairOf($copy), 'a copied cabinet keeps them, between its own Sub Tasks');

        $this->assertSame([], $pairOf($older));
        $this->assertSame(3, $pairs->applyToCabinets($template->fresh()), 'the older cabinet too (and the two that already had them)');
        $this->assertSame(['สั่งซื้ออุปกรณ์'], $pairOf($older), 'matched by name where the template link is gone');

        $dup = app(TemplateTools::class)->duplicateTemplate($template, $this->user(null, 'admin'));
        $dupReceive = CabinetSubtaskTemplate::whereHas('taskTemplate', fn ($q) => $q->where('cabinet_template_id', $dup->id))->where('name', 'ตรวจรับอุปกรณ์')->first();
        $this->assertSame(['สั่งซื้ออุปกรณ์'], $dupReceive->pairSources->pluck('name')->all());
        $this->assertNotSame($order->id, $dupReceive->pairSources->first()->id, 'pointing at the copy\'s own Sub Task');

        // the template page: the picker and the button, for people who may edit templates
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->get(route('cabinet-templates.show', $template))->assertOk()->assertSee('ใช้คู่เทียบกับตู้เดิม (3 ตู้)')->assertSee('เทียบกับ');
        $this->actingAs($admin)->putJson(route('subtask-templates.pairs', $receive), ['sources' => [$quotes->id]])->assertOk()->assertJsonPath('sources.0.name', 'ใบเสนอราคา');
        $this->actingAs($this->user($this->store))->putJson(route('subtask-templates.pairs', $receive), ['sources' => []])->assertForbidden();
        $this->actingAs($admin)->post(route('cabinet-templates.pairs.apply', $template))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(['ใบเสนอราคา'], $pairOf($older));
    }

    private function expectsOnlyOwnTemplate(callable $fn): void
    {
        try {
            $fn();
            $this->fail('a Sub Task of another template must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('sources', $e->errors());
        }
    }

    public function test_a_department_with_no_work_on_the_project_sees_nothing_of_it(): void
    {
        $this->work($this->purchasing, ['Breaker']);
        $this->assertSame([], collect(app(PlanningBoard::class)->build($this->user($this->store))['boards']['pmo']['tasks'])->all());
    }

    public function test_the_source_sets_dates_per_item_or_for_the_whole_cabinet_and_the_receiver_hears_it(): void
    {
        [$order, [$a, $b]] = $this->work($this->purchasing, ['Breaker', 'Busbar']);
        [$receive] = $this->work($this->store, ['รับของ']);
        $this->pair($receive, $order);
        $buyer = $this->user($this->purchasing);
        $keeper = $this->user($this->store);
        $stranger = $this->user($this->other);

        $this->assertTrue($this->apply($buyer, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok']);
        $this->assertSame('2026-10-20', $a->fresh()->expected_date->format('Y-m-d'));
        $notice = $keeper->notifications()->first();
        $this->assertNotNull($notice, 'the store hears it');
        $this->assertStringContainsString('จัดซื้อ ลงวันของเข้า 1 รายการ', $notice->data['body']);
        $this->assertSame(0, $buyer->notifications()->count(), 'not the person who did it');
        $this->assertSame(0, $stranger->notifications()->count(), 'nor a department not comparing');

        $this->assertFalse($this->apply($stranger, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$b->id, 'date' => '2026-10-21'])['ok']);
        [$storeItem] = $receive->checklists->all();
        $this->assertFalse($this->apply($keeper, ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$storeItem->id, 'date' => '2026-10-21'])['ok'], 'the store gives no date');

        $r = $this->apply($buyer, ['type' => 'checklist_date_all', 'taskId' => 'p'.$this->project->id, 'cabinetId' => 'c'.$this->cabinet->id, 'deptId' => 'd_'.$this->purchasing->id, 'date' => '2026-10-22']);
        $this->assertTrue($r['ok']);
        $this->assertSame(['2026-10-22', '2026-10-22'], [$a->fresh()->expected_date->format('Y-m-d'), $b->fresh()->expected_date->format('Y-m-d')]);
        $this->assertSame(1, $keeper->notifications()->count(), 'folded into the one project notice');
        $this->assertStringContainsString('จัดซื้อ ลงวันของเข้า 3 รายการ', $keeper->notifications()->first()->data['body']);
    }

    public function test_the_source_must_have_accepted_its_work_before_giving_dates(): void
    {
        [, [$a]] = $this->work($this->purchasing, ['Breaker'], 'ASSIGNED');
        $this->assertFalse($this->apply($this->user($this->purchasing), ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok']);
        $this->assertTrue($this->apply($this->user(null, 'admin'), ['type' => 'checklist_date', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$a->id, 'date' => '2026-10-20'])['ok'], 'Admin / PM may');
    }

    public function test_ticking_tells_the_department_comparing_and_a_finished_list_once_more(): void
    {
        [$given, [$a, $b]] = $this->work($this->store, ['Breaker', 'Busbar']);
        [$fit] = $this->work($this->production, ['ติดตั้ง']);
        [$unpaired, [$c]] = $this->work($this->store, ['อื่น'], name: 'งานอื่นของคลัง');
        $this->pair($fit, $given);
        $keeper = $this->user($this->store);
        $maker = $this->user($this->production);
        $qc = $this->user($this->other);

        app(ProgressService::class)->toggleChecklist($c, true, $keeper);
        $this->assertSame(0, $maker->notifications()->count(), 'work nobody compares with is not announced');
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
        [$order, [$a, $b, $c]] = $this->work($this->purchasing, ['Breaker', 'Busbar', 'CT']);
        [$receive, [$storeBreaker]] = $this->work($this->store, ['Breaker', 'อื่น ๆ']);
        $this->pair($receive, $order);
        $a->update(['expected_date' => '2026-10-07']);   // today
        $b->update(['expected_date' => '2026-10-05']);   // late
        $c->update(['expected_date' => '2026-10-30']);   // later: not yet
        $keeper = $this->user($this->store);
        $buyer = $this->user($this->purchasing);

        app(NotificationService::class)->sendReminders();
        $store = $keeper->notifications()->first()->data;
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $store['body']);
        $this->assertStringContainsString('📦 วันของเข้าวันนี้ 1 รายการ', $store['body']);
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $buyer->notifications()->first()->data['body'], 'the source is chased about the late one');

        app(NotificationService::class)->sendReminders();
        $this->assertSame(1, $keeper->notifications()->count(), 'once a day');

        $storeBreaker->update(['is_completed' => true]);   // the store got the breaker
        $this->travelTo('2026-10-08 09:00:00');
        $keeper->notifications()->update(['read_at' => now()]);
        app(NotificationService::class)->sendReminders();
        $this->assertStringContainsString('📦 เลยวันของเข้า 1 รายการ', $keeper->notifications()->latest()->first()->data['body'], 'only Busbar is still outstanding');
    }

    public function test_the_date_label_is_set_on_the_department_and_the_old_settings_page_is_gone(): void
    {
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->put(route('settings.departments.update', $this->store), ['name' => 'คลัง', 'code' => 'STO', 'is_active' => 1, 'handoff_date_label' => ' วันจ่ายของ '])->assertSessionHasNoErrors();
        $this->assertSame('วันจ่ายของ', $this->store->fresh()->handoff_date_label);
        $this->actingAs($admin)->put(route('settings.departments.update', $this->store), ['name' => 'คลัง', 'code' => 'STO', 'is_active' => 1, 'handoff_date_label' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->store->fresh()->handoff_date_label);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('settings.handoffs.index'));
    }

    public function test_admin_or_pm_can_change_one_cabinets_pairs_on_the_cabinet_page(): void
    {
        [$order] = $this->work($this->purchasing, ['Breaker 100A'], name: 'สั่งซื้ออุปกรณ์');
        [$receive] = $this->work($this->store, ['Breaker 100A'], name: 'ตรวจรับอุปกรณ์');
        $url = route('cabinet-subtasks.pairs', $receive);

        $this->actingAs($this->user($this->store))->putJson($url, ['sources' => [$order->id]])->assertForbidden();
        $this->actingAs($this->user(null, 'project_manager'))->putJson($url, ['sources' => [$order->id]])->assertOk()->assertJsonPath('sources.0.name', 'สั่งซื้ออุปกรณ์');
        $this->assertSame([$order->id], $receive->pairSources()->pluck('cabinet_subtasks.id')->all());
        $this->assertDatabaseHas('activity_logs', ['description' => "ตั้งคู่เทียบ ตรวจรับอุปกรณ์ ({$this->cabinet->mo_no}) กับ สั่งซื้ออุปกรณ์"]);
        $this->assertStringContainsString('cabinet-subtasks\\/'.$receive->id.'\\/pairs', $this->actingAs($this->user(null, 'admin'))->get(route('cabinets.show', $this->cabinet))->getContent());

        // another cabinet's Sub Task: refused
        $other = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-2', 'cabinet_name' => 'Cab 2', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $otherTask = CabinetTask::forceCreate(['cabinet_id' => $other->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $elsewhere = CabinetSubtask::forceCreate(['cabinet_task_id' => $otherTask->id, 'name' => 'สั่งซื้ออุปกรณ์', 'department_id' => $this->purchasing->id, 'status' => 'not_started', 'progress' => 0]);
        $this->actingAs($this->user(null, 'admin'))->putJson($url, ['sources' => [$elsewhere->id]])->assertUnprocessable();

        $this->actingAs($this->user(null, 'admin'))->putJson($url, ['sources' => []])->assertOk();
        $this->assertSame(0, $receive->pairSources()->count());
    }
}
