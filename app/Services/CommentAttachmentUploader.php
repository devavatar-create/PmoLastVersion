<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CommentAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Shared by ProjectController::storeNote() and CabinetController::storeNote()
 * (the Project and Cabinet Comment tabs both post through the same
 * ActivityLog 'note' flow): public/uploads, randomized filename, defensive
 * .htaccess, mime allowlist. Comments (chat) are the only place files are
 * uploaded to.
 */
class CommentAttachmentUploader
{
    private const UPLOAD_DIR = 'uploads/comment-attachments';

    public const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,dwg,dxf';

    public function store(ActivityLog $log, UploadedFile $file, ?int $userId): CommentAttachment
    {
        $destination = public_path(self::UPLOAD_DIR);

        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $htaccess = $destination.'/.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
        }

        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $size = $file->getSize();
        $storedName = Str::random(40).'.'.$file->getClientOriginalExtension();

        $file->move($destination, $storedName);

        return $log->attachments()->create([
            'original_name' => $originalName,
            'path' => $storedName,
            'mime_type' => $mimeType,
            'size' => $size,
            'uploaded_by' => $userId,
        ]);
    }
}
