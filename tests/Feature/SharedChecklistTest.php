<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTask;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\CabinetTemplateService;
use App\Services\HandoffService;
use App\Services\PlanningSync;
use App\Services\SubtaskPairs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One list for a pair (SharedChecklist): the Sub Task comparing (ผลิต "ตรวจรับ") holds a copy of each item of the one it
 * compares with (จัดซื้อ "สั่งซื้อ"). The source owns the list; each side ticks its own copy and sees the other's.
 */
class SharedChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Department $purchasing;

    private Department $production;

    private Project $project;

    private CabinetTask $task;

    private CabinetSubtask $buy;      // the source: จัดซื้อ

    private CabinetSubtask $receive;  // comparing with it: ผลิต

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Department::create(['name' => 'จัดซื้อ', 'code' => 'PUR', 'is_active' => true]);
        $this->production = Department::create(['name' => 'ผลิต', 'code' => 'PRO', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->buy = $this->work($this->purchasing, 'สั่งซื้ออุปกรณ์', ['Breaker 100A', 'สายไฟ 25']);
        // the receiving side already has one of them (ticked) and one of its own
        $this->receive = $this->work($this->production, 'ตรวจรับอุปกรณ์', ['สายไฟ  25', 'ตรวจสภาพกล่อง'], [0]);
    }

    private function work(Department $d, string $name, array $items, array $ticked = []): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $name, 'department_id' => $d->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        foreach ($items as $i => $n) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $n, 'is_completed' => in_array($i, $ticked, true), 'sequence' => $i + 1]);
        }

        return $s;
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'S'.++$n, 'email' => 'shared'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function pairUp(): void
    {
        app(SubtaskPairs::class)->setCabinetPairs($this->receive, [$this->buy->id]);
    }

    /** @return list<string> name, "🔗" for a copy, "✓" when ticked */
    private function list(CabinetSubtask $s): array
    {
        return CabinetChecklist::where('cabinet_subtask_id', $s->id)->orderBy('sequence')->orderBy('id')->get()
            ->map(fn ($c) => ($c->source_checklist_id ? '🔗' : '').$c->name.($c->is_completed ? ' ✓' : ''))->all();
    }

    private function edit(User $u, CabinetSubtask $s, array $op)
    {
        return $this->actingAs($u)->postJson(route('checklist-editor.apply', ['subtask', $s->id]), $op);
    }

    private function item(CabinetSubtask $s, string $name): CabinetChecklist
    {
        return CabinetChecklist::where('cabinet_subtask_id', $s->id)->where('name', $name)->firstOrFail();
    }

    public function test_pairing_links_the_same_names_adds_the_missing_and_keeps_the_receivers_own(): void
    {
        $this->pairUp();

        // "สายไฟ  25" (two spaces) is the same item: it becomes the copy, its tick and place kept, its name the source's
        $this->assertSame(['🔗Breaker 100A', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
        $this->assertSame(['Breaker 100A', 'สายไฟ 25'], $this->list($this->buy), 'the source is untouched');
        $this->assertSame(33, $this->receive->fresh()->progress, 'its progress counts the new item');

        // set again: nothing doubles
        $this->pairUp();
        $this->assertCount(3, $this->list($this->receive));

        // the source sees, per item, whether the receiver has it
        $receipts = app(HandoffService::class)->receiptsFor($this->buy->load('checklists'));
        $this->assertSame([false, true], collect($receipts)->map(fn ($r) => $r[0]['received'])->values()->all());
    }

    public function test_the_source_owns_the_list_add_rename_order_reach_the_copies(): void
    {
        $this->pairUp();
        $buyer = $this->user($this->purchasing);

        $this->edit($buyer, $this->buy, ['type' => 'add', 'items' => ['ตู้เหล็ก']])->assertOk();
        $this->edit($buyer, $this->buy, ['type' => 'update', 'id' => $this->item($this->buy, 'Breaker 100A')->id, 'name' => 'Breaker 125A'])->assertOk();
        $ids = CabinetChecklist::where('cabinet_subtask_id', $this->buy->id)->orderByDesc('sequence')->pluck('id')->all();
        $this->edit($buyer, $this->buy, ['type' => 'reorder', 'ids' => $ids])->assertOk();

        $this->assertSame(['ตู้เหล็ก', 'สายไฟ 25', 'Breaker 125A'], $this->list($this->buy));
        // the copies follow, in the places copies hold; the receiver's own item keeps its place
        $this->assertSame(['🔗ตู้เหล็ก', '🔗สายไฟ 25 ✓', '🔗Breaker 125A', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
        $this->assertSame(25, $this->receive->fresh()->progress);
    }

    public function test_the_receiver_ticks_and_notes_its_copy_but_cannot_rename_delete_or_move_it(): void
    {
        $this->pairUp();
        $maker = $this->user($this->production);
        $pm = $this->user(null, 'project_manager');
        $copy = $this->item($this->receive, 'Breaker 100A');

        $this->edit($maker, $this->receive, ['type' => 'update', 'id' => $copy->id, 'name' => 'อื่น'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'แก้ชื่อ / ลบ / ย้าย ได้ที่ต้นทาง') && str_contains($m, 'จัดซื้อ'));
        $this->edit($pm, $this->receive, ['type' => 'delete', 'ids' => [$copy->id]])->assertStatus(422);   // Admin / PM too: at the source
        $other = $this->work($this->production, 'ประกอบ', []);
        $this->edit($pm, $this->receive, ['type' => 'move', 'ids' => [$copy->id], 'to' => $other->id])->assertStatus(422);
        $this->edit($pm, $this->buy, ['type' => 'move', 'ids' => [$this->item($this->buy, 'Breaker 100A')->id], 'to' => $other->id])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ใช้ร่วมกับแผนกที่เทียบ'));

        $this->edit($maker, $this->receive, ['type' => 'update', 'id' => $copy->id, 'remark' => 'ของมาไม่ครบ'])->assertOk();
        $this->assertSame('ของมาไม่ครบ', $copy->fresh()->remark);
        $this->assertNull($this->item($this->buy, 'Breaker 100A')->remark, 'a remark is each side\'s own');

        // its own items stay its own: added, renamed, deleted freely, never sent back
        $this->edit($maker, $this->receive, ['type' => 'add', 'items' => ['ถ่ายรูป']])->assertOk();
        $this->assertSame(['Breaker 100A', 'สายไฟ 25'], $this->list($this->buy));

        // the editor tells the page which items are copies (and of which item)
        $items = collect($this->edit($maker, $this->receive, ['type' => 'reorder', 'ids' => []])->json('items'));
        $this->assertSame('k'.$this->item($this->buy, 'Breaker 100A')->id, $items->firstWhere('name', 'Breaker 100A')['from']);
        $this->assertNull($items->firstWhere('name', 'ถ่ายรูป')['from']);
    }

    public function test_deleting_takes_the_copies_along_and_undo_brings_them_back_but_not_once_the_receiver_ticked(): void
    {
        $this->pairUp();
        $buyer = $this->user($this->purchasing);

        $r = $this->edit($buyer, $this->buy, ['type' => 'delete', 'ids' => [$this->item($this->buy, 'Breaker 100A')->id]])->assertOk();
        $this->assertSame(['🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
        $this->assertSame(50, $this->receive->fresh()->progress, 'the receiver\'s progress follows (1 of 2)');
        $this->edit($buyer, $this->buy, $r->json('undo'))->assertOk();
        $this->assertSame(['🔗Breaker 100A', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));

        $this->edit($buyer, $this->buy, ['type' => 'delete', 'ids' => [$this->item($this->buy, 'สายไฟ 25')->id]])->assertStatus(422)
            ->assertJsonPath('message', 'ลบไม่ได้ — ผลิต ติ๊ก "สายไฟ 25" แล้ว');
        $this->assertCount(2, $this->list($this->buy));
    }

    public function test_a_finished_receiver_gets_no_new_items_and_a_dropped_pair_leaves_the_items_as_its_own(): void
    {
        $this->pairUp();
        $this->receive->forceFill(['assignment_status' => 'COMPLETED'])->saveQuietly();
        $this->edit($this->user($this->purchasing), $this->buy, ['type' => 'add', 'items' => ['ตู้เหล็ก']])->assertOk();
        $this->assertNotContains('🔗ตู้เหล็ก', $this->list($this->receive), 'it would reopen finished work');

        // a list merely all ticked, not closed, still gets new work
        $this->receive->forceFill(['assignment_status' => 'IN_PROGRESS'])->saveQuietly();
        CabinetChecklist::where('cabinet_subtask_id', $this->receive->id)->update(['is_completed' => true]);
        $this->edit($this->user($this->purchasing), $this->buy, ['type' => 'add', 'items' => ['ฝาตู้']])->assertOk();
        $this->assertContains('🔗ฝาตู้', $this->list($this->receive));
        $this->receive->forceFill(['assignment_status' => 'COMPLETED'])->saveQuietly();
        CabinetChecklist::where('cabinet_subtask_id', $this->receive->id)->where('name', 'ฝาตู้')->forceDelete();

        app(SubtaskPairs::class)->setCabinetPairs($this->receive, []);
        $this->assertSame(['Breaker 100A ✓', 'สายไฟ 25 ✓', 'ตรวจสภาพกล่อง ✓'], $this->list($this->receive), 'no longer copies: its own items');
    }

    public function test_planning_add_rename_order_delete_follow_the_same_rules(): void
    {
        $this->pairUp();
        $buyer = $this->user($this->purchasing);
        $maker = $this->user($this->production);
        $op = fn (User $u, array $o) => app(PlanningSync::class)->apply($u, [$o + ['taskId' => 'p'.$this->project->id]])[0];

        $this->assertTrue($op($buyer, ['type' => 'checklist_add', 'cabinetId' => 'c'.$this->task->cabinet_id, 'subtaskId' => 's'.$this->buy->id, 'text' => 'ตู้เหล็ก'])['ok']);
        $this->assertTrue($op($buyer, ['type' => 'checklist_rename', 'itemId' => 'k'.$this->item($this->buy, 'ตู้เหล็ก')->id, 'text' => 'ตู้เหล็ก 2 มม.'])['ok']);
        $this->assertTrue($op($buyer, ['type' => 'checklist_move', 'itemId' => 'k'.$this->item($this->buy, 'ตู้เหล็ก 2 มม.')->id, 'dir' => 'up'])['ok']);
        $this->assertSame(['🔗Breaker 100A', '🔗ตู้เหล็ก 2 มม.', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));

        $refused = $op($maker, ['type' => 'checklist_rename', 'itemId' => 'k'.$this->item($this->receive, 'Breaker 100A')->id, 'text' => 'x']);
        $this->assertFalse($refused['ok']);
        $this->assertFalse($op($maker, ['type' => 'checklist_delete', 'itemId' => 'k'.$this->item($this->receive, 'Breaker 100A')->id])['ok']);
        $this->assertFalse($op($buyer, ['type' => 'checklist_delete', 'itemId' => 'k'.$this->item($this->buy, 'สายไฟ 25')->id])['ok'], 'the receiver ticked it');

        $this->assertTrue($op($buyer, ['type' => 'checklist_delete', 'itemId' => 'k'.$this->item($this->buy, 'ตู้เหล็ก 2 มม.')->id])['ok']);
        $this->assertSame(['🔗Breaker 100A', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
    }

    public function test_two_sub_tasks_comparing_with_each_other_share_without_loops(): void
    {
        $this->pairUp();
        app(SubtaskPairs::class)->setCabinetPairs($this->buy, [$this->receive->id]);   // and back

        // each takes only the other's own items: the receiver's "ตรวจสภาพกล่อง" reaches the buyer, nothing goes round
        $this->assertSame(['🔗ตรวจสภาพกล่อง', 'Breaker 100A', 'สายไฟ 25'], $this->list($this->buy));
        $this->assertSame(['🔗Breaker 100A', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
        $this->pairUp();
        app(SubtaskPairs::class)->setCabinetPairs($this->buy, [$this->receive->id]);
        $this->assertCount(3, $this->list($this->buy));
        $this->assertCount(3, $this->list($this->receive));
    }

    public function test_a_cabinet_made_from_a_template_starts_with_the_shared_list(): void
    {
        $template = CabinetTemplate::create(['name' => 'DB', 'code' => 'DB', 'is_active' => true]);
        $t = CabinetTaskTemplate::create(['cabinet_template_id' => $template->id, 'name' => 'งาน', 'sequence' => 1]);
        $order = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $t->id, 'name' => 'สั่งซื้อ', 'department_id' => $this->purchasing->id, 'sequence' => 1]);
        $check = CabinetSubtaskTemplate::create(['cabinet_task_template_id' => $t->id, 'name' => 'ตรวจรับ', 'department_id' => $this->production->id, 'sequence' => 2]);
        foreach (['A', 'B'] as $i => $n) {
            ChecklistTemplate::create(['cabinet_subtask_template_id' => $order->id, 'name' => $n, 'sequence' => $i + 1]);
        }
        ChecklistTemplate::create(['cabinet_subtask_template_id' => $check->id, 'name' => 'B', 'sequence' => 1]);
        app(SubtaskPairs::class)->setTemplatePairs($check, [$order->id]);

        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-T', 'cabinet_name' => 'T', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        app(CabinetTemplateService::class)->applyToCabinet($cab, $template->fresh());
        $made = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cab->id))->where('name', 'ตรวจรับ')->first();
        $this->assertSame(['🔗A', '🔗B'], $this->list($made), 'its own "B" from the template is the copy, "A" added');
    }

    public function test_the_project_wide_editor_renames_at_the_source_and_skips_copies(): void
    {
        $this->pairUp();
        $pm = $this->user(null, 'project_manager');
        $run = fn (string $sub, array $body) => $this->actingAs($pm)->postJson(route('projects.checklist-bulk.run', $this->project), $body + ['subtask' => $sub, 'preview' => false]);

        $run('ตรวจรับอุปกรณ์', ['action' => 'rename', 'find' => 'Breaker 100A', 'replace' => 'X'])->assertOk()->assertJsonPath('changes', 0);
        $run('สั่งซื้ออุปกรณ์', ['action' => 'rename', 'find' => 'Breaker 100A', 'replace' => 'Breaker 125A'])->assertOk()->assertJsonPath('changes', 1);
        $this->assertSame(['🔗Breaker 125A', '🔗สายไฟ 25 ✓', 'ตรวจสภาพกล่อง'], $this->list($this->receive));
        $run('สั่งซื้ออุปกรณ์', ['action' => 'delete', 'names' => ['สายไฟ 25']])->assertOk()->assertJsonPath('changes', 0);
    }

    public function test_the_cabinet_page_hears_of_a_change_without_a_reload(): void
    {
        $this->pairUp();
        $buyer = $this->user($this->purchasing);
        $url = route('cabinets.checklists-live', $this->task->cabinet_id);

        $v = $this->actingAs($buyer)->getJson($url)->assertOk()->json('version');
        $this->actingAs($buyer)->getJson($url.'?v='.$v)->assertOk()->assertExactJson(['version' => $v]);   // nothing moved: only the stamp

        $this->edit($buyer, $this->buy, ['type' => 'add', 'items' => ['ตู้เหล็ก']])->assertOk();
        $r = $this->actingAs($buyer)->getJson($url.'?v='.$v)->assertOk();
        $this->assertNotSame($v, $r->json('version'));
        $this->assertContains('ตู้เหล็ก', array_column($r->json('lists.'.$this->receive->id.'.items'), 'name'), 'the shared list, for the other Sub Task on the page');
        $this->assertSame(4, collect($r->json('progress'))->firstWhere('subtask.id', $this->receive->id)['subtask']['total_checklists']);

        // a tick moves it too
        $v = $r->json('version');
        $this->actingAs($this->user($this->production))->patchJson(route('checklists.toggle', $this->item($this->receive, 'ตู้เหล็ก')), ['is_completed' => true])->assertOk();
        $this->assertNotSame($v, $this->actingAs($buyer)->getJson($url.'?v='.$v)->json('version'));
    }
}
