<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One version of one file of a project (App\Services\ProjectFiles). */
class ProjectFile extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'folder_id', 'cabinet_id', 'name', 'disk', 'path', 'mime', 'size', 'version', 'previous_id', 'is_current', 'uploaded_by', 'deleted_by'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'size' => 'integer', 'version' => 'integer'];
    }

    public function folder()
    {
        return $this->belongsTo(ProjectFolder::class, 'folder_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    /** Where the bytes are: our private storage, or (older uploads) under public/. */
    public function absolutePath(): string
    {
        return $this->disk === 'public' ? public_path($this->path) : \Illuminate\Support\Facades\Storage::disk('local')->path($this->path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /** Opened in the browser (not downloaded): pictures and PDF only - never HTML / SVG, which could run scripts. */
    public function previewable(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'], true);
    }
}
