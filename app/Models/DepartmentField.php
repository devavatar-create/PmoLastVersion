<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentField extends Model
{
    public const TYPES = ['select' => 'ตัวเลือก (dropdown)', 'text' => 'กล่องข้อความ', 'link' => 'ลิงก์'];

    protected $fillable = ['department_id', 'name', 'type', 'show_on_chip', 'sort'];

    protected function casts(): array
    {
        return ['show_on_chip' => 'boolean'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function options()
    {
        return $this->hasMany(DepartmentFieldOption::class)->orderBy('sort')->orderBy('id');
    }
}
