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
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > สิทธิ์การใช้งาน: which projects a department sees, which pages it opens, which parts of a project, and the PM-level powers. */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $ours;      // Busbar has work here

    private Project $theirs;    // only Wiring has

    private User $admin;

    private User $member;       // Busbar

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->member = $this->user('member', $this->busbar);
        $this->ours = $this->project('P-OURS', $this->busbar);
        $this->theirs = $this->project('P-THEIRS', $this->wiring);
    }

    private function user(string $role, ?Department $d = null): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'perm-'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function project(string $no, Department $dept): Project
    {
        $p = Project::forceCreate(['project_no' => $no, 'project_name' => 'MDB '.$no, 'customer_name' => 'C', 'customer_phone' => '081-111-2222', 'status' => 'in_progress',
            'priority' => 'normal', 'contract_value' => 1250000, 'margin' => 225000]);
        $cab = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => 'MO-'.$no, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'S', 'department_id' => $dept->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);

        return $p;
    }

    /** one column of the table ($d null = people without a department); the rest stays on the defaults */
    private function allow(?Department $d, array $perms): void
    {
        AppSetting::put('department_permissions', [$d ? (string) $d->id : Access::NO_DEPARTMENT => $perms]);
    }

    // ---- which projects ----

    public function test_a_member_sees_only_the_projects_their_department_works_on_everywhere(): void
    {
        $this->actingAs($this->member)->get(route('projects.show', $this->ours))->assertOk();
        $this->actingAs($this->member)->get(route('projects.show', $this->theirs))->assertForbidden();
        $this->actingAs($this->member)->get(route('cabinets.show', $this->theirs->cabinets()->first()))->assertForbidden();

        $list = $this->actingAs($this->member)->get(route('projects.index'))->assertOk();
        $list->assertSee('P-OURS')->assertDontSee('P-THEIRS');
        $this->actingAs($this->member)->get(route('cabinets.index'))->assertOk()->assertSee('MO-P-OURS')->assertDontSee('MO-P-THEIRS');
        $this->assertSame(1, $this->actingAs($this->member)->get(route('dashboard'))->viewData('activeProjectsCount'));

        $this->theirs->assignees()->attach($this->member->id);   // made a person in charge -> they see it
        $this->actingAs($this->member)->get(route('projects.show', $this->theirs))->assertOk();
    }

    public function test_a_department_given_every_project_sees_them_but_does_not_edit(): void
    {
        $salesDept = Department::create(['name' => 'ฝ่ายขาย', 'code' => 'SAL', 'is_active' => true]);
        $sales = $this->user('member', $salesDept);
        $this->actingAs($sales)->get(route('projects.show', $this->theirs))->assertForbidden();

        $salesDept->update(['sees_all' => true]);   // "เห็นทุกโครงการ" = the department's "เห็นงานทุกแผนก"
        $sales->refresh();
        $this->actingAs($sales)->get(route('projects.index'))->assertSee('P-OURS')->assertSee('P-THEIRS');
        $this->actingAs($sales)->get(route('projects.show', $this->theirs))->assertOk();
        $this->actingAs($sales)->get(route('projects.edit', $this->theirs))->assertForbidden();

        $pm = $this->user('project_manager', $this->busbar);   // a PM may everything, whatever their department
        $this->actingAs($pm)->get(route('projects.edit', $this->theirs))->assertOk();
        $this->assertTrue($pm->allows('data.finance'));
    }

    public function test_people_without_a_department_have_their_own_column(): void
    {
        $nobody = $this->user('member');
        $this->assertFalse($nobody->allows('page.export'));
        $this->allow(null, ['page.export' => true]);
        $this->assertTrue($nobody->allows('page.export'));
        $this->assertFalse($this->member->allows('page.export'), 'Busbar keeps its own');
    }

    // ---- pages ----

    public function test_pages_follow_the_table(): void
    {
        $this->actingAs($this->member)->get(route('projects.export'))->assertForbidden();   // Export: not a member's by default
        $this->actingAs($this->member)->get(route('cabinet-templates.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('assignments.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('projects.index'))->assertDontSee(route('projects.export'));

        $this->allow($this->busbar, ['page.export' => true, 'page.cabinets' => false, 'page.dashboard' => false]);
        $csv = $this->actingAs($this->member)->get(route('projects.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('P-OURS', $csv);
        $this->assertStringNotContainsString('P-THEIRS', $csv, 'the CSV has the same projects as the list');
        $this->actingAs($this->member)->get(route('cabinets.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('projects.index'))->assertDontSee('aria-label="ตู้ไฟฟ้า"', false)->assertDontSee('aria-label="แดชบอร์ด"', false);
        $this->actingAs($this->member)->get(route('dashboard'))->assertRedirect(route('planning.board'));
    }

    // ---- parts of a project ----

    public function test_money_and_the_phone_are_left_out_for_whom_may_not_see_them(): void
    {
        $page = $this->actingAs($this->member)->get(route('projects.show', $this->ours))->assertOk();
        $page->assertDontSee('1,250,000.00')->assertDontSee('Margin')->assertDontSee('081-111-2222');
        $this->actingAs($this->admin)->get(route('projects.show', $this->ours))->assertSee('1,250,000.00')->assertSee('081-111-2222');

        $info = collect(app(PlanningBoard::class)->tasks($this->member))->firstWhere('id', 'p'.$this->ours->id)['info'];
        $this->assertSame([null, null, ''], [$info['contractValue'], $info['margin'], $info['customerPhone']], 'not in Planning\'s JSON either');

        // the change log keeps the values from them too
        $this->actingAs($this->admin);
        $this->ours->update(['contract_value' => 2000000, 'customer_phone' => '089-999-0000']);
        $tree = json_encode($this->actingAs($this->member)->getJson(route('projects.activity-tree', $this->ours))->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('2000000', $tree);
        $this->assertStringNotContainsString('089-999-0000', $tree);
        $this->assertStringContainsString('•••', $tree);
        $this->assertStringContainsString('2000000', json_encode($this->actingAs($this->admin)->getJson(route('projects.activity-tree', $this->ours))->json()));
    }

    public function test_who_may_not_see_the_money_does_not_change_it(): void
    {
        $pm = $this->user('member', $this->busbar);   // a department given the PM-level power, not the money / phone
        $this->allow($this->busbar, ['edit.manage' => true, 'data.finance' => false, 'data.contact' => false]);
        $sync = app(PlanningSync::class);

        $this->assertFalse($sync->apply($pm, [['type' => 'project', 'taskId' => 'p'.$this->ours->id, 'fields' => ['margin' => '1']]])[0]['ok']);
        $this->assertFalse($sync->apply($pm, [['type' => 'project', 'taskId' => 'p'.$this->ours->id, 'fields' => ['customerPhone' => '1']]])[0]['ok']);
        $this->assertTrue($sync->apply($pm, [['type' => 'project', 'taskId' => 'p'.$this->ours->id, 'fields' => ['poNo' => 'PO-9']]])[0]['ok'], 'the rest still edits');

        $this->actingAs($pm)->get(route('projects.edit', $this->ours))->assertOk()->assertDontSee('name="contract_value"', false)->assertDontSee('name="customer_phone"', false);
        $this->actingAs($pm)->put(route('projects.update', $this->ours), ['project_no' => 'P-OURS', 'project_name' => 'X', 'customer_name' => 'C', 'priority' => 'normal', 'status' => 'in_progress',
            'contract_value' => '1', 'margin' => '1', 'customer_phone' => 'x'])->assertSessionHasNoErrors();
        $p = $this->ours->fresh();
        $this->assertSame(['X', '1250000.00', '225000.00', '081-111-2222'], [$p->project_name, $p->contract_value, $p->margin, $p->customer_phone], 'the old values stay');
    }

    public function test_comments_can_be_taken_away(): void
    {
        $this->allow($this->busbar, ['data.comments' => false]);

        $page = $this->actingAs($this->member)->get(route('projects.show', $this->ours))->assertOk();
        $page->assertDontSee("tab = 'comment'", false)->assertDontSee("tab = 'activity'", false);
        $this->actingAs($this->member)->getJson(route('projects.activity-tree', $this->ours))->assertForbidden();
        $this->actingAs($this->member)->post(route('projects.notes.store', $this->ours), ['note' => 'hi'])->assertForbidden();
        $this->actingAs($this->member)->postJson(route('planning.comment'), ['taskId' => 'p'.$this->ours->id, 'text' => 'hi'])->assertForbidden();
        $this->assertSame(0, ActivityLog::where('action', 'note')->count());

        $task = collect(app(PlanningBoard::class)->tasks($this->member))->firstWhere('id', 'p'.$this->ours->id);
        $this->assertSame([], $task['comments']);
        $config = $this->actingAs($this->member)->get(route('planning.board'))->viewData('config');
        $this->assertSame(['finance' => false, 'contact' => false, 'comments' => false, 'export' => false, 'projects' => true], $config['perms']);
    }

    public function test_other_departments_extra_fields_follow_the_table(): void
    {
        $field = \App\Models\DepartmentField::create(['department_id' => $this->wiring->id, 'name' => 'สายไฟ', 'type' => 'text', 'sort' => 1]);
        \App\Models\ProjectFieldValue::create(['project_id' => $this->theirs->id, 'department_field_id' => $field->id, 'value' => 'THW 4 sq.mm']);
        $this->busbar->update(['sees_all' => true]);   // to see Wiring's part of the project at all
        $this->allow($this->busbar, ['data.other_fields' => true]);
        $sales = $this->user('member', $this->busbar);
        $stage = fn (User $u) => collect(collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.$this->theirs->id)['deptStages'])->firstWhere('deptId', PlanningBoard::deptKey($this->wiring->id));

        $this->assertSame(['THW 4 sq.mm'], array_values((array) $stage($sales)['fields']));
        $this->allow($this->busbar, ['data.other_fields' => false]);
        $this->assertSame([], (array) $stage($sales)['fields']);
    }

    // ---- PM-level powers ----

    public function test_pm_level_powers_can_be_given_to_another_role(): void
    {
        $sales = $this->user('sales', $this->wiring);
        $this->actingAs($sales)->get(route('projects.create'))->assertForbidden();
        $this->assertFalse($sales->canDispatchWork());

        $this->allow($this->wiring, ['edit.manage' => true]);
        $this->actingAs($sales)->get(route('projects.create'))->assertOk();
        $this->assertTrue($sales->canDispatchWork());

        $production = $this->user('production', $this->busbar);
        $this->actingAs($production)->get(route('cabinet-templates.index'))->assertForbidden();
        $this->allow($this->busbar, ['page.templates' => true]);
        $this->actingAs($production)->get(route('cabinet-templates.index'))->assertOk();
        $this->actingAs($production)->get(route('cabinet-templates.create'))->assertForbidden();
        $this->allow($this->busbar, ['edit.templates' => true, 'page.templates' => false]);
        $this->actingAs($production)->get(route('cabinet-templates.create'))->assertOk();
        $this->actingAs($production)->get(route('cabinet-templates.index'))->assertOk();   // editing the templates comes with opening them
    }

    // ---- the settings page ----

    public function test_only_admin_sets_the_table_and_admin_and_pm_keep_everything(): void
    {
        $this->actingAs($this->member)->get(route('settings.permissions.edit'))->assertForbidden();
        $page = $this->actingAs($this->admin)->get(route('settings.permissions.edit'))->assertOk()->assertSee('สิทธิ์การใช้งานตามแผนก');
        $page->assertSee('Busbar')->assertSee('Wiring')->assertSee('ไม่มีแผนก');

        // Busbar: only "Planning"; Wiring: sees every project
        $this->actingAs($this->admin)->put(route('settings.permissions.update'), ['perm' => [
            (string) $this->busbar->id => ['page.planning' => '1'],
            (string) $this->wiring->id => ['page.planning' => '1', 'scope.all_projects' => '1'],
        ]])->assertSessionHas('success');
        $this->assertTrue($this->member->allows('page.planning'));
        $this->assertFalse($this->member->allows('page.projects'), 'an unticked box is off');
        $this->assertTrue($this->wiring->fresh()->sees_all, 'the same switch as Settings > แผนก');
        $this->assertFalse($this->busbar->fresh()->sees_all);
        $this->assertTrue($this->user('project_manager', $this->busbar)->allows('page.projects'), 'PM always');
        $this->assertTrue($this->admin->allows('edit.manage'), 'Admin always');

        $this->actingAs($this->admin)->delete(route('settings.permissions.reset'))->assertSessionHas('success');
        $this->assertTrue($this->member->fresh()->allows('page.projects'), 'back to the defaults');
        $this->assertFalse($this->wiring->fresh()->sees_all);
        $this->assertSame([$this->busbar->id, $this->wiring->id, Access::NO_DEPARTMENT], array_keys(Access::table()), 'a column per active department (by name) + no department');
    }
}
