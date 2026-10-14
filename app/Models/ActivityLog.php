<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'project_id',
        'user_id',
        'loggable_type',
        'loggable_id',
        'action',
        'description',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /** Changed fields whose values only some people may see (Settings > สิทธิ์การใช้งาน), by their label in the log. */
    public const GUARDED_LABELS = ['data.finance' => ['มูลค่างาน', 'Margin'], 'data.contact' => ['เบอร์ติดต่อ']];

    /** The labels this person may not see the values of. */
    public static function hiddenLabelsFor(?User $user): array
    {
        return collect(self::GUARDED_LABELS)->reject(fn ($labels, $perm) => $user?->allows($perm))->flatten()->all();
    }

    /** $changes with the values this person may not see as "•••" (and whether any were). */
    public function changesFor(?User $user): array
    {
        $hidden = self::hiddenLabelsFor($user);

        return collect($this->getAttribute('changes') ?? [])->map(fn ($c) => in_array($c['label'] ?? '', $hidden, true) ? ['old' => '•••', 'new' => '•••'] + $c : $c)->values()->all();
    }

    /** The sentence, rebuilt from the masked changes when it carried a value this person may not see. */
    public function descriptionFor(?User $user): string
    {
        $hidden = self::hiddenLabelsFor($user);
        if (! $hidden || ! collect($this->getAttribute('changes') ?? [])->contains(fn ($c) => in_array($c['label'] ?? '', $hidden, true))) {
            return (string) $this->description;
        }

        return (string) preg_replace('/:.*$/su', '', (string) $this->description).': '
            .collect($this->changesFor($user))->map(fn ($c) => "{$c['label']} จาก \"{$c['old']}\" เป็น \"{$c['new']}\"")->implode(', ');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function loggable()
    {
        return $this->morphTo();
    }

    // Files attached to this entry when it's a Comment (action='note') - see
    // CommentAttachmentUploader. loggable_type/loggable_id above identify what
    // the comment is ABOUT (the Project/Cabinet); this is a separate relation
    // for files attached to the comment itself.
    public function attachments()
    {
        return $this->hasMany(CommentAttachment::class);
    }

    public static function record(?int $projectId, ?int $userId, Model $loggable, string $action, string $description, array $changes = []): ?self
    {
        if ($projectId === null) {
            return null;
        }

        return static::create([
            'project_id' => $projectId,
            'user_id' => $userId,
            'loggable_type' => $loggable::class,
            'loggable_id' => $loggable->getKey(),
            'action' => $action,
            'description' => $description,
            'changes' => $changes ?: null,
        ]);
    }
}
