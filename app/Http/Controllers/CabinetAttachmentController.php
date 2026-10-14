<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\ProjectLock;
use App\Models\Cabinet;
use App\Models\CabinetAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CabinetAttachmentController extends Controller
{
    /**
     * Files are moved straight into public/uploads/cabinet-attachments -
     * deliberately not using the `public` disk/Storage facade, since that
     * needs `storage:link` (a symlink) that isn't reliably creatable on
     * shared hosting without shell access. Filenames on disk are randomized;
     * the user-facing name is kept only in the DB record.
     */
    private const UPLOAD_DIR = 'uploads/cabinet-attachments';

    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,dwg,dxf';

    public function store(Request $request, Cabinet $cabinet)
    {
        ProjectLock::assertOpen($cabinet->project_id);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:'.self::ALLOWED_MIMES],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $data['file'];
        $destination = public_path(self::UPLOAD_DIR);

        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $htaccess = $destination.'/.htaccess';
        if (! file_exists($htaccess)) {
            // Defense in depth: even though filenames are randomized and
            // mime-validated, this folder should never execute anything.
            file_put_contents($htaccess, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
        }

        // Capture metadata before move() - it relocates the underlying temp
        // file, after which the UploadedFile object can no longer stat it.
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $size = $file->getSize();
        $storedName = Str::random(40).'.'.$file->getClientOriginalExtension();

        $file->move($destination, $storedName);

        $attachment = $cabinet->attachments()->create([
            'original_name' => $originalName,
            'path' => $storedName,
            'mime_type' => $mimeType,
            'size' => $size,
            'description' => $data['description'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);

        ActivityLog::record(
            $cabinet->project_id,
            auth()->id(),
            $cabinet,
            'attachment_uploaded',
            "อัปโหลดไฟล์ \"{$attachment->original_name}\" ให้ตู้ {$cabinet->mo_no}".($attachment->description ? " ({$attachment->description})" : '')
        );

        // Cabinet Quick Detail Panel's attachments tab uploads via fetch() (no
        // Project page reload) - everything above is unchanged, this only adds
        // a JSON reply alongside the existing redirect-back form behavior.
        if ($request->wantsJson()) {
            return response()->json(['message' => 'อัปโหลดไฟล์เรียบร้อยแล้ว', 'attachment' => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'description' => $attachment->description,
                'size_for_humans' => $attachment->size_for_humans,
                'url' => $attachment->url,
                'uploaded_by_name' => auth()->user()?->name,
                'created_at' => $attachment->created_at->thaiDateTime(),
                'destroy_url' => route('cabinet-attachments.destroy', $attachment),
            ]]);
        }

        return back()->with('success', 'อัปโหลดไฟล์เรียบร้อยแล้ว');
    }

    public function destroy(Request $request, CabinetAttachment $attachment)
    {
        $cabinet = $attachment->cabinet;
        ProjectLock::assertOpen($cabinet->project_id);
        $filePath = public_path(self::UPLOAD_DIR.'/'.$attachment->path);

        if (is_file($filePath)) {
            unlink($filePath);
        }

        $attachment->delete();

        ActivityLog::record(
            $cabinet->project_id,
            auth()->id(),
            $cabinet,
            'attachment_deleted',
            "ลบไฟล์ \"{$attachment->original_name}\" จากตู้ {$cabinet->mo_no}"
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => 'ลบไฟล์เรียบร้อยแล้ว']);
        }

        return back()->with('success', 'ลบไฟล์เรียบร้อยแล้ว');
    }
}
