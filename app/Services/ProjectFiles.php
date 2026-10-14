<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CommentAttachment;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project's files, kept like folders on a computer:
 *  - the standard folders every project has (Settings > ไฟล์และโฟลเดอร์: names, order, who sees them - kept the same in
 *    every project, not changed inside one), plus "ตู้ / ใบสั่งผลิต" with one folder per cabinet (made
 *    and named by the system), plus "จากคอมเมนต์" (the files attached to comments - shown, not copied)
 *  - many files at once, a whole folder from the computer, the same name again = a new version (older ones kept)
 *  - rename, move, delete -> trash (30 days, then gone), restore; download one, or many as .zip
 *  - who sees what: Admin / PM everything. Others (whoever sees the project): not folders marked "เฉพาะ Admin / PM", not
 *    folders limited to other departments, and (unless Settings says every cabinet) only the folders of cabinets their
 *    department has work in (as on Planning).
 *    A sub folder is seen only by people who also see its parent. Admin / PM set who sees a folder, its maker too (always
 *    keeping their own department in). Admin / PM do everything; a
 *    department adds files in its own cabinets' folders and in ordinary folders, and renames / moves / deletes what it added
 *  - a closed project can be read, not changed. Files are served through a permission check (storage/app/private), never
 *    straight from a public URL.
 */
class ProjectFiles
{
    public const MAX_MB = 50;

