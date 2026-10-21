<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use Illuminate\Database\Eloquent\Model;

/**
 * Copies an existing Cabinet's Task/SubTask/Checklist tree into another
 * (freshly created, empty) Cabinet - for cabinets that share the same
 * production structure (and, usually, the same schedule) as one already
 * built out/tweaked, without going back through a CabinetTemplate.
 * Start/due dates carry over (the new cabinet is expected to run on the same
 * schedule); progress/status/completion dates and the assignment workflow
 * (accepted/started/completed) reset, since the copy hasn't actually done
 * any of this work yet.
 */
class CabinetCopyService
{
    public function copyFrom(Cabinet $source, Cabinet $target): void
    {
        $counts = ['tasks' => 0, 'subtasks' => 0, 'checklists' => 0, 'assigned' => 0];
        $made = [];   // source sub task id => the copy's, for the pairs that compare checklists

        // Suppressed: logging every copied row would bury the timeline in
        // boilerplate noise. One consolidated entry below instead.
        Model::withoutEvents(function () use ($source, $target, &$counts, &$made) {
            foreach ($source->tasks as $sourceTask) {
                $task = $target->tasks()->create([
                    'cabinet_task_template_id' => $sourceTask->cabinet_task_template_id,
                    'name' => $sourceTask->name,
                    'description' => $sourceTask->description,
                    'weight' => $sourceTask->weight,
                    'sequence' => $sourceTask->sequence,
                    'start_date' => $sourceTask->start_date,
                    'due_date' => $sourceTask->due_date,
                    // completed_date is deliberately left out - the copy hasn't done any of this work yet.
                    'status' => 'not_started',
                    'progress' => 0,
                ]);
                $counts['tasks']++;

                foreach ($sourceTask->subtasks as $sourceSubtask) {
                    $subtask = $task->subtasks()->create([
                        'cabinet_subtask_template_id' => $sourceSubtask->cabinet_subtask_template_id,
                        'name' => $sourceSubtask->name,
                        'description' => $sourceSubtask->description,
                        'sequence' => $sourceSubtask->sequence,
                        'start_date' => $sourceSubtask->start_date,
                        'due_date' => $sourceSubtask->due_date,
                        // completed_date is deliberately left out - the copy hasn't done any of this work yet.
                        'status' => 'not_started',
                        'progress' => 0,
                    ] + CabinetSubtask::initialAssignment($sourceSubtask->department_id));
                    $made[$sourceSubtask->id] = $subtask->id;
                    $counts['subtasks']++;
                    $counts['assigned'] += $subtask->department_id ? 1 : 0;

                    foreach ($sourceSubtask->checklists as $sourceChecklist) {
                        $subtask->checklists()->create([
                            'checklist_template_id' => $sourceChecklist->checklist_template_id,
                            'name' => $sourceChecklist->name,
                            'description' => $sourceChecklist->description,
                            'sequence' => $sourceChecklist->sequence,
                            'is_completed' => false,
                        ]);
                        $counts['checklists']++;
                    }
                }
            }
        });

        app(SubtaskPairs::class)->copyFromCabinet($made);

        ActivityLog::record(
            $target->project_id,
            auth()->id(),
            $target,
            'copied_from_cabinet',
            "คัดลอกตู้ {$target->mo_no} จากตู้ {$source->mo_no} ({$counts['tasks']} Tasks, {$counts['subtasks']} Sub Tasks, {$counts['checklists']} Checklists, จ่ายงานให้แผนกแล้ว {$counts['assigned']} Sub Tasks)"
        );
    }
}
