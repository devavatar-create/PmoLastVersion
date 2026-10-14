<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\CommentAttachment;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectFolder;
use App\Models\User;
use App\Services\ProjectFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Project files like folders on a computer: structure, upload + versions, who may do what, trash, serving through a check. */
class ProjectFilesTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Project $project;

    private Cabinet $mine;      // Busbar has work here

    private Cabinet $other;     // ...and not here

    private User $pm;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        AppSetting::put('file_default_folders', ['เอกสารโครงการ', 'แบบ (Drawing)', 'รูปหน้างาน', 'เอกสาร QC']);   // several folders to work in
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->mine = $this->cabinet('MO-1', true);
        $this->other = $this->cabinet('MO-2', false);
        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'pm', 'password' => 'x', 'role' => 'project_manager', 'is_active' => true]);
        $this->worker = User::forceCreate(['name' => 'W', 'email' => 'w', 'password' => 'x', 'role' => 'member', 'department_id' => $this->busbar->id, 'is_active' => true]);
    }

    private function cabinet(string $mo, bool $busbarWork): Cabinet
    {
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => $mo, 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        if ($busbarWork) {
            CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'บัส', 'department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        }

        return $cab;
    }

    private function folder(string $name): ProjectFolder
    {
        return ProjectFolder::where('project_id', $this->project->id)->where('name', $name)->firstOrFail();
    }

    private function upload(User $u, ?ProjectFolder $f, string $name, string $content = 'x', string $path = '')
    {
        return $this->actingAs($u)->post(route('project-files.upload', $this->project), array_filter([
            'file' => UploadedFile::fake()->createWithContent($name, $content), 'folder' => $f?->id, 'path' => $path,
        ]), ['Accept' => 'application/json']);
    }

    private function op(User $u, array $op)
    {
        return $this->actingAs($u)->postJson(route('project-files.ops', $this->project), $op);
    }

    public function test_out_of_the_box_a_project_has_only_its_documents_folder_besides_the_cabinets(): void
    {
        AppSetting::where('key', 'file_default_folders')->delete();   // never set on the settings page
        AppSetting::flush();
        $tree = $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project))->assertOk()->json();

        $this->assertSame(['เอกสารโครงการ', 'ตู้ / ใบสั่งผลิต (MO)'], collect($tree['folders'])->pluck('name')->all());
        $this->assertSame(['MO-1 Cab', 'MO-2 Cab'], array_column(collect($tree['folders'])->firstWhere('system', 'cabinets')['children'], 'name'), 'cabinet folders as before');
    }

    public function test_every_project_gets_its_folders_and_older_attachments_are_put_away(): void
    {
        $legacy = ProjectFile::create(['project_id' => $this->project->id, 'cabinet_id' => $this->mine->id, 'name' => 'old.jpg', 'disk' => 'public', 'path' => 'uploads/cabinet-attachments/old.jpg']);
        $tree = $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project))->assertOk()->json();

        $names = collect($tree['folders'])->pluck('name')->all();
        $this->assertSame(['เอกสารโครงการ', 'แบบ (Drawing)', 'รูปหน้างาน', 'เอกสาร QC', 'ตู้ / ใบสั่งผลิต (MO)'], $names, 'the defaults in their set order, cabinets last');
        $cabinets = collect($tree['folders'])->firstWhere('system', 'cabinets');
        $this->assertSame(['MO-1 Cab', 'MO-2 Cab'], array_column($cabinets['children'], 'name'), 'one folder per cabinet, made by the system');
        $this->assertSame($this->folder('MO-1 Cab')->id, $legacy->fresh()->folder_id, "the cabinet's older attachment is in its folder");

        $this->mine->update(['mo_no' => 'MO-1A']);
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $this->assertTrue(ProjectFolder::where('name', 'MO-1A Cab')->exists(), 'the folder follows the cabinet');
        $this->op($this->pm, ['type' => 'delete', 'folders' => [$this->folder('MO-1A Cab')->id]])->assertStatus(422);
    }

    public function test_upload_versions_and_an_older_version_back_on_top(): void
    {
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $docs = $this->folder('แบบ (Drawing)');

        $first = $this->upload($this->pm, $docs, 'SLD.pdf', 'v1')->assertOk()->json('item');
        $second = $this->upload($this->pm, $docs, 'sld.PDF', 'v2')->assertOk()->json('item');
        $this->assertSame([1, 2], [$first['version'], $second['version']], 'the same name again = the next version');
        $this->assertSame(1, ProjectFile::where('folder_id', $docs->id)->where('is_current', true)->count());
        $this->assertCount(2, $this->actingAs($this->pm)->getJson(route('project-files.versions', $second['id']))->json('versions'));

        $this->op($this->pm, ['type' => 'restoreVersion', 'id' => $first['id']])->assertOk();
        $now = ProjectFile::where('folder_id', $docs->id)->where('is_current', true)->first();
        $this->assertSame([3, 'v1'], [$now->version, Storage::disk('local')->get($now->path)], 'v1 is back - as version 3 (nothing lost)');

        $this->upload($this->pm, $docs, 'virus.exe')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ไม่รับไฟล์ .exe'));
        $this->op($this->pm, ['type' => 'log', 'ids' => [$now->id]])->assertOk();
        $this->assertStringContainsString('อัปโหลดไฟล์ 1 ไฟล์ ใน "แบบ (Drawing)"', ActivityLog::where('action', 'files')->latest('id')->value('description'));
    }

    public function test_a_whole_folder_from_the_computer_keeps_its_sub_folders(): void
    {
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $root = $this->folder('รูปหน้างาน');
        $this->upload($this->pm, $root, 'a.jpg', 'x', 'ส่งของ/วันแรก/a.jpg')->assertOk();
        $this->upload($this->pm, $root, 'b.jpg', 'x', 'ส่งของ/วันแรก/b.jpg')->assertOk();
        $day = ProjectFolder::where('name', 'วันแรก')->firstOrFail();
        $this->assertSame('ส่งของ', $day->parent->name);
        $this->assertSame($root->id, $day->parent->parent_id);
        $this->assertSame(['a.jpg', 'b.jpg'], ProjectFile::where('folder_id', $day->id)->orderBy('name')->pluck('name')->all());
    }

    public function test_what_a_department_may_do(): void
    {
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $this->op($this->pm, ['type' => 'mkdir', 'name' => 'การเงิน', 'restricted' => true])->assertOk();

        $this->upload($this->worker, $this->folder('MO-1 Cab'), 'bus.pdf')->assertOk();
        $this->upload($this->worker, $this->folder('MO-2 Cab'), 'x.pdf')->assertStatus(422);   // not a cabinet without its work
        $this->upload($this->worker, null, 'x.pdf')->assertStatus(422);   // not the top level
        $this->upload($this->worker, $this->folder('รูปหน้างาน'), 'site.jpg')->assertOk();

        $tree = $this->actingAs($this->worker)->getJson(route('project-files.tree', $this->project))->json('folders');
        $this->assertNotContains('การเงิน', array_column($tree, 'name'), 'Admin / PM only');
        $this->actingAs($this->worker)->getJson(route('project-files.index', [$this->project, 'in' => $this->folder('การเงิน')->id]))->assertStatus(422);
        $secret = $this->upload($this->pm, $this->folder('การเงิน'), 'cost.xlsx')->json('item');
        $this->actingAs($this->worker)->get(route('project-files.download', $secret['id']))->assertForbidden();

        $pmFile = $this->upload($this->pm, $this->folder('รูปหน้างาน'), 'pm.jpg')->json('item');
        $mine = ProjectFile::where('name', 'site.jpg')->first();
        $this->op($this->worker, ['type' => 'rename', 'kind' => 'file', 'id' => $pmFile['id'], 'name' => 'x'])->assertStatus(422);
        $this->op($this->worker, ['type' => 'rename', 'kind' => 'file', 'id' => $mine->id, 'name' => 'หน้าตู้'])->assertOk();
        $this->assertSame('หน้าตู้.jpg', $mine->fresh()->name, 'the extension stays');

        $stranger = User::forceCreate(['name' => 'S', 'email' => 's', 'password' => 'x', 'role' => 'member', 'department_id' => Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true])->id, 'is_active' => true]);
        $this->actingAs($stranger)->getJson(route('project-files.tree', $this->project))->assertForbidden();
        $this->actingAs($stranger)->get(route('project-files.download', $pmFile['id']))->assertForbidden();
    }

    public function test_a_folder_can_be_limited_to_departments_and_sub_folders_follow(): void
    {
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $inspector = User::forceCreate(['name' => 'Q', 'email' => 'q', 'password' => 'x', 'role' => 'member', 'department_id' => $qc->id, 'is_active' => true]);
        $this->project->assignees()->attach($inspector->id);   // sees the project as a person in charge
        $tree = fn (User $u) => collect($this->actingAs($u)->getJson(route('project-files.tree', $this->project))->json('folders'));
        $tree($this->pm);

        $this->op($this->pm, ['type' => 'mkdir', 'name' => 'ผลตรวจ', 'visibility' => 'departments', 'departments' => [$qc->id]])->assertOk();
        $folder = $this->folder('ผลตรวจ');
        $this->assertSame([$qc->id], $folder->department_ids);
        $this->assertStringContainsString('เห็นได้เฉพาะแผนก QC', ActivityLog::where('action', 'files')->latest('id')->value('description'));
        $report = $this->upload($this->pm, $folder, 'report.pdf')->json('item');
        $this->op($this->pm, ['type' => 'mkdir', 'parent' => $folder->id, 'name' => 'ภาพ', 'visibility' => 'all'])->assertOk();
        $this->upload($this->pm, $this->folder('ภาพ'), 'p.jpg')->assertOk();

        $this->assertSame([$qc->id], $tree($this->pm)->firstWhere('name', 'ผลตรวจ')['departments'], 'Admin / PM see it, with who it is for');
        $this->assertNotNull($tree($inspector)->firstWhere('name', 'ผลตรวจ'));
        $this->assertNull($tree($this->worker)->firstWhere('name', 'ผลตรวจ'), 'Busbar does not');
        $this->actingAs($this->worker)->getJson(route('project-files.index', [$this->project, 'in' => $this->folder('ภาพ')->id]))->assertStatus(422)
            ->assertJsonPath('message', 'คุณไม่มีสิทธิ์เปิดโฟลเดอร์นี้');   // a sub folder set to "everyone" is still behind its parent
        $this->actingAs($this->worker)->get(route('project-files.download', $report['id']))->assertForbidden();
        $this->actingAs($inspector)->get(route('project-files.download', $report['id']))->assertOk();
        $this->assertSame([], $this->actingAs($this->worker)->getJson(route('project-files.index', [$this->project, 'q' => 'report']))->json('items'), 'nor in a search');
        $this->upload($this->worker, $folder, 'x.pdf')->assertStatus(422);

        $files = app(ProjectFiles::class);
        $this->assertSame([2, 0, 2], [$files->count($this->project, null, $this->pm), $files->count($this->project, null, $this->worker), $files->count($this->project, null, $inspector)], 'the "ไฟล์ (n)" counts too');

        $this->op($this->pm, ['type' => 'delete', 'folders' => [$folder->id]])->assertOk();
        $this->assertSame([], $this->actingAs($this->worker)->getJson(route('project-files.index', [$this->project, 'in' => 'trash']))->json('items'), 'nor in the trash');
        $this->assertCount(1, $this->actingAs($inspector)->getJson(route('project-files.index', [$this->project, 'in' => 'trash']))->json('items'));
    }

    public function test_a_department_sees_only_the_folders_of_cabinets_it_works_on(): void
    {
        $tree = $this->actingAs($this->worker)->getJson(route('project-files.tree', $this->project))->json('folders');
        $this->assertSame(['MO-1 Cab'], array_column(collect($tree)->firstWhere('system', 'cabinets')['children'], 'name'));
        $theirs = $this->upload($this->pm, $this->folder('MO-2 Cab'), 'wiring.pdf')->json('item');
        $this->actingAs($this->worker)->get(route('project-files.download', $theirs['id']))->assertForbidden();

        $files = app(ProjectFiles::class);
        $this->assertFalse($files->sees($this->folder('MO-2 Cab'), $this->worker), 'the cabinet page opens at the top level instead');
        $this->assertTrue($files->sees($this->folder('MO-1 Cab'), $this->worker));
        $this->assertSame([$this->project->id => 1], $files->counts([$this->project->id], $this->pm));
        $this->assertSame([], $files->counts([$this->project->id], $this->worker), 'Planning\'s "📎 ไฟล์ (n)"');

        $this->actingAs($this->worker)->get(route('cabinets.show', $this->other))->assertOk()->assertSee('ไฟล์ (0)');
    }

    public function test_who_may_set_who_sees_a_folder(): void
    {
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $docs = $this->folder('เอกสารโครงการ');

        $this->op($this->worker, ['type' => 'mkdir', 'parent' => $docs->id, 'name' => 'ลับ', 'visibility' => 'pmo'])->assertStatus(422);
        $this->op($this->worker, ['type' => 'mkdir', 'parent' => $docs->id, 'name' => 'ของบัส', 'visibility' => 'departments', 'departments' => [$qc->id]])->assertOk();
        $this->assertSame([$this->busbar->id, $qc->id], $this->folder('ของบัส')->department_ids, 'the maker keeps their own department in');
        $this->op($this->worker, ['type' => 'mkdir', 'parent' => $docs->id, 'name' => 'ว่าง', 'visibility' => 'departments', 'departments' => []])->assertOk();
        $this->assertSame([$this->busbar->id], $this->folder('ว่าง')->department_ids);

        $this->op($this->worker, ['type' => 'rename', 'kind' => 'folder', 'id' => $docs->id, 'name' => 'x', 'visibility' => 'all'])->assertStatus(422);   // not theirs
        $this->op($this->pm, ['type' => 'rename', 'kind' => 'folder', 'id' => $this->folder('ของบัส')->id, 'name' => 'ของบัส', 'visibility' => 'pmo'])->assertOk();
        $this->assertTrue($this->folder('ของบัส')->restricted);
        $this->assertNull($this->folder('ของบัส')->department_ids);
        $this->op($this->pm, ['type' => 'rename', 'kind' => 'folder', 'id' => $this->folder('ว่าง')->id, 'name' => 'ว่าง', 'visibility' => 'departments', 'departments' => []])->assertStatus(422)
            ->assertJsonPath('message', 'เลือกอย่างน้อย 1 แผนก');
        $this->op($this->pm, ['type' => 'rename', 'kind' => 'folder', 'id' => $docs->id, 'name' => 'เอกสาร', 'visibility' => 'pmo'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ตั้งค่า → ไฟล์และโฟลเดอร์'));   // a standard folder is set in Settings
        $this->op($this->pm, ['type' => 'delete', 'folders' => [$docs->id]])->assertStatus(422);
        $this->assertNotNull($docs->fresh());
        $this->op($this->pm, ['type' => 'rename', 'kind' => 'folder', 'id' => $this->folder('MO-1 Cab')->id, 'name' => 'MO-1 Cab', 'visibility' => 'pmo'])->assertStatus(422);   // cabinet folders follow the work
    }

    public function test_move_trash_restore_and_purge(): void
    {
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $docs = $this->folder('เอกสารโครงการ');
        $this->op($this->pm, ['type' => 'mkdir', 'name' => 'ส่งมอบ'])->assertOk();   // an ordinary folder (standard ones are not moved / deleted)
        $qc = $this->folder('ส่งมอบ');
        $po = $this->upload($this->pm, $docs, 'PO.pdf', 'po')->json('item');
        $this->upload($this->pm, $qc, 'PO.pdf', 'other');

        $this->op($this->pm, ['type' => 'move', 'files' => [$po['id']], 'to' => $qc->id])->assertOk();
        $this->assertSame(['PO (2).pdf', 'PO.pdf'], ProjectFile::where('folder_id', $qc->id)->orderBy('name')->pluck('name')->all(), 'a name clash gets a number');
        $this->op($this->pm, ['type' => 'mkdir', 'parent' => $qc->id, 'name' => 'ย่อย'])->assertOk();
        $this->op($this->pm, ['type' => 'move', 'folders' => [$qc->id], 'to' => $this->folder('ย่อย')->id])->assertStatus(422);

        $this->op($this->pm, ['type' => 'delete', 'folders' => [$qc->id]])->assertOk();
        $trash = $this->actingAs($this->pm)->getJson(route('project-files.index', [$this->project, 'in' => 'trash']))->json('items');
        $this->assertSame(['ส่งมอบ'], array_column($trash, 'name'), 'its files and sub folders go with it (not listed one by one)');
        $this->op($this->pm, ['type' => 'restore', 'folders' => [$qc->id]])->assertOk();
        $this->assertSame(2, ProjectFile::where('folder_id', $qc->id)->count());

        $file = ProjectFile::where('name', 'PO.pdf')->where('folder_id', $qc->id)->first();
        $this->op($this->pm, ['type' => 'delete', 'files' => [$file->id]])->assertOk();
        $this->op($this->worker, ['type' => 'purge', 'files' => [$file->id]])->assertStatus(422);
        $this->op($this->pm, ['type' => 'purge', 'files' => [$file->id]])->assertOk();
        $this->assertFalse(Storage::disk('local')->exists($file->path), 'gone for good, bytes too');

        $old = $this->upload($this->pm, $docs, 'old.txt')->json('item');
        $this->op($this->pm, ['type' => 'delete', 'files' => [$old['id']]]);
        $this->travel(ProjectFiles::TRASH_DAYS + 1)->days();
        $this->assertSame(1, app(ProjectFiles::class)->purgeExpired());
    }

    public function test_serving_zip_comments_and_a_closed_project(): void
    {
        $this->actingAs($this->pm)->getJson(route('project-files.tree', $this->project));
        $docs = $this->folder('เอกสารโครงการ');
        $pdf = $this->upload($this->pm, $docs, 'spec.pdf', '%PDF-1.4')->json('item');
        $dwg = $this->upload($this->pm, $docs, 'layout.dwg', 'dwg')->json('item');

        $r = $this->actingAs($this->worker)->get(route('project-files.download', [$pdf['id'], 'inline' => 1]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'), 'a PDF opens in the browser');
        $r = $this->actingAs($this->worker)->get(route('project-files.download', [$dwg['id'], 'inline' => 1]))->assertOk();
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'), 'a DWG is downloaded');

        $zip = $this->actingAs($this->worker)->get(route('project-files.zip', [$this->project, 'folders' => [$docs->id]]))->assertOk();
        $this->assertStringContainsString('.zip', $zip->headers->get('Content-Disposition'));

        $note = ActivityLog::record($this->project->id, $this->pm->id, $this->project, 'note', 'ดูรูปนี้');
        CommentAttachment::create(['activity_log_id' => $note->id, 'original_name' => 'rup.jpg', 'path' => 'r.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'uploaded_by' => $this->pm->id]);
        $this->assertSame(['rup.jpg'], array_column($this->actingAs($this->worker)->getJson(route('project-files.index', [$this->project, 'in' => 'comments']))->json('items'), 'name'));

        $this->project->update(['status' => 'completed']);
        $this->upload($this->pm, $docs, 'late.pdf')->assertStatus(422);
        $this->op($this->pm, ['type' => 'delete', 'files' => [$pdf['id']]])->assertStatus(422);
        $this->actingAs($this->pm)->get(route('project-files.download', $pdf['id']))->assertOk();
    }
}
