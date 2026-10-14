<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** What the Planning page can now do like the original index page: hand-picked statuses, tags, building a project up, boards, "ของฉัน", files. */
class PlanningFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'F'.++$n, 'email' => 'feat'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $status = 'ACCEPTED'): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'due_date' => '2026-10-20', 'status' => 'not_started', 'progress' => 0]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => 'c', 'is_completed' => false, 'sequence' => 1]);

        return $s;
    }

    private function apply(User $u, array $op): array
    {
        return app(PlanningSync::class)->apply($u, [$op])[0];
    }

    private function task(User $u, ?int $board = null): ?array
    {
        return collect(app(PlanningBoard::class)->build($u, null, $board)['boards']['pmo']['tasks'])->firstWhere('id', 'p'.$this->project->id);
    }

    private function pid(): string
    {
        return 'p'.$this->project->id;
    }

    public function test_a_status_can_be_picked_by_hand_and_given_back_to_automatic(): void
    {
        $this->subtask($this->busbar, 'ACCEPTED');
        $member = $this->user($this->busbar);
        $custom = BoardStatus::create(['name' => 'รอลูกค้า', 'color' => '#123456', 'is_done' => false, 'sort' => 9]);

        $this->assertSame(['pmo_in_progress', false], [$this->task($member)['statusId'], $this->task($member)['manualStatus']], "none picked: PMO's own status");

        $this->assertFalse($this->apply($member, ['type' => 'project', 'taskId' => $this->pid(), 'fields' => ['statusId' => 's_done']])['ok'], 'Admin / PM only');
        $pm = $this->user(null, 'project_manager');
        $this->assertTrue($this->apply($pm, ['type' => 'project', 'taskId' => $this->pid(), 'fields' => ['statusId' => 'x'.$custom->id]])['ok']);
        $this->assertSame(['x'.$custom->id, true], [$this->task($member)['statusId'], $this->task($member)['manualStatus']]);
        $this->assertTrue($this->apply($pm, ['type' => 'project', 'taskId' => $this->pid(), 'fields' => ['statusId' => 's_done']])['ok'], 'a built-in one can be picked too');
        $this->assertSame('s_done', $this->task($member)['statusId']);
        $this->assertFalse($this->apply($pm, ['type' => 'project', 'taskId' => $this->pid(), 'fields' => ['statusId' => 'x999']])['ok']);

        $this->assertTrue($this->apply($pm, ['type' => 'project', 'taskId' => $this->pid(), 'fields' => ['statusId' => '']])['ok']);
        $this->assertSame(['pmo_in_progress', false], [$this->task($member)['statusId'], $this->task($member)['manualStatus']], "back to PMO's own status");
        $this->assertSame('s_review', $this->task($member)['deptStages'][0]['statusId'], "the department's status is its own, untouched");
        $this->assertSame('in_progress', $this->project->fresh()->status, "PMO's own project status is not touched");
    }

    public function test_tags_are_set_and_created_by_pmo_roles(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');

        $this->assertFalse($this->apply($member, ['type' => 'label_create', 'name' => 'ด่วน'])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'label_create', 'name' => 'ด่วน', 'color' => '#ff0000'])['ok']);
        $label = BoardLabel::first();

        $this->assertFalse($this->apply($member, ['type' => 'labels_set', 'taskId' => $this->pid(), 'labels' => ['l'.$label->id]])['ok'], 'tags: Admin / PM only');
        $this->assertTrue($this->apply($pm, ['type' => 'labels_set', 'taskId' => $this->pid(), 'labels' => ['l'.$label->id, 'l999', 'junk']])['ok']);
        $this->assertSame(['l'.$label->id], $this->task($member)['labels'], 'unknown tags are dropped');
        $board = app(PlanningBoard::class)->build($member)['boards']['pmo'];
        $this->assertSame([['id' => 'l'.$label->id, 'name' => 'ด่วน', 'color' => '#ff0000']], $board['labels']);

        $this->assertTrue($this->apply($pm, ['type' => 'label_delete', 'labelId' => 'l'.$label->id])['ok']);
        $this->assertSame([], $this->task($member)['labels']);
    }

    public function test_the_people_in_charge_are_the_departments_with_work(): void
    {
        $this->subtask($this->busbar);
        $this->subtask($this->wiring);

        $this->assertSame(['d_'.$this->busbar->id, 'd_'.$this->wiring->id], $this->task($this->user(null, 'admin'))['assignees']);
    }

    public function test_admin_or_pm_can_set_a_departments_status_over_the_automatic_one(): void
    {
        $this->subtask($this->busbar, 'ACCEPTED');
        $this->subtask($this->wiring, 'IN_PROGRESS');
        $member = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $bus = 'd_'.$this->busbar->id;
        $stages = fn (User $u) => collect($this->task($u)['deptStages'])->keyBy('deptId');
        $op = fn (string $statusId, string $dept) => ['type' => 'stage_status', 'taskId' => $this->pid(), 'deptId' => $dept, 'statusId' => $statusId];

        $this->assertFalse($this->apply($member, $op('s_done', $bus))['ok'], 'Admin / PM only');
        $this->assertTrue($this->apply($pm, $op('s_done', $bus))['ok']);
        $this->assertSame(['s_done', true], [$stages($member)[$bus]['statusId'], $stages($member)[$bus]['manualStatus']]);
        $this->assertSame('s_doing', $stages($pm)['d_'.$this->wiring->id]['statusId'], 'only that department');
        $this->assertSame('pmo_in_progress', $this->task($pm)['statusId'], "the project's status is separate");
        $this->assertSame('ACCEPTED', CabinetSubtask::where('department_id', $this->busbar->id)->value('assignment_status'), 'the work itself is not touched');
        $this->assertFalse($this->apply($pm, $op('x999', $bus))['ok']);

        $this->assertTrue($this->apply($pm, $op('', $bus))['ok']);
        $this->assertSame(['s_review', false], [$stages($pm)[$bus]['statusId'], $stages($pm)[$bus]['manualStatus']], 'automatic again');
    }

    public function test_pmo_roles_see_projects_and_cabinets_with_no_work_yet_members_do_not(): void
    {
        $member = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');

        $t = $this->task($pm);
        $this->assertNotNull($t, 'an open project with nothing dispatched still shows for PMO');
        $this->assertSame(['c'.$this->cabinet->id], array_column($t['subtasks'], 'id'));
        $this->assertSame('pmo_in_progress', $t['statusId']);
        $this->assertNull($this->task($member));

        $this->project->update(['status' => 'completed']);
        $this->assertNull($this->task($pm), 'a closed project with no work is not brought back');
    }

    public function test_cabinets_are_added_renamed_and_deleted_by_pmo_roles(): void
    {
        $pm = $this->user(null, 'project_manager');
        $member = $this->user($this->busbar);
        $this->subtask($this->busbar);

        $this->assertFalse($this->apply($member, ['type' => 'cabinet_add', 'taskId' => $this->pid(), 'mo' => 'MO-2', 'text' => 'x'])['ok']);
        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => $this->pid(), 'mo' => 'MO-1', 'text' => 'dup'])['ok'], 'MO numbers are unique');
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_add', 'taskId' => $this->pid(), 'mo' => 'MO-2', 'text' => 'MDB-2'])['ok']);
        $new = Cabinet::where('mo_no', 'MO-2')->first();
        $this->assertSame($this->project->id, $new->project_id);
        $this->assertContains('c'.$new->id, array_column($this->task($pm)['subtasks'], 'id'));

        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_rename', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$new->id, 'mo' => 'MO-3', 'text' => 'MDB-3'])['ok']);
        $this->assertSame(['MO-3', 'MDB-3'], [$new->fresh()->mo_no, $new->fresh()->cabinet_name]);
        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_rename', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$new->id, 'mo' => 'MO-1', 'text' => 'x'])['ok']);

        $other = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'O', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->assertFalse($this->apply($pm, ['type' => 'cabinet_delete', 'taskId' => 'p'.$other->id, 'cabinetId' => 'c'.$new->id])['ok'], 'a cabinet of another project');
        $this->assertTrue($this->apply($pm, ['type' => 'cabinet_delete', 'taskId' => $this->pid(), 'cabinetId' => 'c'.$new->id])['ok']);
        $this->assertSoftDeleted($new);
    }

    public function test_a_department_is_added_to_a_project_and_taken_off_while_it_has_not_accepted(): void
    {
        $pm = $this->user(null, 'project_manager');
        $worker = $this->user($this->wiring);

        $this->assertTrue($this->apply($pm, ['type' => 'stage_add', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->wiring->id])['ok']);
        $s = CabinetSubtask::where('department_id', $this->wiring->id)->first();
        $this->assertSame(['งาน Wiring', 'ASSIGNED'], [$s->name, $s->assignment_status]);
        $this->assertSame(1, $worker->fresh()->notifications()->count(), 'the department is told, like any dispatch');
        $this->assertSame(['d_'.$this->wiring->id], array_column($this->task($pm)['deptStages'], 'deptId'));
        $this->assertFalse($this->apply($pm, ['type' => 'stage_add', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->wiring->id])['ok'], 'already there');
        $this->assertFalse($this->apply($worker, ['type' => 'stage_add', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->busbar->id])['ok'], 'PMO roles only');

        $s->update(['assignment_status' => 'ACCEPTED']);
        $this->assertFalse($this->apply($pm, ['type' => 'stage_remove', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->wiring->id])['ok'], 'accepted work stays');
        $s->update(['assignment_status' => 'ASSIGNED']);
        $this->assertTrue($this->apply($pm, ['type' => 'stage_remove', 'taskId' => $this->pid(), 'deptId' => 'd_'.$this->wiring->id])['ok']);
        $this->assertNull($s->fresh()->department_id);
        $this->assertSame([], $this->task($pm)['deptStages']);
    }

    public function test_boards_split_the_projects(): void
    {
        $this->subtask($this->busbar);
        $service = Board::create(['name' => 'Service']);
        $pm = $this->user(null, 'project_manager');

        $this->assertNotNull($this->task($pm));
        $this->assertFalse($this->apply($this->user($this->busbar), ['type' => 'board_set', 'taskId' => $this->pid(), 'boardId' => 'b'.$service->id])['ok']);
        $this->assertTrue($this->apply($pm, ['type' => 'board_set', 'taskId' => $this->pid(), 'boardId' => 'b'.$service->id])['ok']);

        $this->assertNull($this->task($pm), 'gone from the main board');
        $this->assertSame($service->id, $this->task($pm, $service->id)['boardNo']);

        $config = $this->actingAs($pm)->get(route('planning.board', ['board' => $service->id]))->assertOk()->viewData('config');
        $this->assertSame($service->id, $config['boardNo']);
        $this->assertSame(['main', 'b'.$service->id], array_column($config['boards'], 'id'));
        $this->assertStringContainsString('board='.$service->id, $config['urls']['sync']);
        $this->assertSame(['p'.$this->project->id], array_column($config['root']['boards']['pmo']['tasks'], 'id'));

        $service->delete();
        $this->assertNotNull($this->task($pm), 'deleting a board sends its projects back to the main one');
    }

    public function test_mine_lists_my_departments_open_work_my_projects_and_tags(): void
    {
        $this->subtask($this->busbar, 'ACCEPTED');
        $other = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'O', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $third = Project::forceCreate(['project_no' => 'P-3', 'project_name' => 'T', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $member = $this->user($this->busbar);
        $pm = $this->user(null, 'project_manager');
        $other->update(['project_manager_id' => $pm->id]);
        app(NotificationService::class)->mentioned($third, $this->user($this->wiring), $member, 'hi');

        $svc = app(PlanningBoard::class);
        $this->assertEqualsCanonicalizing([$this->project->id, $third->id], $svc->mineProjectIds($member));
        $this->assertSame([$other->id], $svc->mineProjectIds($pm));
    }

    public function test_a_comment_can_carry_files(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);
        $uploads = public_path('uploads/comment-attachments');
        $before = is_dir($uploads) ? scandir($uploads) : [];

        try {
            $res = $this->actingAs($member)->post(route('planning.comment'), [
                'taskId' => $this->pid(), 'text' => 'ดูรูป', 'files' => [UploadedFile::fake()->image('photo.jpg'), UploadedFile::fake()->create('spec.pdf', 10, 'application/pdf')],
            ], ['Accept' => 'application/json'])->assertOk();

            $this->assertTrue($res->json('results.0.ok'));
            $comment = collect($res->json('tasks.0.comments'))->firstWhere('text', 'ดูรูป');
            $this->assertSame(['photo.jpg', 'spec.pdf'], array_column($comment['files'], 'name'));
            $this->assertSame([true, false], array_column($comment['files'], 'image'));

            $this->post(route('planning.comment'), ['taskId' => $this->pid(), 'files' => [UploadedFile::fake()->create('x.exe', 5)]], ['Accept' => 'application/json'])->assertStatus(422);
            $res = $this->post(route('planning.comment'), ['taskId' => 'p'.(Project::forceCreate(['project_no' => 'P-9', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal'])->id), 'files' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json']);
            $this->assertFalse($res->json('results.0.ok'), "not someone else's project");
            $this->assertSame(2, \App\Models\CommentAttachment::count(), 'no file kept for a refused comment');
        } finally {
            foreach (array_diff(is_dir($uploads) ? scandir($uploads) : [], $before) as $f) {
                @unlink($uploads.'/'.$f);   // the uploader writes to public/: leave the folder as it was
            }
        }
    }

    public function test_dragging_bars_is_for_pmo_roles_only(): void
    {
        $month = file_get_contents(resource_path('views/planning/partials/_month.blade.php'));
        $script = file_get_contents(resource_path('js/planning-board.js'));

        $this->assertStringContainsString(':draggable="isPmo && !dept && !b.c"', $month);
        $this->assertStringContainsString('dragStart(e, t) { if (!this.isPmo)', $script);
        $this->assertStringContainsString('if (!t || !this.isPmo || this.dept) return;', $script);
    }
}
