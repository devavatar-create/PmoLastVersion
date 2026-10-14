<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningPageTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'urgent', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'plan'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    /** one cabinet with a Busbar and a Wiring Sub Task, each with checklist items */
    private function seedCabinet(string $busbarStatus = 'IN_PROGRESS', string $wiringStatus = 'ACCEPTED', string $wiringDue = '2026-10-20'): Cabinet
    {
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab 1', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        foreach ([[$this->busbar, $busbarStatus, '2026-10-10', 'bus-a', 'bus-b'], [$this->wiring, $wiringStatus, $wiringDue, 'wir-a', 'wir-b']] as [$d, $st, $due, $c1, $c2]) {
            $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $st, 'start_date' => '2026-10-02', 'due_date' => $due, 'status' => 'not_started', 'progress' => 0]);
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $c1, 'is_completed' => true, 'sequence' => 1]);
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => $c2, 'is_completed' => false, 'sequence' => 2]);
        }

        return $cab;
    }

    public function test_board_is_mapped_into_the_avatar_planning_shape(): void
    {
        $this->seedCabinet();
        ActivityLog::record($this->project->id, null, $this->project, 'note', 'hello');
        $pm = $this->user($this->busbar, 'project_manager');

        $root = app(PlanningBoard::class)->build($pm);
        $board = $root['boards']['pmo'];

        $this->assertSame('pmo', $root['activeBoard']);
        $this->assertSame(['s_todo', 's_doing', 's_review', 's_done', 's_fail'], array_column($board['statuses'], 'id'));
        $this->assertSame(['d_'.$this->busbar->id, 'd_'.$this->wiring->id], array_column($board['departments'], 'id'));

        $this->assertCount(1, $board['tasks']);
        $t = $board['tasks'][0];
        $this->assertSame('P-1', $t['so']);
        $this->assertSame('MDB', $t['title']);
        $this->assertSame('2026-10-31', $t['date']);
        $this->assertSame('2026-10-01', $t['startDate']);
        $this->assertSame('urgent', $t['priority']);
        $this->assertSame('pmo_in_progress', $t['statusId'], "with no project status picked the page shows PMO's own project status");
        $this->assertSame(['hello'], array_column($t['comments'], 'text'), 'the Activity box holds only the discussion');
        $this->assertSame(route('projects.activity-tree', $this->project), $t['logUrl'], 'a PMO role gets the change-log tree, separate from the Activity box');

        $this->assertCount(1, $t['subtasks']);
        $cab = $t['subtasks'][0];
        $this->assertSame('MO-1', $cab['mo']);
        $this->assertSame('Cab 1', $cab['text']);
        $this->assertSame('2026-10-20', $cab['date']);
        $this->assertEqualsCanonicalizing(['bus-a', 'bus-b', 'wir-a', 'wir-b'], array_column($cab['checklist'], 'text'));
        $this->assertSame(['bus-a' => true, 'bus-b' => false], collect($cab['checklist'])->where('deptId', 'd_'.$this->busbar->id)->pluck('done', 'text')->all());
        $this->assertSame('2026-10-10', $cab['deptDates']['d_'.$this->busbar->id]);

        $stages = collect($t['deptStages'])->keyBy('deptId');
        $this->assertSame('2026-10-20', $stages['d_'.$this->wiring->id]['due']);
        $this->assertFalse($stages['d_'.$this->wiring->id]['done']);
    }

    public function test_each_department_has_its_own_status_and_the_project_keeps_pmos(): void
    {
        $svc = app(PlanningBoard::class);
        $pm = $this->user($this->busbar, 'project_manager');
        $stage = fn () => collect($svc->build($pm)['boards']['pmo']['tasks'][0]['deptStages'])->keyBy('deptId');
        $wir = 'd_'.$this->wiring->id;
        $bus = 'd_'.$this->busbar->id;

        $this->seedCabinet('COMPLETED', 'COMPLETED');
        $this->assertSame(['s_done', 's_done'], [$stage()[$bus]['statusId'], $stage()[$wir]['statusId']]);
        $this->assertSame('pmo_in_progress', $svc->build($pm)['boards']['pmo']['tasks'][0]['statusId'], "all departments done does not change the project's status");

        CabinetSubtask::query()->where('department_id', $this->wiring->id)->update(['assignment_status' => 'ACCEPTED']);
        $this->assertSame(['s_done', 's_review'], [$stage()[$bus]['statusId'], $stage()[$wir]['statusId']]);

        CabinetSubtask::query()->where('department_id', $this->wiring->id)->update(['assignment_status' => 'IN_PROGRESS']);
        $this->assertSame('s_doing', $stage()[$wir]['statusId']);

        CabinetSubtask::query()->where('department_id', $this->wiring->id)->update(['assignment_status' => 'ASSIGNED']);
        $this->assertSame('s_todo', $stage()[$wir]['statusId']);

        CabinetSubtask::query()->where('department_id', $this->wiring->id)->update(['due_date' => '2026-10-01']);   // before "today"
        $this->assertSame(['s_done', 's_fail'], [$stage()[$bus]['statusId'], $stage()[$wir]['statusId']], 'late is per department');
        $this->assertFalse($stage()[$wir]['manualStatus']);

        $this->project->update(['status' => 'on_hold']);
        $this->assertSame('pmo_on_hold', $svc->build($pm)['boards']['pmo']['tasks'][0]['statusId']);
        $this->assertContains('pmo_on_hold', array_column($svc->build($pm)['boards']['pmo']['pmoStatusList'], 'id'));
    }

    public function test_a_department_user_only_receives_their_own_departments_work(): void
    {
        $this->seedCabinet();
        $user = $this->user($this->busbar);

        $t = app(PlanningBoard::class)->build($user)['boards']['pmo']['tasks'][0];

        $this->assertSame(['bus-a', 'bus-b'], array_column($t['subtasks'][0]['checklist'], 'text'), "no other department's checklist items");
        $this->assertSame(['d_'.$this->busbar->id], array_column($t['deptStages'], 'deptId'));
        $this->assertSame(['d_'.$this->busbar->id], array_keys((array) $t['subtasks'][0]['deptDates']));

        $profile = app(PlanningBoard::class)->profile($user);
        $this->assertSame('d_'.$this->busbar->id, $profile['dept_id']);
        $this->assertSame('checklist', $profile['perm'], 'department users: tick checklist items + comment');
        $this->assertSame('member', $profile['role'], 'never admin on this page');
        $this->assertSame('full', app(PlanningBoard::class)->profile($this->user($this->busbar, 'project_manager'))['perm']);

        $this->assertSame('', app(PlanningBoard::class)->profile($this->user($this->busbar, 'admin'))['dept_id'], 'PMO roles view every department');
    }

    public function test_a_department_user_gets_the_discussion_but_not_the_change_log(): void
    {
        $this->seedCabinet();
        ActivityLog::record($this->project->id, null, $this->project, 'note', 'hello');
        $t = app(PlanningBoard::class)->build($this->user($this->busbar))['boards']['pmo']['tasks'][0];

        $this->assertSame(['hello'], array_column($t['comments'], 'text'));
        $this->assertNull($t['logUrl']);
    }

    public function test_a_user_with_no_department_gets_an_empty_board(): void
    {
        $this->seedCabinet();

        $this->assertSame([], app(PlanningBoard::class)->build($this->user(null))['boards']['pmo']['tasks']);
    }

    public function test_page_serves_a_small_shell_with_embedded_data_and_requires_login(): void
    {
        $this->get(route('planning.spa'))->assertRedirect(route('login'));

        $this->seedCabinet();
        $res = $this->actingAs($this->user($this->busbar, 'admin'))->get(route('planning.spa'))->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('window.__PMO__ = {', $html);
        $this->assertStringNotContainsString('__PMO_CONFIG__', $html, 'the placeholder must have been replaced');
        $this->assertStringNotContainsString('__PLANNING_ASSETS__', $html, 'the asset base must have been replaced');
        $this->assertStringContainsString('P-1', $html);
        $this->assertStringContainsString('"perm":"full"', $html, 'an admin gets the full tier (the server still enforces the real rules)');
        $this->assertStringContainsString('planning/sync', $html, 'the page knows where to send its edits');

        // the 600 KB of CSS/JS are NOT in the page any more: it links the static, content-hashed files instead
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/planning-assets/app\.css\?v=[0-9a-f]{10}">#', $html);
        $this->assertMatchesRegularExpression('#<script src="[^"]*/planning-assets/app\.js\?v=[0-9a-f]{10}"></script>#', $html);
        $this->assertStringNotContainsString('function pmoSync', $html);
        $this->assertStringNotContainsString('supabase.js', $html);
        $this->assertLessThan(60 * 1024, strlen($html), 'shell + one small board stays small');

        $js = file_get_contents(public_path('planning-assets/app.js'));
        $this->assertStringContainsString('function pmoSync', $js);
        $this->assertStringContainsString('PMO_MODE', $js);
        $this->assertStringNotContainsString('supabase.min.js', $js);
    }

    public function test_the_generated_assets_match_the_source_page(): void
    {
        $expected = \App\Services\PlanningAssets::split(file_get_contents(resource_path(\App\Services\PlanningAssets::SOURCE)));

        foreach (\App\Services\PlanningAssets::paths() as $key => $path) {
            $this->assertFileExists($path);
            $this->assertSame(md5($expected[$key]), md5(file_get_contents($path)), "$path is stale - run: php artisan planning:build");
        }
    }

    public function test_the_page_hash_in_the_shell_changes_when_the_script_changes(): void
    {
        $a = \App\Services\PlanningAssets::split("<html><head><style>a{}</style></head><body><script>window.__PMO__ = /*__PMO_CONFIG__*/null;</script>\n<script>\n/* ==== */ var x = 1;\n</script></body></html>");
        $b = \App\Services\PlanningAssets::split("<html><head><style>a{}</style></head><body><script>window.__PMO__ = /*__PMO_CONFIG__*/null;</script>\n<script>\n/* ==== */ var x = 2;\n</script></body></html>");

        $this->assertNotSame($a['shell'], $b['shell'], 'a new script must get a new ?v= so browsers fetch it');
        $this->assertStringContainsString('window.__PMO__ = /*__PMO_CONFIG__*/null;', $a['shell'], 'the config placeholder stays in the shell');
        $this->assertStringNotContainsString('var x', $a['shell']);
        $this->assertSame("/* ==== */ var x = 1;\n", $a['js']);
    }

    public function test_user_written_text_cannot_break_out_of_the_embedded_script(): void
    {
        $this->project->update(['project_name' => '</script><script>alert(1)</script>']);
        $this->seedCabinet();
        ActivityLog::record($this->project->id, null, $this->project, 'note', '"></script><img src=x onerror=alert(2)>');

        $html = $this->actingAs($this->user($this->busbar, 'admin'))->get(route('planning.spa'))->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('\u003C/script\u003E', $html, 'the closing tag is embedded only in its escaped form');
    }
}
