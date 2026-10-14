<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectFolder;
use App\Models\User;
use App\Services\ProjectFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > ไฟล์และโฟลเดอร์: the standard folders and who sees them, set once for every project; the cabinets' folders. */
class FileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $buying;

    private User $admin;

    private User $worker;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->buying = Department::create(['name' => 'จัดซื้อ', 'code' => 'PUR', 'is_active' => true]);
        $this->admin = User::forceCreate(['name' => 'A', 'email' => 'a', 'password' => 'x', 'role' => 'admin', 'is_active' => true]);
        $this->worker = User::forceCreate(['name' => 'W', 'email' => 'w', 'password' => 'x', 'role' => 'member', 'department_id' => $this->busbar->id, 'is_active' => true]);
        $this->project = $this->project('P-1');
    }

    private function project(string $no): Project
    {
        $p = Project::forceCreate(['project_no' => $no, 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        foreach (['MO-1' => true, 'MO-2' => false] as $mo => $work) {
            $cab = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => $no.$mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
            $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
            if ($work) {
                CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'บัส', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
            }
        }

        return $p;
    }

    private function top(User $u, ?Project $p = null): array
    {
        return array_column($this->actingAs($u)->getJson(route('project-files.tree', $p ?? $this->project))->assertOk()->json('folders'), 'name');
    }

    private function save(array $rows, string $cabinets = 'work')
    {
        return $this->actingAs($this->admin)->put(route('settings.files.update'), ['rows' => $rows, 'cabinets' => $cabinets]);
    }

    public function test_only_admin_opens_it(): void
    {
        $this->actingAs($this->worker)->get(route('settings.files.edit'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.files.edit'))->assertOk()->assertSee('โฟลเดอร์มาตรฐานของทุกโครงการ')
            ->assertViewHas('rows', [['name' => 'เอกสารโครงการ', 'visibility' => 'all', 'departments' => []]]);
        $this->actingAs($this->worker)->put(route('settings.files.update'), ['rows' => [['name' => 'x', 'visibility' => 'all']], 'cabinets' => 'work'])->assertForbidden();
    }

    public function test_saving_brings_every_project_in_step(): void
    {
        $this->top($this->admin);   // P-1 has its folders already
        $docs = ProjectFolder::where('name', 'เอกสารโครงการ')->firstOrFail();
        ProjectFile::create(['project_id' => $this->project->id, 'folder_id' => $docs->id, 'name' => 'po.pdf', 'disk' => 'local', 'path' => 'x/po.pdf']);
        $fresh = $this->project('P-2');   // never opened

        $this->save([
            ['from' => 'เอกสารโครงการ', 'name' => 'เอกสารจัดซื้อ', 'visibility' => 'departments', 'departments' => [$this->buying->id]],
            ['from' => '', 'name' => 'แบบ (Drawing)', 'visibility' => 'all'],
            ['from' => '', 'name' => 'การเงิน', 'visibility' => 'pmo'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(['เอกสารจัดซื้อ', 'แบบ (Drawing)', 'การเงิน', 'ตู้ / ใบสั่งผลิต (MO)'], $this->top($this->admin), 'renamed, added, in the set order');
        $this->assertSame([$this->buying->id], $docs->fresh()->department_ids, 'the same folder (its files with it), now for จัดซื้อ only');
        $this->assertSame(['แบบ (Drawing)', 'ตู้ / ใบสั่งผลิต (MO)'], $this->top($this->worker), 'Busbar sees neither จัดซื้อ\'s nor Admin / PM\'s');
        $this->assertSame(0, app(ProjectFiles::class)->count($this->project, null, $this->worker));
        $this->assertSame(['เอกสารจัดซื้อ', 'แบบ (Drawing)', 'การเงิน', 'ตู้ / ใบสั่งผลิต (MO)'], $this->top($this->admin, $fresh), 'a project opened later gets the same');

        // taken off the list: an empty one goes, one with files stays as an ordinary folder
        $this->save([['from' => 'แบบ (Drawing)', 'name' => 'แบบ (Drawing)', 'visibility' => 'all']])->assertSessionHasNoErrors();
        $this->assertFalse(ProjectFolder::withTrashed()->where('name', 'การเงิน')->exists());
        $this->assertNull($docs->fresh()->system, 'kept - it has a file');
        $this->assertSame([$this->buying->id], $docs->fresh()->department_ids, 'and keeps who sees it');
        $this->assertSame(['แบบ (Drawing)', 'เอกสารจัดซื้อ', 'ตู้ / ใบสั่งผลิต (MO)'], $this->top($this->admin), 'standard ones first, then the others, cabinets last');
    }

    public function test_bad_input_is_refused(): void
    {
        $this->save([])->assertSessionHasErrors('rows');
        $this->save([['name' => 'A', 'visibility' => 'all'], ['name' => 'a', 'visibility' => 'all']])->assertSessionHasErrors('rows.1.name');
        $this->save([['name' => 'A', 'visibility' => 'departments', 'departments' => []]])->assertSessionHasErrors('rows.0.departments');
        $this->assertSame(['เอกสารโครงการ'], array_column(ProjectFiles::standardFolders(), 'name'), 'nothing saved');
    }

    public function test_every_cabinet_folder_can_be_open_to_everyone(): void
    {
        $cabinets = fn () => array_column(collect($this->actingAs($this->worker)->getJson(route('project-files.tree', $this->project))->json('folders'))->firstWhere('system', 'cabinets')['children'], 'name');
        $this->assertSame(['P-1MO-1 Cab'], $cabinets());

        $this->save([['from' => 'เอกสารโครงการ', 'name' => 'เอกสารโครงการ', 'visibility' => 'all']], 'all')->assertSessionHasNoErrors();
        $this->assertSame('all', AppSetting::get('file_cabinet_folders'));
        $this->assertSame(['P-1MO-1 Cab', 'P-1MO-2 Cab'], $cabinets());
    }
}
