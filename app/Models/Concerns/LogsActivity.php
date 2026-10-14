<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Writes a row to activity_logs on create/update/delete. A model opts in
 * with `use LogsActivity` and overrides activityProjectId()/activityLabel()/
 * activityLoggableFields() (see Project, Cabinet, CabinetTask for examples).
 *
 * Only fields listed in activityLoggableFields() are diffed on update -
 * fields that ProgressService recalculates automatically (progress, status)
 * are deliberately left out on the Cabinet/CabinetTask/CabinetSubtask side,
 * otherwise every checklist toggle would spam the timeline with derived
 * roll-up noise instead of the one meaningful "checked/unchecked" entry
 * (see ProgressService::toggleChecklist, which logs that explicitly).
 *
 * A caller that wants to replace the automatic message with a friendlier
 * custom one (see CabinetTaskController::extend) can suppress it for one
 * save via Model::withoutEvents(fn () => $model->update([...])).
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->writeActivityLog('created', $model->activityDescription('created'));
        });

        static::updated(function ($model) {
            $changes = $model->loggableChanges();

            if (empty($changes)) {
                return;
            }

            $model->writeActivityLog('updated', $model->activityDescription('updated', $changes), $changes);
        });

        static::deleted(function ($model) {
            $model->writeActivityLog('deleted', $model->activityDescription('deleted'));
        });

        // Soft-deletable models (see the trash) log the way back too.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model) {
                $model->writeActivityLog('restored', $model->activityDescription('restored'));
            });
        }
    }

    protected function writeActivityLog(string $action, string $description, array $changes = []): void
    {
        ActivityLog::record($this->activityProjectId(), auth()->id(), $this, $action, $description, $changes);
    }

    protected function loggableChanges(): array
    {
        $changes = [];

        foreach ($this->activityLoggableFields() as $field => $label) {
            if (! $this->wasChanged($field)) {
                continue;
            }

            $old = $this->activityFormatValue($field, $this->getOriginal($field));
            $new = $this->activityFormatValue($field, $this->getAttribute($field));

            // Eloquent's dirty-tracking can false-positive on raw type/format
            // mismatches (e.g. a decimal column re-saved as "35" vs "35.00")
            // even when nothing meaningful changed - skip those here too.
            if ($old === $new) {
                continue;
            }

            $changes[$field] = ['label' => $label, 'old' => $old, 'new' => $new];
        }

        return $changes;
    }

    protected function activityDescription(string $action, array $changes = []): string
    {
        $label = $this->activityLabel();

        return match ($action) {
            'created' => "สร้าง{$label}",
            'deleted' => "ลบ{$label}",
            'restored' => "กู้คืน{$label}",
            default => "แก้ไข{$label}: ".collect($changes)
                ->map(fn ($c) => "{$c['label']} จาก \"{$c['old']}\" เป็น \"{$c['new']}\"")
                ->implode(', '),
        };
    }

    /**
     * Fields to diff on update, as [column => Thai label]. Empty by default
     * (no automatic update logging) - override per model to opt fields in.
     */
    protected function activityLoggableFields(): array
    {
        return [];
    }

    protected function activityFormatValue(string $field, $value): string
    {
        return $this->activityDefaultFormatValue($value);
    }

    protected function activityDefaultFormatValue($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof Carbon) {
            return $value->thaiDate();
        }

        return (string) $value;
    }

    /**
     * The project this record rolls up to, for denormalized filtering.
     * Return null to skip logging entirely.
     */
    protected function activityProjectId(): ?int
    {
        return null;
    }

    /**
     * Human label identifying this record in a log line, e.g. `ตู้ MO-690001`.
     */
    protected function activityLabel(): string
    {
        return class_basename($this);
    }
}
