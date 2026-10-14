<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A folder of a project's files (App\Services\ProjectFiles). */
class ProjectFolder extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'parent_id', 'cabinet_id', 'name', 'system', 'restricted', 'department_ids', 'created_by'];

    protected function casts(): array
    {
        return ['restricted' => 'boolean', 'department_ids' => 'array'];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files()
    {
        return $this->hasMany(ProjectFile::class, 'folder_id');
    }
}
