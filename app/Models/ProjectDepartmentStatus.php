<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin / PM's own status for one department's work in a project, over the one worked out from that work. */
class ProjectDepartmentStatus extends Model
{
    protected $fillable = ['project_id', 'department_id', 'board_status_id'];

    public function boardStatus()
    {
        return $this->belongsTo(BoardStatus::class);
    }
}
