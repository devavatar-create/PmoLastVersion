<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetSubtaskTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_task_template_id',
        'name',
        'description',
        'department_id',
        'sequence',
    ];

    protected function casts(): array
    {
        // See the identical comment in CabinetSubtask::casts() - without this,
        // some servers' PDO/MySQL driver settings return this column as a
        // string, breaking the strict `===` comparison the department
        // dropdown (@selected()) relies on in _subtask.blade.php.
        return [
            'department_id' => 'integer',
        ];
    }

    public function taskTemplate()
    {
        return $this->belongsTo(CabinetTaskTemplate::class, 'cabinet_task_template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /** The Sub Tasks whose checklist this one is shown next to, to compare (copied to every cabinet made from the template). */
    public function pairSources()
    {
        return $this->belongsToMany(self::class, 'cabinet_subtask_template_pairs', 'cabinet_subtask_template_id', 'source_subtask_template_id')->withTimestamps();
    }

    /** The Sub Tasks that compare with this one. */
    public function pairReceivers()
    {
        return $this->belongsToMany(self::class, 'cabinet_subtask_template_pairs', 'source_subtask_template_id', 'cabinet_subtask_template_id');
    }

    public function checklistTemplates()
    {
        return $this->hasMany(ChecklistTemplate::class)->orderBy('sequence');
    }
}
