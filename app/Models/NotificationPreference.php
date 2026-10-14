<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'enabled', 'only_my_department', 'progress', 'completed', 'reminders', 'comments', 'due_changes'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'only_my_department' => 'boolean', 'progress' => 'boolean',
            'completed' => 'boolean', 'reminders' => 'boolean', 'comments' => 'boolean', 'due_changes' => 'boolean',
        ];
    }

    /** The values a person has when they never opened the page. */
    public const DEFAULTS = ['enabled' => true, 'only_my_department' => false, 'progress' => true, 'completed' => true, 'reminders' => true, 'comments' => true, 'due_changes' => true];

    public static function for(User $user): self
    {
        return self::where('user_id', $user->id)->first() ?? new self(['user_id' => $user->id] + self::DEFAULTS);
    }
}