    public const TRASH_DAYS = 30;

    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'csv', 'ppt', 'pptx', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff',
        'dwg', 'dxf', 'dwf', 'step', 'stp', 'igs', 'iges', 'zip', 'rar', '7z', 'mp4', 'mov'];

    public const DEFAULT_FOLDERS = ['เอกสารโครงการ'];

    public const VISIBILITIES = ['all', 'departments', 'pmo'];

    /**
     * The standard folders (Settings > ไฟล์และโฟลเดอร์), in order. Older settings were just names.
     *
     * @return list<array{name: string, visibility: string, departments: list<int>}>
     */
    public static function standardFolders(): array
    {
        return collect((array) (AppSetting::get('file_default_folders') ?: self::DEFAULT_FOLDERS))
            ->map(fn ($r) => is_array($r) ? $r : ['name' => (string) $r])
            ->map(fn ($r) => ['name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 120),
                'visibility' => in_array($r['visibility'] ?? null, self::VISIBILITIES, true) ? $r['visibility'] : 'all',
                'departments' => array_values(array_map('intval', (array) ($r['departments'] ?? [])))])
            ->filter(fn ($r) => $r['name'] !== '')->values()->all();
    }

    /** A standard folder's who-sees columns. */
    private static function standardColumns(array $row): array
    {
        return match (true) {
            $row['visibility'] === 'pmo' => ['restricted' => true, 'department_ids' => null],
            $row['visibility'] === 'departments' && $row['departments'] !== [] => ['restricted' => false, 'department_ids' => $row['departments']],
            default => ['restricted' => false, 'department_ids' => null],
        };
    }

    /** @var array<string, int>|null lower-case standard folder name => its place */
    private ?array $standardOrder = null;

    // ======================================================================= who may

    public function canView(Project $project, User $user): bool
    {
        return $user->allows('data.files') && WorkScope::canSeeProject($project, $user);   // Settings > สิทธิ์การใช้งาน, then which projects
    }

    private function pmo(User $user): bool
    {
        return $user->canDispatchWork();
    }

    /** @var array<string, list<int>|null> "project:user" => the cabinets whose folders the person sees (null = all) */
    private array $seenCabinets = [];

    /** @param Collection<int, ProjectFolder> $all the project's folders, by id */
    private function hidden(?ProjectFolder $folder, Collection $all, User $user): bool
    {
        if (! $folder || $this->pmo($user)) {
            return false;
        }
        for ($f = $folder; $f; $f = $all->get($f->parent_id)) {
            if ($f->restricted || ($f->department_ids && ! in_array((int) $user->department_id, array_map('intval', $f->department_ids), true))) {
                return true;
            }
            if ($f->cabinet_id && ($seen = $this->cabinetsSeenBy($f->project_id, $user)) !== null && ! in_array($f->cabinet_id, $seen, true)) {
                return true;
            }
        }

        return false;
    }

    /** Cabinets whose folders a person sees: all for those who see all work and the project's people in charge; else the ones their department has work in. */
    private function cabinetsSeenBy(int $projectId, User $user): ?array
    {
        if (! array_key_exists($projectId.':'.$user->id, $this->seenCabinets)) {
            $this->primeCabinets([$projectId], $user);
        }

        return $this->seenCabinets[$projectId.':'.$user->id];
    }

    /** @param list<int> $projectIds worked out in two queries, for a page that counts the files of many projects */
    public function primeCabinets(array $projectIds, User $user): void
    {
        if (! $projectIds) {
            return;
        }
        $inCharge = $user->canSeeAllWork() || AppSetting::get('file_cabinet_folders') === 'all' ? $projectIds
            : Project::whereKey($projectIds)->whereHas('assignees', fn ($q) => $q->whereKey($user->id))->pluck('id')->all();
        $work = $user->department_id ? DB::table('cabinet_subtasks as s')->join('cabinet_tasks as t', 't.id', '=', 's.cabinet_task_id')->join('cabinets as c', 'c.id', '=', 't.cabinet_id')
            ->whereIn('c.project_id', $projectIds)->where('s.department_id', $user->department_id)->whereIn('s.assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereNull('s.deleted_at')->whereNull('t.deleted_at')->whereNull('c.deleted_at')->distinct()->get(['c.id', 'c.project_id'])->groupBy('project_id') : collect();
        foreach ($projectIds as $pid) {
            $this->seenCabinets[$pid.':'.$user->id] = in_array($pid, $inCharge) ? null : ($work->get($pid)?->pluck('id')->map(fn ($id) => (int) $id)->all() ?? []);
        }
    }

    /**
     * "ใครเห็นได้" from the folder window -> the columns. 'all' (whoever sees the project), 'departments' (+ Admin / PM),
     * 'pmo' (Admin / PM only - only they may set it). The folder's maker always keeps their own department in.
     */
    private function visibility(array $op, User $user): array
    {
        $who = $op['visibility'] ?? (! empty($op['restricted']) ? 'pmo' : 'all');
        if ($who === 'pmo') {
            if (! $this->pmo($user)) {
                throw new PlanningRejected('"เห็นเฉพาะ Admin / PM" ตั้งได้เฉพาะ Admin / PM');
            }

            return ['restricted' => true, 'department_ids' => null];
        }
        if ($who !== 'departments') {
            return ['restricted' => false, 'department_ids' => null];
        }
        $ids = Department::where('is_active', true)->whereKey(array_map('intval', (array) ($op['departments'] ?? [])))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $this->pmo($user)) {
            if (! $user->department_id) {
                throw new PlanningRejected('บัญชีของคุณยังไม่มีแผนก — จำกัดตามแผนกไม่ได้');
            }
            $ids[] = (int) $user->department_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (! $ids) {
            throw new PlanningRejected('เลือกอย่างน้อย 1 แผนก');
        }

        return ['restricted' => false, 'department_ids' => $ids];
    }

    /** "เห็นได้เฉพาะ …" for the Activity Log. */
    private function visibilityText(ProjectFolder $f): string
    {
        return $f->restricted ? 'เฉพาะ Admin / PM'
            : ($f->department_ids ? 'เฉพาะแผนก '.Department::whereKey($f->department_ids)->orderBy('name')->pluck('name')->implode(', ').' (และ Admin / PM)' : 'ทุกคนที่เห็นโครงการ');
    }

    /** Whether this person sees the folder (the cabinet page opens the file manager at the cabinet's folder only if so). */
    public function sees(?ProjectFolder $folder, User $user): bool
    {
        return ! $folder || ! $this->hidden($folder, $this->folders(Project::withTrashed()->find($folder->project_id)), $user);
    }

    /** The cabinet a folder belongs to (the folder itself or one of its parents is a cabinet's folder). */
    private function cabinetOf(?ProjectFolder $folder, Collection $all): ?int
    {
        for ($f = $folder; $f; $f = $all->get($f->parent_id)) {
            if ($f->cabinet_id) {
                return $f->cabinet_id;
            }
        }

        return null;
    }

    public function canAddTo(Project $project, ?ProjectFolder $folder, User $user, ?Collection $all = null): bool
    {
        if ($project->isLocked()) {
            return false;
        }
        if ($this->pmo($user)) {
            return true;
        }
        $all ??= $this->folders($project);
        if (! $folder || $folder->system === 'cabinets' || $this->hidden($folder, $all, $user)) {
            return false;   // the top level and the cabinets' container are kept tidy by Admin / PM
        }
        $cabinet = $this->cabinetOf($folder, $all);

        return ! $cabinet || ($user->department_id && CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet))
            ->where('department_id', $user->department_id)->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)->exists());
    }

    private function canEditFile(ProjectFile $file, User $user): bool
    {
        return $this->pmo($user) || $file->uploaded_by === $user->id;
    }

    private function canEditFolder(ProjectFolder $folder, User $user): bool
    {
        return ! in_array($folder->system, ['cabinets', 'cabinet', 'default'], true) && ($this->pmo($user) || $folder->created_by === $user->id);   // standard folders: in Settings
    }

    // ======================================================================= structure

    private function folders(Project $project, bool $withTrashed = false): Collection
    {
        return ProjectFolder::where('project_id', $project->id)->when($withTrashed, fn ($q) => $q->withTrashed())->orderBy('name')->get()->keyBy('id');
    }

    /** Default folders on first use, the cabinets' container with a folder per cabinet (kept in step), older attachments put away. */
    public function ensureStructure(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $all = ProjectFolder::withTrashed()->where('project_id', $project->id)->get();
            foreach (self::standardFolders() as $row) {
                $have = $all->first(fn ($f) => $f->system === 'default' && ! $f->parent_id && mb_strtolower($f->name) === mb_strtolower($row['name']));
                if (! $have) {
                    $all->push(ProjectFolder::create(['project_id' => $project->id, 'name' => $row['name'], 'system' => 'default'] + self::standardColumns($row)));
                } elseif ($have->trashed()) {
                    $have->restore();
                }
            }
            $container = $all->firstWhere('system', 'cabinets')
                ?? ProjectFolder::create(['project_id' => $project->id, 'name' => 'ตู้ / ใบสั่งผลิต (MO)', 'system' => 'cabinets']);
            if ($container->trashed()) {
                $container->restore();
            }
            foreach (Cabinet::where('project_id', $project->id)->orderBy('id')->get(['id', 'mo_no', 'cabinet_name']) as $cab) {
                $name = mb_substr(trim($cab->mo_no.' '.$cab->cabinet_name), 0, 120) ?: 'ตู้ #'.$cab->id;
                $folder = $all->first(fn ($f) => $f->cabinet_id === $cab->id);
                if (! $folder) {
                    $folder = ProjectFolder::create(['project_id' => $project->id, 'parent_id' => $container->id, 'cabinet_id' => $cab->id, 'name' => $name, 'system' => 'cabinet']);
                } elseif ($folder->name !== $name || $folder->trashed()) {
                    $folder->trashed() && $folder->restore();
                    $folder->forceFill(['name' => $name])->save();   // follows the cabinet's MO / name
                }
                ProjectFile::where('project_id', $project->id)->where('cabinet_id', $cab->id)->whereNull('folder_id')->update(['folder_id' => $folder->id]);
            }
            // older project attachments: into the first default folder
            $first = ProjectFolder::where('project_id', $project->id)->where('system', 'default')->orderBy('id')->first();
            if ($first) {
                ProjectFile::where('project_id', $project->id)->whereNull('folder_id')->whereNull('cabinet_id')->where('disk', 'public')->update(['folder_id' => $first->id]);
            }
        });
    }

    /**
     * Settings > ไฟล์และโฟลเดอร์ was saved: every project that has folders follows at once - renamed ($row['from'] = the
     * name before), who sees it, added. A folder taken off the list goes if it is empty, else it stays as an ordinary
     * folder of that project (nothing in it is lost). Projects without folders yet get them on first use.
     *
     * @param  list<array{name: string, visibility: string, departments: list<int>, from?: string|null}>  $rows
     * @return int how many projects were brought in step
     */
    public function applyStandardFolders(array $rows): int
    {
        $projects = DB::transaction(function () use ($rows) {
            $top = ProjectFolder::withTrashed()->whereNull('parent_id')->whereNull('cabinet_id')->whereIn(DB::raw("COALESCE(`system`, '')"), ['default', ''])->get()->groupBy('project_id');
            $projects = ProjectFolder::withTrashed()->distinct()->pluck('project_id');
            foreach ($projects as $pid) {
                $mine = $top->get($pid, collect())->sortBy(fn ($f) => $f->system === 'default' ? 0 : 1);   // a standard one first, else an ordinary one of that name
                $kept = [];
                foreach ($rows as $row) {
                    $names = array_filter([mb_strtolower((string) ($row['from'] ?? '')), mb_strtolower($row['name'])]);
                    $f = $mine->first(fn ($f) => ! in_array($f->id, $kept, true) && in_array(mb_strtolower($f->name), $names, true) && ($f->system === 'default' || mb_strtolower($f->name) === mb_strtolower($row['name'])));
                    $cols = ['name' => $row['name'], 'system' => 'default'] + self::standardColumns($row);
                    if ($f) {
                        $f->forceFill($cols + ['deleted_at' => null])->saveQuietly();
                    } else {
                        $f = ProjectFolder::create(['project_id' => $pid] + $cols);
                    }
                    $kept[] = $f->id;
                }
                foreach ($mine->where('system', 'default')->whereNotIn('id', $kept) as $gone) {
                    $used = ProjectFile::withTrashed()->where('folder_id', $gone->id)->exists() || ProjectFolder::withTrashed()->where('parent_id', $gone->id)->exists();
                    $used ? $gone->forceFill(['system' => null])->saveQuietly() : $gone->forceDelete();
                }
            }

            return $projects->count();
        });
        $this->standardOrder = null;
        PlanningBoard::touch();   // the "📎 ไฟล์ (n)" counts may change

        return $projects;
    }

    // ======================================================================= reading

    /** A cabinet's own folder (made if needed) - the cabinet page opens the file manager there. */
    public function cabinetFolder(Cabinet $cabinet): ?ProjectFolder
    {
        $this->ensureStructure($cabinet->project);

        return ProjectFolder::where('cabinet_id', $cabinet->id)->first();
    }

    /** The newest files this person may see (current versions), for the project overview's "ไฟล์ล่าสุด". */
    public function latest(Project $project, User $user, int $limit = 3): array
    {
        $all = $this->folders($project);

        return ProjectFile::where('project_id', $project->id)->where('is_current', true)->latest('id')->limit($limit * 5)->get()
            ->reject(fn ($f) => $this->hidden($all->get($f->folder_id), $all, $user))
            ->take($limit)
            ->map(fn ($f) => ['name' => $f->name, 'url' => route('project-files.download', [$f, 'inline' => 1]), 'at' => $f->created_at?->thaiDate()])
            ->values()->all();
    }

    /** How many files (current versions) a project has, or one folder (with its sub folders). */
    public function count(Project $project, ?ProjectFolder $folder = null, ?User $user = null): int
    {
        return $this->counts([$project->id], $user, $folder)[$project->id] ?? 0;
    }

    /**
     * Files per project (current versions, not in the trash) - only those in folders this person sees when one is given.
     *
     * @param  list<int>  $projectIds
     * @return array<int, int>
     */
    public function counts(array $projectIds, ?User $user = null, ?ProjectFolder $within = null): array
    {
        if (! $projectIds) {
            return [];
        }
        $rows = ProjectFile::whereIn('project_id', $projectIds)->where('is_current', true)
            ->selectRaw('project_id, folder_id, COUNT(*) n')->groupBy('project_id', 'folder_id')->get();
        $folders = ProjectFolder::whereIn('project_id', $projectIds)->get()->keyBy('id');
        $only = $within ? array_flip($this->descendants($within->id, $folders)) : null;
        if ($user && ! $this->pmo($user)) {
            $this->primeCabinets(array_values(array_filter($projectIds, fn ($pid) => ! array_key_exists($pid.':'.$user->id, $this->seenCabinets))), $user);
        }
        $out = [];
        foreach ($rows as $r) {
            $folder = $r->folder_id ? $folders->get($r->folder_id) : null;
            if (($only !== null && ! isset($only[$r->folder_id])) || ($user && $this->hidden($folder, $folders, $user))) {
                continue;
            }
            $out[$r->project_id] = ($out[$r->project_id] ?? 0) + (int) $r->n;
        }

        return $out;
    }

    /** The folder tree (what this person may see) with file counts, plus "จากคอมเมนต์" and the trash. */
    public function tree(Project $project, User $user): array
    {
        $this->ensureStructure($project);
        $all = $this->folders($project);
        $counts = ProjectFile::where('project_id', $project->id)->where('is_current', true)->selectRaw('folder_id, COUNT(*) n')->groupBy('folder_id')->pluck('n', 'folder_id');
        $visible = $all->reject(fn ($f) => $this->hidden($f, $all, $user));
        $node = function (?int $parent) use (&$node, $visible, $counts, $user) {
            return $visible->where('parent_id', $parent)
                ->sortBy(fn ($f) => $this->order($f))->values()
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'system' => $f->system, 'restricted' => $f->restricted, 'departments' => $f->department_ids ?: [],
                    'count' => (int) ($counts[$f->id] ?? 0), 'canEdit' => $this->canEditFolder($f, $user), 'children' => $node($f->id)])->all();
        };

        return [
            'folders' => $node(null),
            'rootCount' => ProjectFile::where('project_id', $project->id)->where('is_current', true)->whereNull('folder_id')->count(),
            'comments' => $this->commentFiles($project)->count(),
            'trash' => ProjectFile::onlyTrashed()->where('project_id', $project->id)->where('is_current', true)->count()
                + ProjectFolder::onlyTrashed()->where('project_id', $project->id)->count(),
            'canAddRoot' => $this->canAddTo($project, null, $user, $all),
            'locked' => $project->isLocked(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->all(),   // for "ใครเห็นได้"
            'myDepartment' => $user->department_id,
            'maxMb' => min(self::MAX_MB, $this->phpLimitMb()),
            'extensions' => self::EXTENSIONS,
        ];
    }

    /** What is in a folder ('' = top level), "comments", "trash", or a search. */
    public function listing(Project $project, User $user, string $where, string $q = ''): array
    {
        $all = $this->folders($project);
        $q = trim($q);

        if ($q !== '') {   // search: every visible file of the project by name
            $files = ProjectFile::where('project_id', $project->id)->where('is_current', true)->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
                ->with('uploader:id,name')->orderBy('name')->limit(300)->get()
                ->reject(fn ($f) => $this->hidden($all->get($f->folder_id), $all, $user));

            return ['crumbs' => [['id' => null, 'name' => 'ค้นหา "'.$q.'"']], 'items' => $files->map(fn ($f) => $this->fileItem($f, $user, $this->pathOf($f->folder_id, $all)))->values()->all(), 'canAdd' => false];
        }

        if ($where === 'comments') {
            return ['crumbs' => [['id' => 'comments', 'name' => 'จากคอมเมนต์']], 'canAdd' => false,
                'items' => $this->commentFiles($project)->map(fn (CommentAttachment $a) => [
                    'type' => 'comment', 'id' => 'c'.$a->id, 'name' => $a->original_name, 'size' => (int) $a->size, 'ext' => strtolower(pathinfo($a->original_name, PATHINFO_EXTENSION)),
                    'by' => $a->uploadedBy?->name, 'at' => $a->created_at?->thaiDateTime(), 'url' => $a->url, 'preview' => (bool) preg_match('/\.(jpe?g|png|gif|webp|pdf)$/i', $a->original_name),
                    'note' => mb_strimwidth((string) $a->activityLog?->description, 0, 80, '…'), 'noteUrl' => route('planning.board', ['project' => $project->id]),
                ])->values()->all()];
        }

        if ($where === 'trash') {
            $since = now()->subDays(self::TRASH_DAYS);
            $any = $this->folders($project, true);   // a deleted thing is seen by whoever saw it before
            $folders = ProjectFolder::onlyTrashed()->where('project_id', $project->id)->where('deleted_at', '>=', $since)->get()
                ->reject(fn ($f) => $f->parent_id && ProjectFolder::onlyTrashed()->whereKey($f->parent_id)->where('deleted_at', $f->deleted_at)->exists())   // shown with the folder it went with
                ->reject(fn ($f) => $this->hidden($f, $any, $user));
            $files = ProjectFile::onlyTrashed()->where('project_id', $project->id)->where('is_current', true)->where('deleted_at', '>=', $since)->with('uploader:id,name')->get()
                ->reject(fn ($f) => $f->folder_id && ProjectFolder::onlyTrashed()->whereKey($f->folder_id)->where('deleted_at', $f->deleted_at)->exists())
                ->reject(fn ($f) => $this->hidden($any->get($f->folder_id), $any, $user));

            return ['crumbs' => [['id' => 'trash', 'name' => 'ถังขยะ']], 'canAdd' => false,
                'items' => $folders->map(fn ($f) => ['type' => 'folder', 'id' => $f->id, 'name' => $f->name, 'trashed' => true, 'at' => $f->deleted_at->thaiDateTime(),
                    'canEdit' => $this->canEditFolder($f, $user), 'expires' => $f->deleted_at->copy()->addDays(self::TRASH_DAYS)->thaiDate()])
                    ->concat($files->map(fn ($f) => $this->fileItem($f, $user) + ['trashed' => true, 'expires' => $f->deleted_at->copy()->addDays(self::TRASH_DAYS)->thaiDate()]))->values()->all()];
        }

        $folder = $where === '' ? null : ($all->get((int) $where) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์นี้ (อาจถูกลบไปแล้ว)'));
        if ($folder && $this->hidden($folder, $all, $user)) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์เปิดโฟลเดอร์นี้');
        }
        $counts = ProjectFile::where('project_id', $project->id)->where('is_current', true)->selectRaw('folder_id, COUNT(*) n')->groupBy('folder_id')->pluck('n', 'folder_id');
        $subs = $all->where('parent_id', $folder?->id)->reject(fn ($f) => $this->hidden($f, $all, $user))
            ->sortBy(fn ($f) => $this->order($f))->values();
        $files = ProjectFile::where('project_id', $project->id)->where('is_current', true)->where('folder_id', $folder?->id)->with('uploader:id,name')->orderBy('name')->get();

        return [
            'crumbs' => array_merge([['id' => '', 'name' => $project->project_no]], $folder ? $this->pathOf($folder->id, $all) : []),
            'canAdd' => $this->canAddTo($project, $folder, $user, $all),
            'folder' => $folder ? ['id' => $folder->id, 'restricted' => $folder->restricted, 'departments' => $folder->department_ids ?: [], 'system' => $folder->system] : null,
            'items' => $subs->map(fn ($f) => ['type' => 'folder', 'id' => $f->id, 'name' => $f->name, 'system' => $f->system, 'restricted' => $f->restricted, 'departments' => $f->department_ids ?: [],
                'count' => (int) ($counts[$f->id] ?? 0), 'canEdit' => $this->canEditFolder($f, $user)])
                ->concat($files->map(fn ($f) => $this->fileItem($f, $user)))->values()->all(),
        ];
    }

    private function fileItem(ProjectFile $f, User $user, ?array $path = null): array
    {
        return ['type' => 'file', 'id' => $f->id, 'name' => $f->name, 'size' => $f->size, 'ext' => $f->extension(), 'version' => $f->version,
            'by' => $f->uploader?->name, 'at' => $f->created_at?->thaiDateTime(), 'preview' => $f->previewable(),
            'url' => route('project-files.download', $f), 'viewUrl' => route('project-files.download', [$f, 'inline' => 1]),
            'canEdit' => $this->canEditFile($f, $user), 'path' => $path ? collect($path)->pluck('name')->implode(' / ') : null];
    }

    /** @return list<array{id: int, name: string}> */
    private function pathOf(?int $folderId, Collection $all): array
    {
        $path = [];
        for ($f = $all->get($folderId); $f; $f = $all->get($f->parent_id)) {
            array_unshift($path, ['id' => $f->id, 'name' => $f->name]);
        }

        return $path;
    }

    private function commentFiles(Project $project): Collection
    {
        return CommentAttachment::whereHas('activityLog', fn ($q) => $q->where('project_id', $project->id))
            ->with('uploadedBy:id,name', 'activityLog:id,description')->latest('id')->get();
    }

    public function versions(ProjectFile $file, User $user): array
    {
        $this->authorizeView($file, $user);
        $out = [];
        for ($v = ProjectFile::withTrashed()->with('uploader:id,name')->find($file->id); $v; $v = $v->previous_id ? ProjectFile::withTrashed()->with('uploader:id,name')->find($v->previous_id) : null) {
            $out[] = ['id' => $v->id, 'version' => $v->version, 'size' => $v->size, 'by' => $v->uploader?->name, 'at' => $v->created_at?->thaiDateTime(),
                'url' => route('project-files.download', $v), 'current' => $v->is_current];
        }

        return $out;
    }

    // ======================================================================= writing

    /** One uploaded file into a folder ('' = top level); $relative "a/b/x.pdf" (a folder dragged in) makes the sub folders. */
    public function upload(Project $project, User $user, ?int $folderId, UploadedFile $upload, string $relative = ''): array
    {
        $all = $this->folders($project);
        $folder = $folderId ? ($all->get($folderId) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์นี้')) : null;
        if (! $this->canAddTo($project, $folder, $user, $all)) {
            throw new PlanningRejected($project->isLocked() ? 'โครงการปิดแล้ว — เพิ่มไฟล์ไม่ได้' : 'คุณเพิ่มไฟล์ในโฟลเดอร์นี้ไม่ได้');
        }
        $name = $this->cleanName($upload->getClientOriginalName());
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new PlanningRejected("ไม่รับไฟล์ .{$ext} — รับ: ".implode(', ', self::EXTENSIONS));
        }
        if ($upload->getSize() > self::MAX_MB * 1048576) {
            throw new PlanningRejected("{$name} ใหญ่เกิน ".self::MAX_MB.' MB');
        }

        $id = DB::transaction(function () use ($project, $user, $folder, $upload, $relative, $name, $ext) {
            // a folder dragged in from the computer: its sub folders come too
            foreach (array_slice(array_filter(explode('/', str_replace('\\', '/', $relative))), 0, -1) as $part) {
                $part = mb_substr($this->cleanName($part), 0, 120);
                $folder = ProjectFolder::firstOrCreate(['project_id' => $project->id, 'parent_id' => $folder?->id, 'name' => $part], ['created_by' => $user->id]);
            }
            $path = $upload->storeAs('project-files/'.$project->id, Str::uuid().'.'.$ext, 'local');
            if (! $path) {
                throw new PlanningRejected('บันทึกไฟล์ไม่สำเร็จ (พื้นที่เต็ม หรือเขียนโฟลเดอร์ไม่ได้)');
            }
            $old = $this->sameName($project, $folder?->id, $name);
            if ($old) {
                $old->forceFill(['is_current' => false])->save();   // the same name again = its next version
            }

            return ProjectFile::create(['project_id' => $project->id, 'folder_id' => $folder?->id, 'name' => $name, 'disk' => 'local', 'path' => $path,
                'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'version' => $old ? $old->version + 1 : 1, 'previous_id' => $old?->id,
                'is_current' => true, 'uploaded_by' => $user->id])->id;
        });

        return $this->fileItem(ProjectFile::with('uploader:id,name')->find($id), $user);
    }

    /** @throws PlanningRejected */
    public function apply(Project $project, User $user, array $op): ?string
    {
        if ($project->isLocked() && ($op['type'] ?? '') !== 'log') {
            throw new PlanningRejected('โครงการปิดแล้ว — แก้ไฟล์ไม่ได้ (ดู / ดาวน์โหลดได้)');
        }

        return DB::transaction(fn () => match ($op['type'] ?? null) {
            'mkdir' => $this->mkdir($project, $user, $op),
            'rename' => $this->rename($project, $user, $op),
            'move' => $this->move($project, $user, $op),
            'delete' => $this->delete($project, $user, $op),
            'restore' => $this->restore($project, $user, $op),
            'purge' => $this->purge($project, $user, $op),
            'restoreVersion' => $this->restoreVersion($project, $user, $op),
            'log' => $this->logUpload($project, $user, $op),
            default => throw new PlanningRejected('ไม่รู้จักคำสั่งนี้'),
        });
    }

    private function mkdir(Project $project, User $user, array $op): string
    {
        $all = $this->folders($project);
        $parent = ! empty($op['parent']) ? ($all->get((int) $op['parent']) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์นี้')) : null;
        if (! $this->canAddTo($project, $parent, $user, $all)) {
            throw new PlanningRejected('คุณสร้างโฟลเดอร์ที่นี่ไม่ได้');
        }
        $name = mb_substr($this->cleanName((string) ($op['name'] ?? '')), 0, 120);
        if ($name === '') {
            throw new PlanningRejected('ตั้งชื่อโฟลเดอร์');
        }
        if ($all->where('parent_id', $parent?->id)->contains(fn ($f) => mb_strtolower($f->name) === mb_strtolower($name))) {
            throw new PlanningRejected("มีโฟลเดอร์ชื่อ \"{$name}\" อยู่แล้ว");
        }
        $folder = ProjectFolder::create(['project_id' => $project->id, 'parent_id' => $parent?->id, 'name' => $name, 'created_by' => $user->id] + $this->visibility($op, $user));
        if ($folder->restricted || $folder->department_ids) {
            ActivityLog::record($project->id, $user->id, $project, 'files', "สร้างโฟลเดอร์ \"{$name}\" — เห็นได้".$this->visibilityText($folder));
        }

        return "สร้างโฟลเดอร์ \"{$name}\" แล้ว";
    }

    private function rename(Project $project, User $user, array $op): string
    {
        $name = $this->cleanName((string) ($op['name'] ?? ''));
        if ($name === '') {
            throw new PlanningRejected('ชื่อต้องไม่ว่าง');
        }
        if (($op['kind'] ?? '') === 'folder') {
            $folder = ProjectFolder::where('project_id', $project->id)->find((int) ($op['id'] ?? 0)) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์นี้');
            if (! $this->canEditFolder($folder, $user)) {
                throw new PlanningRejected($folder->system === 'default' ? 'โฟลเดอร์มาตรฐาน — Admin แก้ชื่อและสิทธิ์ได้ที่ ตั้งค่า → ไฟล์และโฟลเดอร์' : 'เปลี่ยนชื่อโฟลเดอร์นี้ไม่ได้');
            }
            $before = $this->visibilityText($folder);
            $folder->forceFill(['name' => mb_substr($name, 0, 120)] + (array_key_exists('visibility', $op) || array_key_exists('restricted', $op) ? $this->visibility($op, $user) : []))->save();
            if (($after = $this->visibilityText($folder)) !== $before) {
                ActivityLog::record($project->id, $user->id, $project, 'files', "ตั้งโฟลเดอร์ \"{$folder->name}\" ให้เห็นได้".$after);
            }

            return 'บันทึกแล้ว';
        }
        $file = $this->file($project, $op['id'] ?? 0);
        if (! $this->canEditFile($file, $user)) {
            throw new PlanningRejected('เปลี่ยนชื่อได้เฉพาะไฟล์ที่คุณเพิ่มเอง (หรือ Admin / PM)');
        }
        if (pathinfo($name, PATHINFO_EXTENSION) === '' && $file->extension() !== '') {
            $name .= '.'.$file->extension();   // the extension stays, so the file still opens
        }
        if (($other = $this->sameName($project, $file->folder_id, $name)) && $other->id !== $file->id) {
            throw new PlanningRejected("มีไฟล์ชื่อ \"{$name}\" ในโฟลเดอร์นี้แล้ว");
        }
        $file->forceFill(['name' => mb_substr($name, 0, 255)])->save();

        return 'เปลี่ยนชื่อแล้ว';
    }

    private function move(Project $project, User $user, array $op): string
    {
        $all = $this->folders($project);
        $to = ! empty($op['to']) ? ($all->get((int) $op['to']) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์ปลายทาง')) : null;
        if (! $this->canAddTo($project, $to, $user, $all)) {
            throw new PlanningRejected('ย้ายไปโฟลเดอร์นี้ไม่ได้');
        }
        $n = 0;
        foreach ((array) ($op['folders'] ?? []) as $id) {
            $f = $all->get((int) $id) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์');
            if (! $this->canEditFolder($f, $user)) {
                throw new PlanningRejected("ย้ายโฟลเดอร์ \"{$f->name}\" ไม่ได้");
            }
            for ($p = $to; $p; $p = $all->get($p->parent_id)) {
                if ($p->id === $f->id) {
                    throw new PlanningRejected('ย้ายโฟลเดอร์เข้าไปในตัวเองไม่ได้');
                }
            }
            $f->forceFill(['parent_id' => $to?->id])->save();
            $n++;
        }
        foreach ((array) ($op['files'] ?? []) as $id) {
            $file = $this->file($project, $id);
            if (! $this->canEditFile($file, $user)) {
                throw new PlanningRejected("ย้าย \"{$file->name}\" ไม่ได้ — ย้ายได้เฉพาะไฟล์ที่คุณเพิ่มเอง");
            }
            $name = $file->name;
            for ($i = 2; ($other = $this->sameName($project, $to?->id, $name)) && $other->id !== $file->id; $i++) {
                $name = pathinfo($file->name, PATHINFO_FILENAME)." ({$i})".($file->extension() ? '.'.$file->extension() : '');
            }
            $file->forceFill(['folder_id' => $to?->id, 'name' => $name])->save();
            ProjectFile::withTrashed()->whereKey($this->olderIds($file))->update(['folder_id' => $to?->id]);   // its history goes with it
            $n++;
        }

        return "ย้าย {$n} รายการไป \"".($to?->name ?? $project->project_no).'" แล้ว';
    }

    private function delete(Project $project, User $user, array $op): string
    {
        $stamp = now();
        $n = 0;
        $all = $this->folders($project);
        foreach ((array) ($op['folders'] ?? []) as $id) {
            $f = $all->get((int) $id) ?? throw new PlanningRejected('ไม่พบโฟลเดอร์');
            if (! $this->canEditFolder($f, $user)) {
                throw new PlanningRejected("ลบโฟลเดอร์ \"{$f->name}\" ไม่ได้");
            }
            $ids = $this->descendants($f->id, $all);
            ProjectFile::where('project_id', $project->id)->whereIn('folder_id', $ids)->update(['deleted_at' => $stamp, 'deleted_by' => $user->id]);
            ProjectFolder::whereKey($ids)->update(['deleted_at' => $stamp]);
            $n++;
        }
        foreach ((array) ($op['files'] ?? []) as $id) {
            $file = $this->file($project, $id);
            if (! $this->canEditFile($file, $user)) {
                throw new PlanningRejected("ลบ \"{$file->name}\" ไม่ได้ — ลบได้เฉพาะไฟล์ที่คุณเพิ่มเอง");
            }
            ProjectFile::whereKey(array_merge([$file->id], $this->olderIds($file)))->update(['deleted_at' => $stamp, 'deleted_by' => $user->id]);
            $n++;
        }
        ActivityLog::record($project->id, $user->id, $project, 'files', "ย้าย {$n} รายการไปถังขยะ (ไฟล์โครงการ)");

        return "ย้าย {$n} รายการไปถังขยะ — กู้คืนได้ภายใน ".self::TRASH_DAYS.' วัน';
    }

    private function restore(Project $project, User $user, array $op): string
    {
        $live = $this->folders($project);
        $n = 0;
        foreach ((array) ($op['folders'] ?? []) as $id) {
            $f = ProjectFolder::onlyTrashed()->where('project_id', $project->id)->find((int) $id) ?? throw new PlanningRejected('ไม่พบในถังขยะ');
            if (! $this->canEditFolder($f, $user)) {
                throw new PlanningRejected('กู้คืนโฟลเดอร์นี้ไม่ได้');
            }
            $ids = $this->descendants($f->id, $this->folders($project, true));
            ProjectFolder::onlyTrashed()->whereKey($ids)->where('deleted_at', $f->deleted_at)->update(['deleted_at' => null]);
            ProjectFile::onlyTrashed()->whereIn('folder_id', $ids)->where('deleted_at', $f->deleted_at)->update(['deleted_at' => null, 'deleted_by' => null]);
            if ($f->parent_id && ! $live->has($f->parent_id)) {
                $f->forceFill(['parent_id' => null])->save();   // its folder is gone: back at the top level
            }
            $n++;
        }
        foreach ((array) ($op['files'] ?? []) as $id) {
            $file = ProjectFile::onlyTrashed()->where('project_id', $project->id)->find((int) $id) ?? throw new PlanningRejected('ไม่พบในถังขยะ');
            if (! $this->canEditFile($file, $user)) {
                throw new PlanningRejected('กู้คืนได้เฉพาะไฟล์ที่คุณเพิ่มเอง (หรือ Admin / PM)');
            }
            ProjectFile::onlyTrashed()->whereKey(array_merge([$file->id], $this->olderIds($file)))->update(['deleted_at' => null, 'deleted_by' => null]);
            $folderGone = $file->folder_id && ! ProjectFolder::whereKey($file->folder_id)->exists();
            $clash = $this->sameName($project, $folderGone ? null : $file->folder_id, $file->name);
            $file->refresh()->forceFill(['folder_id' => $folderGone ? null : $file->folder_id,
                'name' => $clash && $clash->id !== $file->id ? pathinfo($file->name, PATHINFO_FILENAME).' (กู้คืน).'.$file->extension() : $file->name])->save();
            $n++;
        }

        return "กู้คืน {$n} รายการแล้ว";
    }

    /** Admin / PM: gone for good (the trash also empties itself after TRASH_DAYS days). */
    private function purge(Project $project, User $user, array $op): string
    {
        if (! $this->pmo($user)) {
            throw new PlanningRejected('ลบถาวรได้เฉพาะ Admin / PM');
        }
        $files = ProjectFile::onlyTrashed()->where('project_id', $project->id)->whereKey(array_map('intval', (array) ($op['files'] ?? [])))->get();
        $folderIds = collect((array) ($op['folders'] ?? []))->flatMap(fn ($id) => $this->descendants((int) $id, $this->folders($project, true)));
        $files = $files->concat(ProjectFile::onlyTrashed()->whereIn('folder_id', $folderIds)->get());
        $this->forget($files->flatMap(fn ($f) => array_merge([$f->id], $this->olderIds($f)))->unique()->all());
        ProjectFolder::onlyTrashed()->whereKey($folderIds->all())->forceDelete();

        return 'ลบถาวรแล้ว';
    }

    /** An older version becomes the newest again - as a new version (the history stays complete). */
    private function restoreVersion(Project $project, User $user, array $op): string
    {
        $old = ProjectFile::withTrashed()->where('project_id', $project->id)->find((int) ($op['id'] ?? 0)) ?? throw new PlanningRejected('ไม่พบเวอร์ชันนี้');
        $current = ProjectFile::where('project_id', $project->id)->where('is_current', true)->where('folder_id', $old->folder_id)
            ->get()->first(fn ($f) => in_array($old->id, $this->olderIds($f), true)) ?? throw new PlanningRejected('ไม่พบไฟล์ปัจจุบันของเวอร์ชันนี้');
        if (! $this->canEditFile($current, $user) && ! $this->pmo($user)) {
            throw new PlanningRejected('ใช้เวอร์ชันเก่าได้เฉพาะคนที่เพิ่มไฟล์ (หรือ Admin / PM)');
        }
        $current->forceFill(['is_current' => false])->save();
        ProjectFile::create(['project_id' => $project->id, 'folder_id' => $current->folder_id, 'name' => $current->name, 'disk' => $old->disk, 'path' => $old->path,
            'mime' => $old->mime, 'size' => $old->size, 'version' => $current->version + 1, 'previous_id' => $current->id, 'is_current' => true, 'uploaded_by' => $user->id]);

        return "ใช้เวอร์ชัน {$old->version} เป็นเวอร์ชันล่าสุดแล้ว";
    }

    /** One Activity Log line for a batch of uploads (the page sends it after its last file). */
    private function logUpload(Project $project, User $user, array $op): ?string
    {
        $files = ProjectFile::where('project_id', $project->id)->whereKey(array_map('intval', (array) ($op['ids'] ?? [])))->with('folder:id,name')->get();
        if ($files->isNotEmpty()) {
            ActivityLog::record($project->id, $user->id, $project, 'files', 'อัปโหลดไฟล์ '.$files->count().' ไฟล์'
                .($files->pluck('folder.name')->filter()->unique()->count() === 1 ? ' ใน "'.$files->first()->folder?->name.'"' : '').': '
                .$files->pluck('name')->take(3)->implode(', ').($files->count() > 3 ? ' …' : ''));
        }

        return null;
    }

    // ======================================================================= serving

    public function authorizeView(ProjectFile $file, User $user): void
    {
        $project = Project::withTrashed()->find($file->project_id);
        abort_unless($project && $this->canView($project, $user), 403);
        abort_if($this->hidden($file->folder_id ? ProjectFolder::withTrashed()->find($file->folder_id) : null, $this->folders($project, true), $user), 403);
    }

    /** @param list<int> $fileIds @param list<int> $folderIds -> a .zip in storage/app/private/tmp (the caller sends and deletes it) */
    public function zip(Project $project, User $user, array $fileIds, array $folderIds): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new PlanningRejected('เซิร์ฟเวอร์นี้ไม่มี PHP zip — ดาวน์โหลดทีละไฟล์แทน');
        }
        $all = $this->folders($project);
        $entries = collect();
        foreach ($folderIds as $id) {
            $root = $all->get((int) $id);
            if (! $root || $this->hidden($root, $all, $user)) {
                continue;
            }
            $ids = $this->descendants($root->id, $all);
            foreach (ProjectFile::where('project_id', $project->id)->where('is_current', true)->whereIn('folder_id', $ids)->get() as $f) {
                if (! $this->hidden($all->get($f->folder_id), $all, $user)) {
                    $rel = collect($this->pathOf($f->folder_id, $all))->skipUntil(fn ($p) => $p['id'] === $root->id)->pluck('name')->implode('/');
                    $entries->push([$f, $rel.'/'.$f->name]);
                }
            }
        }
        foreach (ProjectFile::where('project_id', $project->id)->where('is_current', true)->whereKey($fileIds)->get() as $f) {
            if (! $this->hidden($all->get($f->folder_id), $all, $user)) {
                $entries->push([$f, $f->name]);
            }
        }
        if ($entries->isEmpty()) {
            throw new PlanningRejected('ไม่มีไฟล์ให้ดาวน์โหลด');
        }
        if ($entries->sum(fn ($e) => $e[0]->size) > 500 * 1048576) {
            throw new PlanningRejected('รวมกันเกิน 500 MB — เลือกน้อยลง');
        }
        \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory('tmp');
        $zipPath = \Illuminate\Support\Facades\Storage::disk('local')->path('tmp/'.Str::uuid().'.zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $used = [];
        foreach ($entries as [$f, $name]) {
            for ($i = 2, $n = $name; isset($used[mb_strtolower($n)]); $i++) {
                $n = preg_replace('/(\.[^.\/]+)?$/u', " ({$i})$1", $name, 1);
            }
            $used[mb_strtolower($n)] = true;
            if (is_file($f->absolutePath())) {
                $zip->addFile($f->absolutePath(), $n);
            }
        }
        $zip->close();

        return $zipPath;
    }

    /** Daily: what has been in the trash longer than TRASH_DAYS goes for good. */
    public function purgeExpired(): int
    {
        $before = now()->subDays(self::TRASH_DAYS);
        $ids = ProjectFile::onlyTrashed()->where('deleted_at', '<', $before)->pluck('id')->all();
        $this->forget($ids);
        ProjectFolder::onlyTrashed()->where('deleted_at', '<', $before)->forceDelete();

        return count($ids);
    }

    // ======================================================================= helpers

    /** Default folders in the order Settings lists them, then people's own folders A-Z, the cabinets' container last (cabinets by id). */
    private function order(ProjectFolder $f): string
    {
        return match ($f->system) {
            'default' => sprintf('0-%03d-%09d', ($this->standardOrder ??= array_flip(array_map(fn ($r) => mb_strtolower($r['name']), self::standardFolders())))[mb_strtolower($f->name)] ?? 999, $f->id),
            'cabinets' => '2',
            'cabinet' => sprintf('1-%09d', $f->id),
            default => '1-'.mb_strtolower($f->name),
        };
    }

    private function file(Project $project, mixed $id): ProjectFile
    {
        return ProjectFile::where('project_id', $project->id)->where('is_current', true)->find((int) $id) ?? throw new PlanningRejected('ไม่พบไฟล์นี้ (อาจถูกลบไปแล้ว)');
    }

    private function sameName(Project $project, ?int $folderId, string $name): ?ProjectFile
    {
        return ProjectFile::where('project_id', $project->id)->where('is_current', true)->where('folder_id', $folderId)->get(['id', 'name', 'version', 'folder_id'])
            ->first(fn ($f) => mb_strtolower($f->name) === mb_strtolower($name));
    }

    /** @return list<int> the older versions behind a file */
    private function olderIds(ProjectFile $file): array
    {
        $ids = [];
        for ($id = $file->previous_id; $id && ! in_array($id, $ids, true); $id = ProjectFile::withTrashed()->whereKey($id)->value('previous_id')) {
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return list<int> a folder and everything under it */
    private function descendants(int $id, Collection $all): array
    {
        $ids = [$id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all->where('parent_id', $ids[$i]) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    /** Rows gone for good, and their bytes - unless another row (a restored version) still points at the same file. */
    private function forget(array $ids): void
    {
        $rows = ProjectFile::withTrashed()->whereKey($ids)->get();
        ProjectFile::withTrashed()->whereKey($ids)->forceDelete();
        foreach ($rows as $f) {
            if (! ProjectFile::withTrashed()->where('disk', $f->disk)->where('path', $f->path)->exists() && is_file($f->absolutePath())) {
                @unlink($f->absolutePath());
            }
        }
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name);

        return trim(preg_replace('/\s+/u', ' ', (string) $name), " .\t");
    }

    private function phpLimitMb(): int
    {
        $bytes = function ($v): int {
            $v = trim((string) $v);
            $unit = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($v, -1))] ?? 1;

            return (int) $v * $unit;
        };
        $limits = array_filter([$bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size'))]);

        return $limits ? (int) floor(min($limits) / 1048576) : self::MAX_MB;
    }
}
