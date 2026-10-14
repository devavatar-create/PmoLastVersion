<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /planning/board - the Planning page written as a normal Blade + Alpine page (no embedded SPA). */
class PlanningBoardPageTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB <b>x</b>', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'high', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $cab = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        foreach ([$this->busbar, $this->wiring] as $d) {
            $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => 'ACCEPTED', 'due_date' => '2026-10-20', 'status' => 'not_started', 'progress' => 0]);
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => 'secret-'.$d->code, 'is_completed' => false, 'sequence' => 1]);
        }
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'board'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    public function test_guests_go_to_login(): void
    {
        $this->get(route('planning.board'))->assertRedirect(route('login'));
    }

    public function test_the_page_is_a_normal_blade_page_with_one_alpine_component(): void
    {
        $res = $this->actingAs($this->user($this->busbar, 'project_manager'))->get(route('planning.board'))->assertOk();
        $html = $res->getContent();

        // the app layout (sidebar etc.), not a stand-alone page
        $this->assertStringContainsString('Avatar Electric', $html);
        $this->assertStringContainsString('x-data="planningBoard(', $html);
        foreach (['งานที่ต้องเสร็จ', 'ไทม์ไลน์', 'แดชบอร์ด', 'ส่งต่อแผนก', 'Activity', 'ลบโครงการ'] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing: $needle");
        }
        $this->assertStringContainsString("Alpine.data('planningBoard'", file_get_contents(resource_path('js/planning-board.js')), 'the component lives in the bundle');
        $this->assertStringContainsString('registerPlanningBoard()', file_get_contents(resource_path('js/app.js')));
        $this->assertMatchesRegularExpression('#planning.{1,8}sync#', $html, 'edits go through the existing operations endpoint (@js escapes the slash)');
        $this->assertStringNotContainsString('__PMO__', $html, 'no embedded SPA / config script');
        $this->assertNotNull($res->headers->get('Server-Timing'));
        // the page's own templates only (~90 KB of Blade + the app layout), not the 600 KB single-file SPA, and not its script:
        // the Alpine component moved into the cached bundle (resources/js/planning-board.js), so the page dropped from ~290 KB.
        // gzip (GzipResponse) sends a small fraction of it.
        $this->assertLessThan(240 * 1024, strlen($html));
    }

    public function test_pmo_roles_can_edit_and_department_users_start_on_their_own_department(): void
    {
        $pm = $this->actingAs($this->user($this->busbar, 'project_manager'))->get(route('planning.board'))->assertOk()->viewData('config');
        $this->assertTrue($pm['canEdit']);
        $this->assertSame('', $pm['myDept'], 'PMO roles see every department');

        $member = $this->actingAs($this->user($this->busbar))->get(route('planning.board'))->assertOk()->viewData('config');
        $this->assertFalse($member['canEdit'], 'project data and dates: Admin / PM only');
        $this->assertFalse($member['isPmo']);
        $this->assertTrue($pm['isPmo']);
        $this->assertSame('d_'.$this->busbar->id, $member['myDept']);
    }

    public function test_a_department_user_only_receives_their_own_departments_data(): void
    {
        $html = $this->actingAs($this->user($this->busbar))->get(route('planning.board'))->assertOk()->getContent();

        $this->assertStringContainsString('secret-BUS', $html);
        $this->assertStringNotContainsString('secret-WIR', $html, "another department's checklist items never reach the page");
    }

    public function test_user_written_text_is_escaped_not_injected(): void
    {
        $html = $this->actingAs($this->user($this->busbar, 'admin'))->get(route('planning.board'))->assertOk()->getContent();

        $this->assertStringNotContainsString('MDB <b>x</b>', $html);
        $this->assertStringContainsString('MDB', $html);
    }

    public function test_the_at_picker_lists_only_people_the_user_may_tag(): void
    {
        $mate = $this->user($this->busbar);
        $pm = $this->user($this->wiring, 'project_manager');
        $other = $this->user($this->wiring);                   // another department, not PMO
        $gone = $this->user($this->busbar);
        $gone->update(['is_active' => false]);
        $me = $this->user($this->busbar);

        $names = fn ($u) => array_column($this->actingAs($u)->get(route('planning.board'))->assertOk()->viewData('config')['people'], 'name');

        $member = $names($me);
        $this->assertEqualsCanonicalizing([$mate->name, $pm->name], $member, 'own department + PMO roles; not myself, not others, not inactive');
        $this->assertNotContains($other->name, $member);
        $this->assertNotContains($gone->name, $member);

        $all = $names($pm);
        $this->assertContains($other->name, $all);
        $this->assertContains($me->name, $all);
        $this->assertNotContains($pm->name, $all);
        $this->assertNotContains($gone->name, $all);
    }

    public function test_a_notification_link_opens_the_project(): void
    {
        $project = Project::where('project_no', 'P-1')->first();

        $this->assertSame($project->id, $this->actingAs($this->user($this->busbar, 'admin'))->get(route('planning.board', ['project' => $project->id]))->viewData('config')['open']);
        $this->assertNull($this->actingAs($this->user($this->busbar, 'admin'))->get(route('planning.board'))->viewData('config')['open']);
    }
}
