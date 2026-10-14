<?php

namespace App\Http\Controllers;

use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\ProjectFiles;
use Illuminate\Http\Request;

/** The project file manager (x-file-manager): JSON for the page, and the files themselves (always through a permission check). */
class ProjectFilesController extends Controller
{
    /** A fresh one per call: it remembers who sees which cabinet only while it works (a route keeps its controller between requests). */
    private function files(): ProjectFiles
    {
        return app(ProjectFiles::class);
    }

    private function see(Project $project, Request $request): void
    {
        abort_unless($this->files()->canView($project, $request->user()), 403);
    }

    private function json(\Closure $work)
    {
        try {
            return response()->json(['ok' => true] + (array) $work());
        } catch (PlanningRejected $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (ProjectLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 423);
        }
    }

    public function tree(Request $request, Project $project)
    {
        $this->see($project, $request);

        return $this->json(fn () => $this->files()->tree($project, $request->user()));
    }

    public function index(Request $request, Project $project)
    {
        $this->see($project, $request);

        return $this->json(fn () => $this->files()->listing($project, $request->user(), (string) $request->query('in', ''), (string) $request->query('q', '')));
    }

    public function upload(Request $request, Project $project)
    {
        $this->see($project, $request);
        $request->validate(
            ['file' => ['required', 'file', 'max:'.(ProjectFiles::MAX_MB * 1024)], 'folder' => ['nullable', 'integer'], 'path' => ['nullable', 'string', 'max:1000']],
            ['file.max' => 'ไฟล์ใหญ่เกิน '.ProjectFiles::MAX_MB.' MB', 'file.uploaded' => 'อัปโหลดไม่สำเร็จ — ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้']
        );

        return $this->json(fn () => ['item' => $this->files()->upload($project, $request->user(), $request->integer('folder') ?: null, $request->file('file'), (string) $request->input('path', ''))]);
    }

    public function ops(Request $request, Project $project)
    {
        $this->see($project, $request);

        return $this->json(fn () => ['message' => $this->files()->apply($project, $request->user(), $request->all())]);
    }

    public function versions(Request $request, ProjectFile $file)
    {
        return response()->json(['versions' => $this->files()->versions($file, $request->user())]);
    }

    /** One file: opened in the browser (pictures / PDF, ?inline=1) or downloaded under its own name. */
    public function download(Request $request, ProjectFile $file)
    {
        $this->files()->authorizeView($file, $request->user());
        abort_unless(is_file($file->absolutePath()), 404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('inline') && $file->previewable()
            ? response()->file($file->absolutePath(), $headers + ['Content-Type' => $file->mime ?: 'application/octet-stream',
                'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($file->name)])
            : response()->download($file->absolutePath(), $file->name, $headers);
    }

    public function zip(Request $request, Project $project)
    {
        $this->see($project, $request);
        try {
            $path = $this->files()->zip($project, $request->user(), array_map('intval', (array) $request->query('files', [])), array_map('intval', (array) $request->query('folders', [])));
        } catch (PlanningRejected $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->download($path, $project->project_no.'-ไฟล์.zip')->deleteFileAfterSend();
    }
}
