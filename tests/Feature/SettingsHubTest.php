<?php

namespace Tests\Feature;

use App\Exceptions\AssignmentException;
use App\Models\AppSetting;
use App\Models\AutomationRule;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Settings hub: personal notification choices, the admin's work rules, and the per-department look / visibility. */
class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'S'.++$n, 'email' => 'set'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $status, int $checks = 2): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'start_date' => '2026-10-02', 'due_date' => '2026-10-20', 'status' => 'not_started', 'progress' => 0]);
        for ($i = 1; $i <= $checks; $i++) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => "c$i", 'is_completed' => false, 'sequence' => $i]);
        }

        return $s;
    }

    private function unread(User $u): int
    {
        return $u->fresh()->notifications()->count();
    }

    // ---- the hub ----

    public function test_the_hub_sends_each_person_to_the_tab_they_may_open(): void
    {
        $this->actingAs($this->user($this->busbar))->get(route('settings.index'))->assertRedirect(route('settings.notifications.edit'));
        $this->actingAs($this->user(null, 'admin'))->get(route('settings.index'))->assertRedirect(route('settings.users.index'));
    }

    public function test_only_admins_see_and_open_the_admin_tabs(): void
    {
        $member = $this->user($this->busbar);
        $this->actingAs($member)->get(route('settings.notifications.edit'))->assertOk()->assertDontSee('กฎการทำงาน')->assertDontSee('Automation');
        $this->actingAs($member)->get(route('settings.rules.edit'))->assertStatus(403);
        $this->actingAs($member)->put(route('settings.rules.update'), ['cross_mention' => 1])->assertStatus(403);
        $this->assertFalse(AppSetting::get('cross_mention'));

        $this->actingAs($this->user(null, 'admin'))->get(route('settings.rules.edit'))->assertOk()->assertSee('บล็อกการกดเสร็จ');
        foreach (['settings.users.index', 'settings.departments.index', 'settings.job-types.index', 'settings.automation.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('กฎการทำงาน', false);   // the shared tab bar is on every settings page
        }
    }

    // ---- personal notification choices ----

    public function test_notification_choices_are_saved_per_person(): void
    {
        $a = $this->user($this->busbar);
        $b = $this->user($this->busbar);

        $this->actingAs($a)->put(route('settings.notifications.update'), ['enabled' => 1, 'completed' => 1])->assertRedirect();

        $pref = NotificationPreference::for($a);
        $this->assertTrue($pref->enabled && $pref->completed);
        $this->assertFalse($pref->progress || $pref->reminders || $pref->only_my_department, 'unchecked = off');
        $this->assertTrue(NotificationPreference::for($b)->progress, 'someone who never opened the page keeps every default');
    }

    public function test_notifications_follow_each_persons_choices(): void
    {
        $s = $this->subtask($this->busbar, 'ASSIGNED');
        $worker = $this->user($this->busbar);
        $this->project->update(['project_manager_id' => ($pm = $this->user($this->wiring, 'project_manager'))->id]);
        $svc = app(SubtaskAssignmentService::class);

        $svc->accept($s->fresh(), $worker);
        $this->assertSame(1, $this->unread($pm), 'default: the PM hears that the department accepted');

        NotificationPreference::create(['user_id' => $pm->id, 'enabled' => true, 'progress' => false, 'completed' => true, 'reminders' => true, 'only_my_department' => false]);
        $svc->start($s->fresh(), $worker);
        $this->assertSame(1, $this->unread($pm), 'progress switched off: "started" is not delivered');

        $svc->complete($s->fresh(), $worker);
        $this->assertSame(1, $this->unread($pm), 'everything about one project is one notice');
        $this->assertStringContainsString('Busbar เสร็จ 1', $pm->fresh()->notifications[0]->data['body'], 'completed is still on');
        $this->assertStringNotContainsString('เริ่มงาน', $pm->fresh()->notifications[0]->data['body'], 'progress is off');

        $pm->notificationPreference()->update(['enabled' => false]);
        $s2 = $this->subtask($this->busbar, 'ASSIGNED');
        $svc->accept($s2->fresh(), $worker);
        $this->assertSame(1, $this->unread($pm), 'master switch off: nothing arrives');
        $this->assertStringNotContainsString('Busbar รับงาน 2', $pm->fresh()->notifications[0]->data['body']);
    }

    public function test_only_my_department_skips_other_departments_news_but_never_the_urgent_kind(): void
    {
        $other = $this->subtask($this->wiring, 'ASSIGNED');
        $this->project->update(['project_manager_id' => ($pm = $this->user($this->busbar))->id]);   // a PM who belongs to Busbar
        NotificationPreference::create(['user_id' => $pm->id, 'only_my_department' => true]);

        app(SubtaskAssignmentService::class)->accept($other->fresh(), $this->user($this->wiring));
        $this->assertSame(0, $this->unread($pm), "Wiring's progress is not Busbar's business");

        app(NotificationService::class)->mentioned($this->project, $this->user($this->wiring), $pm, 'look');
        $this->assertSame(1, $this->unread($pm), 'a tag is still delivered');
    }

    // ---- work rules ----

    public function test_admin_saves_the_rules(): void
    {
        $this->actingAs($this->user(null, 'admin'))->put(route('settings.rules.update'), [
            'block_done_needs_checklist' => 1, 'automation_locked_statuses' => ['on_hold'],
        ])->assertRedirect();

        $this->assertTrue(AppSetting::get('block_done_needs_checklist'));
        $this->assertFalse(AppSetting::get('cross_mention'));
        $this->assertSame(['on_hold'], AppSetting::get('automation_locked_statuses'));
        $this->actingAs($this->user(null, 'admin'))->put(route('settings.rules.update'), ['automation_locked_statuses' => ['bogus']])->assertSessionHasErrors();
    }

    public function test_a_sub_task_cannot_be_completed_with_open_checklist_items_when_the_rule_is_on(): void
    {
        $worker = $this->user($this->busbar);
        $s = $this->subtask($this->busbar, 'IN_PROGRESS');
        $svc = app(SubtaskAssignmentService::class);

        $svc->complete($s->fresh(), $worker);   // rule off: fine
        $this->assertSame('COMPLETED', $s->fresh()->assignment_status);

        AppSetting::put('block_done_needs_checklist', true);
        $s2 = $this->subtask($this->busbar, 'IN_PROGRESS');
        try {
            $svc->complete($s2->fresh(), $worker);
            $this->fail('should be refused');
        } catch (AssignmentException $e) {
            $this->assertStringContainsString('เช็คลิสต์', $e->getMessage());
        }
        $s2->checklists()->update(['is_completed' => true]);
        $svc->complete($s2->fresh(), $worker);
        $this->assertSame('COMPLETED', $s2->fresh()->assignment_status);
    }

    public function test_a_project_cannot_be_completed_with_unfinished_department_work_when_the_rule_is_on(): void
    {
        $s = $this->subtask($this->busbar, 'ACCEPTED');
        $this->project->update(['status' => 'on_hold']);   // rule off: any status change is fine
        $this->assertSame('on_hold', $this->project->fresh()->status);

        AppSetting::put('block_project_done_needs_work', true);
        try {
            $this->project->update(['status' => 'completed']);
            $this->fail('should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertSame('on_hold', $this->project->fresh()->status);

        $s->update(['assignment_status' => 'COMPLETED']);
        $this->project->update(['status' => 'completed']);
        $this->assertSame('completed', $this->project->fresh()->status);
    }

    public function test_automation_leaves_projects_in_locked_statuses_alone(): void
    {
        AutomationRule::create(['name' => 'r', 'trigger' => AutomationRule::TRIGGER_ALL_DONE, 'action' => AutomationRule::ACTION_COMPLETE, 'is_active' => true]);
        $worker = $this->user($this->busbar);
        $s = $this->subtask($this->busbar, 'IN_PROGRESS', 1);
        $s->checklists()->update(['is_completed' => true]);

        AppSetting::put('automation_locked_statuses', ['in_progress']);
        $this->assertFalse(app(\App\Services\AutomationService::class)->afterChecklistTicked($s->fresh(), $worker));
        $this->assertSame('IN_PROGRESS', $s->fresh()->assignment_status);

        AppSetting::put('automation_locked_statuses', []);
        $this->assertTrue(app(\App\Services\AutomationService::class)->afterChecklistTicked($s->fresh(), $worker));
        $this->assertSame('COMPLETED', $s->fresh()->assignment_status);
    }

    public function test_tagging_across_departments_follows_the_switch(): void
    {
        $this->subtask($this->busbar, 'ACCEPTED');
        $this->subtask($this->wiring, 'ACCEPTED');
        $sender = $this->user($this->busbar);
        $target = $this->user($this->wiring);   // sees the project: Wiring works on it
        $sync = app(PlanningSync::class);

        [$allowed] = $sync->mentionTargets($sender, $this->project, [$target->id]);
        $this->assertCount(0, $allowed, 'off: only the same department or PMO roles');

        AppSetting::put('cross_mention', true);
        [$allowed] = $sync->mentionTargets($sender, $this->project, [$target->id]);
        $this->assertCount(1, $allowed);

        $people = $this->actingAs($sender)->get(route('planning.board'))->viewData('config')['people'];
        $this->assertContains($target->id, array_column($people, 'id'), 'the @ picker offers them too');
    }

    // ---- department look + visibility ----

    public function test_a_department_form_saves_colour_icon_and_visibility(): void
    {
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->post(route('settings.departments.store'), ['name' => 'QC', 'code' => 'QC', 'color' => '#112233', 'icon' => '🔍', 'sees_all' => 1])->assertRedirect();
        $qc = Department::where('code', 'QC')->first();
        $this->assertSame(['#112233', '🔍', true], [$qc->color, $qc->icon, $qc->sees_all]);

        $this->actingAs($admin)->put(route('settings.departments.update', $qc), ['name' => 'QC', 'code' => 'QC', 'color' => 'red'])->assertSessionHasErrors('color');
        $this->actingAs($admin)->put(route('settings.departments.update', $qc), ['name' => 'QC', 'code' => 'QC', 'color' => '#abcdef', 'icon' => '', 'is_active' => 1])->assertRedirect();
        $this->assertSame(['#abcdef', false], [$qc->fresh()->color, $qc->fresh()->sees_all]);

        $dept = collect(app(PlanningBoard::class)->build($admin)['boards']['pmo']['departments'])->firstWhere('id', 'd_'.$qc->id);
        $this->assertSame('#abcdef', $dept['color'], 'the board uses the colour chosen in Settings');
    }

    public function test_a_department_that_sees_everything_reads_all_work_but_moves_no_dates(): void
    {
        $this->busbar->update(['sees_all' => true]);
        $mine = $this->subtask($this->busbar, 'ACCEPTED');
        $theirs = $this->subtask($this->wiring, 'ACCEPTED');
        $member = $this->user($this->busbar);

        $board = app(PlanningBoard::class)->build($member)['boards']['pmo'];
        $this->assertSame('', $board['tasks'] ? app(PlanningBoard::class)->profile($member)['dept_id'] : '', 'no department filter');
        $stages = array_column($board['tasks'][0]['deptStages'], 'deptId');
        $this->assertEqualsCanonicalizing(['d_'.$this->busbar->id, 'd_'.$this->wiring->id], $stages, "sees Wiring's stage too");

        $due = fn (Department $d) => ['type' => 'stage_due', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$d->id, 'due' => '2026-10-25'];
        $sync = app(PlanningSync::class);
        $this->assertFalse($sync->apply($member, [$due($this->wiring)])[0]['ok'], "cannot move another department's date");
        $this->assertSame('2026-10-20', $theirs->fresh()->due_date->format('Y-m-d'));
        $this->assertFalse($sync->apply($member, [$due($this->busbar)])[0]['ok'], 'dates are Admin / PM only');
        $this->assertSame('2026-10-20', $mine->fresh()->due_date->format('Y-m-d'));
    }

    // ---- extra fields per department ----

    private function adminFields(): array
    {
        $admin = $this->user(null, 'admin');
        $this->actingAs($admin)->post(route('settings.fields.store'), ['department_id' => $this->busbar->id, 'name' => 'รถขนส่ง', 'type' => 'select'])->assertRedirect();
        $field = \App\Models\DepartmentField::first();
        $this->post(route('settings.fields.options.store', $field), ['group_name' => 'รถบริษัท', 'labels' => "1กข-1234\n\n  2กก-5678  "])->assertRedirect();
        $this->post(route('settings.fields.store'), ['department_id' => $this->busbar->id, 'name' => 'แผนที่', 'type' => 'link'])->assertRedirect();

        return [$field, \App\Models\DepartmentField::where('type', 'link')->first()];
    }

    public function test_admin_builds_fields_and_members_cannot(): void
    {
        [$field] = $this->adminFields();

        $this->assertSame(['1กข-1234', '2กก-5678'], $field->options()->pluck('label')->all(), 'one option per non-blank line');
        $this->get(route('settings.fields.index', ['department' => $this->busbar->id]))->assertOk()->assertSee('รถขนส่ง')->assertSee('2กก-5678');

        $member = $this->user($this->busbar);
        $this->actingAs($member)->get(route('settings.fields.index'))->assertStatus(403);
        $this->actingAs($member)->post(route('settings.fields.store'), ['department_id' => $this->busbar->id, 'name' => 'x', 'type' => 'text'])->assertStatus(403);
        $this->actingAs($member)->delete(route('settings.fields.destroy', $field))->assertStatus(403);
        $this->assertSame(2, \App\Models\DepartmentField::count());
    }

    public function test_the_board_carries_the_fields_and_what_each_department_chose(): void
    {
        [$field, $link] = $this->adminFields();
        $this->subtask($this->busbar, 'ACCEPTED');
        $member = $this->user($this->busbar);
        $opt = $field->options()->first();

        $pid = 'p'.$this->project->id;
        $dept = 'd_'.$this->busbar->id;
        $sync = app(PlanningSync::class);
        $this->assertTrue($sync->apply($member, [['type' => 'stage_field', 'taskId' => $pid, 'deptId' => $dept, 'fieldId' => 'f'.$field->id, 'value' => 'o'.$opt->id]])[0]['ok']);
        $this->assertTrue($sync->apply($member, [['type' => 'stage_field', 'taskId' => $pid, 'deptId' => $dept, 'fieldId' => 'f'.$link->id, 'value' => 'https://maps.example/x']])[0]['ok']);

        $board = app(PlanningBoard::class)->build($member)['boards']['pmo'];
        $defs = collect($board['departments'])->firstWhere('id', $dept)['fields'];
        $this->assertSame(['รถขนส่ง', 'แผนที่'], array_column($defs, 'name'));
        $this->assertSame(['1กข-1234', '2กก-5678'], array_column($defs[0]['groups'][0]['options'], 'label'));
        $this->assertSame('รถบริษัท', $defs[0]['groups'][0]['name']);
        $this->assertSame('link', $defs[1]['type']);
        $fields = (array) $board['tasks'][0]['deptStages'][0]['fields'];
        $this->assertSame(['f'.$field->id => 'o'.$opt->id, 'f'.$link->id => 'https://maps.example/x'], $fields);
        $this->assertNotEmpty(\App\Models\ActivityLog::where('project_id', $this->project->id)->where('description', 'like', '%รถขนส่ง%')->get(), 'the change is logged');
    }

    public function test_a_field_value_is_checked_and_kept_inside_the_department(): void
    {
        [$field, $link] = $this->adminFields();
        $this->subtask($this->busbar, 'ACCEPTED');
        $this->subtask($this->wiring, 'ACCEPTED');
        $busbarMember = $this->user($this->busbar);
        $pm = $this->user($this->wiring, 'project_manager');
        $sync = app(PlanningSync::class);
        $op = fn (string $f, string $v, ?Department $d = null) => ['type' => 'stage_field', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.($d ?? $this->busbar)->id, 'fieldId' => $f, 'value' => $v];

        $this->assertFalse($sync->apply($busbarMember, [$op('f'.$field->id, 'o999')])[0]['ok'], 'an option that is not in the field');
        $this->assertFalse($sync->apply($busbarMember, [$op('f'.$link->id, 'javascript:alert(1)')])[0]['ok'], 'only http(s) links');
        $this->assertFalse($sync->apply($this->user($this->wiring), [$op('f'.$link->id, 'https://a.example')])[0]['ok'], 'another department cannot fill it in');
        $this->assertFalse($sync->apply($pm, [$op('f'.$link->id, 'https://a.example', $this->wiring)])[0]['ok'], "the field belongs to Busbar, not Wiring");
        $this->assertTrue($sync->apply($pm, [$op('f'.$link->id, 'https://a.example')])[0]['ok'], 'a PMO role may, for any department');

        $this->assertTrue($sync->apply($busbarMember, [$op('f'.$link->id, '')])[0]['ok'], 'blank clears');
        $this->assertSame(0, \App\Models\ProjectFieldValue::count());

        $this->project->update(['status' => 'completed']);
        $this->assertFalse($sync->apply($pm, [$op('f'.$link->id, 'https://b.example')])[0]['ok'], 'a closed project is read-only');
    }

    public function test_deleting_an_option_or_field_clears_the_values_that_used_it(): void
    {
        [$field, $link] = $this->adminFields();
        $this->subtask($this->busbar, 'ACCEPTED');
        $member = $this->user($this->busbar);
        $opt = $field->options()->first();
        app(PlanningSync::class)->apply($member, [['type' => 'stage_field', 'taskId' => 'p'.$this->project->id, 'deptId' => 'd_'.$this->busbar->id, 'fieldId' => 'f'.$field->id, 'value' => 'o'.$opt->id]]);
        $this->assertSame(1, \App\Models\ProjectFieldValue::count());

        $this->actingAs($this->user(null, 'admin'))->delete(route('settings.field-options.destroy', $opt))->assertRedirect();
        $this->assertSame(0, \App\Models\ProjectFieldValue::count());

        $this->delete(route('settings.fields.destroy', $field))->assertRedirect();
        $this->assertNull(\App\Models\DepartmentField::find($field->id));
    }

    // ---- statuses ----

    public function test_admin_manages_statuses_built_in_ones_stay(): void
    {
        $admin = $this->user(null, 'admin');
        $todo = \App\Models\BoardStatus::where('key', 'todo')->first();

        $this->actingAs($admin)->post(route('settings.statuses.store'), ['name' => 'รอลูกค้า', 'color' => '#123456'])->assertRedirect();
        $custom = \App\Models\BoardStatus::whereNull('key')->first();
        $this->put(route('settings.statuses.update'), ['statuses' => [
            $todo->id => ['name' => 'รออยู่', 'color' => '#111111', 'is_done' => 1],
            $custom->id => ['name' => 'รอลูกค้าอนุมัติ', 'color' => '#222222', 'is_done' => 1],
        ]])->assertRedirect();

        $this->assertSame(['รออยู่', '#111111', false], [$todo->fresh()->name, $todo->fresh()->color, $todo->fresh()->is_done], 'a built-in status keeps its meaning');
        $this->assertTrue($custom->fresh()->is_done);
        $this->delete(route('settings.statuses.destroy', $todo))->assertStatus(422);
        $this->patch(route('settings.statuses.move', $custom), ['dir' => 'up'])->assertRedirect();
        $this->assertSame(5, $custom->fresh()->sort, 'moved above the last built-in one');

        $ids = array_column(PlanningBoard::statuses(), 'id');
        $this->assertSame(['s_todo', 's_doing', 's_review', 's_done', 'x'.$custom->id, 's_fail'], $ids);
        $this->put(route('settings.statuses.update'), ['statuses' => [$todo->id => ['name' => 'x', 'color' => 'blue']]])->assertSessionHasErrors();
        $this->delete(route('settings.statuses.destroy', $custom))->assertRedirect();
        $this->actingAs($this->user($this->busbar))->get(route('settings.statuses.edit'))->assertStatus(403);
    }

    public function test_templates_live_under_settings_for_the_roles_that_may_open_them(): void
    {
        $this->actingAs($this->user($this->busbar))->get(route('cabinet-templates.index'))->assertForbidden();   // off for a department by default
        $this->actingAs($this->user($this->busbar))->get(route('settings.notifications.edit'))->assertDontSee('เทมเพลตตู้/เช็คลิสต์');
        \App\Models\AppSetting::put('department_permissions', [(string) $this->busbar->id => ['page.templates' => true]]);   // Settings > สิทธิ์การใช้งาน
        $member = $this->user($this->busbar);

        $html = $this->actingAs($member)->get(route('cabinet-templates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="ตั้งค่า"', $html, 'the settings tab bar is on the templates page');
        $this->assertStringContainsString('เทมเพลตตู้/เช็คลิสต์', $html);
        $this->assertStringNotContainsString('aria-label="เทมเพลต"', $html, 'no separate sidebar entry any more');
        $this->actingAs($member)->get(route('settings.notifications.edit'))->assertSee('เทมเพลตตู้/เช็คลิสต์')->assertDontSee('กฎการทำงาน');
    }

    public function test_a_department_that_sees_everything_can_export_every_department(): void
    {
        $this->busbar->update(['sees_all' => true]);
        \App\Models\AppSetting::put('department_permissions', [(string) $this->busbar->id => ['page.export' => true]]);   // Export CSV is off for a department by default
        $this->subtask($this->wiring, 'ACCEPTED');
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31', 'department_id' => 'all'];

        $csv = $this->actingAs($this->user($this->busbar))->get(route('my-department.export', $q))->assertOk()->streamedContent();
        $this->assertStringContainsString('Wiring', $csv, "the board's Export CSV works for them too");
        $this->actingAs($this->user($this->wiring))->get(route('my-department.export', $q))->assertForbidden();
    }

    /** Every page with the settings tab bar uses the same width, so switching tabs never moves the bar or the content edge. */
    public function test_every_settings_page_uses_the_full_width(): void
    {
        $pages = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            $html = $file->isFile() ? file_get_contents($file->getPathname()) : '';
            if (! str_contains($html, '<x-settings-nav />')) {
                continue;
            }
            $pages++;
            $this->assertMatchesRegularExpression('#<div class="max-w-none mx-auto[^"]*">[^\n]*\s*<x-settings-nav />#', $html, $file->getPathname());   // full width, every one
        }
        $this->assertGreaterThanOrEqual(18, $pages);
    }
}
