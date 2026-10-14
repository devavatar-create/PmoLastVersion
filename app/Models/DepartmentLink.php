<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings > การส่งต่องาน: one department's checklist is passed on (read only + notices) to another, e.g. จัดซื้อ → คลัง. */
class DepartmentLink extends Model
{
    protected $fillable = ['from_department_id', 'to_department_id', 'date_label'];

    public function from()
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function to()
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }
}
