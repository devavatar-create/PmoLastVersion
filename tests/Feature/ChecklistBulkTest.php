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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "แก้ Checklist ทั้งโครงการ": the same change in the same Sub Task of every cabinet, previewed first, Admin / PM only. */
class ChecklistBulkTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<CabinetSubtask> the "QC" Sub Task of MO-1..MO-3 */
    private array $qc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        foreach (['MO-1', 'MO-2', 'MO-3'] as $i => $mo) {
            $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
            $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
            $this->qc[$i] = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'QC', 'department_id' => $dept->id, 'assignment_status' => $i === 2 ? 'COMPLETED' : 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
            CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'อื่น', 'department_id' => $dept->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->qc[$i]->id, 'name' => 'Hi-pot', 'is_completed' => $i === 0, 'sequence' => 1]);
        }
    }

    private function run_(User $u, array $body)
    {
        return $this->actingAs($u)->postJson(route('projects.checklist-bulk.run', $this->project), $body + ['subtask' => 'QC']);
    }

    private function names(int $i): array
    {
        return $this->qc[$i]->checklists()->orderBy('sequence')->pluck('name')->all();
    }

    public function test_options_and_admin_or_pm_only(): void
    {
        $member = User::forceCreate(['name' => 'M', 'email' => 'm', 'password' => 'x', 'role' => 'member', 'department_id' => Department::first()->id, 'is_active' => true]);
        $this->actingAs($member)->getJson(route('projects.checklist-bulk.options', $this->project))->assertForbidden();

        $pm = User::forceCreate(['name' => 'P', 'email' => 'p', 'password' => 'x', 'role' => 'project_manager', 'is_active' => true]);
        $qc = collect($this->actingAs($pm)->getJson(route('projects.checklist-bulk.options', $this->project))->assertOk()->json('subtasks'))->firstWhere('name', 'QC');
        $this->assertSame([3, ['Hi-pot']], [$qc['cabinets'], $qc['items']]);
        $this->actingAs($pm)->get(route('projects.show', [$this->project, 'tab' => 'cabinets']))->assertSee('แก้ Checklist ทั้งโครงการ');
    }

    public function test_add_everywhere_after_a_preview_skipping_finished_work_and_duplicates(): void
    {
        $pm = User::forceCreate(['name' => 'P', 'email' => 'p', 'password' => 'x', 'role' => 'project_manager', 'is_active' => true]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->qc[1]->id, 'name' => 'แรงบิด', 'is_completed' => false, 'sequence' => 2]);

        $p = $this->run_($pm, ['action' => 'add', 'names' => "แรงบิด\n\nฉนวน", 'preview' => true])->assertOk();
        $this->assertSame([3, 2], [$p->json('changes'), $p->json('cabinets')], 'MO-1 gets 2, MO-2 gets 1 ("แรงบิด" already there), MO-3 is finished');
        $this->assertSame(['Hi-pot'], $this->names(0), 'a preview changes nothing');

        $this->run_($pm, ['action' => 'add', 'names' => "แรงบิด\nฉนวน"])->assertOk();
        $this->assertSame(['Hi-pot', 'แรงบิด', 'ฉนวน'], $this->names(0));
        $this->assertSame(['Hi-pot', 'แรงบิด', 'ฉนวน'], $this->names(1));
        $this->assertSame(['Hi-pot'], $this->names(2), 'finished work is not reopened');
        $this->assertSame(33, $this->qc[0]->fresh()->progress, '1 of 3 ticked now');
        $this->assertSame(1, ActivityLog::where('action', 'checklist_edited')->count());
    }

    public function test_rename_exact_or_part_and_delete_keeps_ticked_ones(): void
    {
        $pm = User::forceCreate(['name' => 'P', 'email' => 'p', 'password' => 'x', 'role' => 'admin', 'is_active' => true]);
        $this->run_($pm, ['action' => 'rename', 'find' => 'hi-pot', 'replace' => 'Hi-pot test 2.5kV'])->assertOk()->assertJsonPath('changes', 3);
        $this->assertSame(['Hi-pot test 2.5kV'], $this->names(2));
        $this->run_($pm, ['action' => 'rename', 'find' => '2.5kV', 'replace' => '3kV', 'contains' => true])->assertOk();
        $this->assertSame(['Hi-pot test 3kV'], $this->names(1));

        $r = $this->run_($pm, ['action' => 'delete', 'names' => ['Hi-pot test 3kV']])->assertOk();
        $this->assertSame([2, 1], [$r->json('changes'), $r->json('skipped')]);
        $this->assertSame(['Hi-pot test 3kV'], $this->names(0), 'ticked in MO-1: kept');
        $this->assertSame([], $this->names(1));
        $this->assertSame(2, CabinetChecklist::onlyTrashed()->count(), 'soft-deleted (restorable)');

        $this->project->update(['status' => 'cancelled']);
        $this->run_($pm, ['action' => 'add', 'names' => 'x'])->assertStatus(423);
    }
}
