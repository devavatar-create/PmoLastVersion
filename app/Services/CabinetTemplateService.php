<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Copies a CabinetTemplate's Task/SubTask/Checklist definitions into a
 * cabinet's own real records. After the copy, the cabinet owns its data
 * completely - editing a cabinet's tasks never touches the master template.
 */
class CabinetTemplateService
{
    public function applyToCabinet(Cabinet $cabinet, CabinetTemplate $template): void
    {
        $cabinet->cabinet_template_id = $template->id;
        $cabinet->save();

        $counts = ['tasks' => 0, 'subtasks' => 0, 'checklists' => 0, 'assigned' => 0];
        $made = [];   // template sub task id => the cabinet's, for the pairs that compare checklists

        // Suppressed: logging every copied row would bury the timeline in
        // template-boilerplate noise. One consolidated entry below instead.
        Model::withoutEvents(function () use ($cabinet, $template, &$counts, &$made) {
            foreach ($template->taskTemplates as $taskTemplate) {
                $task = $cabinet->tasks()->create([
                    'cabinet_task_template_id' => $taskTemplate->id,
                    'name' => $taskTemplate->name,
                    'description' => $taskTemplate->description,
                    'weight' => $taskTemplate->weight,
                    'sequence' => $taskTemplate->sequence,
                    'status' => 'not_started',
                    'progress' => 0,
                ]);
                $counts['tasks']++;

                foreach ($taskTemplate->subtaskTemplates as $subtaskTemplate) {
                    $subtask = $task->subtasks()->create([
                        'cabinet_subtask_template_id' => $subtaskTemplate->id,
                        'name' => $subtaskTemplate->name,
                        'description' => $subtaskTemplate->description,
                        'sequence' => $subtaskTemplate->sequence,
                        'status' => 'not_started',
                        'progress' => 0,
                    ] + CabinetSubtask::initialAssignment($subtaskTemplate->department_id));
                    $made[$subtaskTemplate->id] = $subtask->id;
                    $counts['subtasks']++;
                    $counts['assigned'] += $subtask->department_id ? 1 : 0;

                    foreach ($subtaskTemplate->checklistTemplates as $checklistTemplate) {
                        $subtask->checklists()->create([
                            'checklist_template_id' => $checklistTemplate->id,
                            'name' => $checklistTemplate->name,
                            'description' => $checklistTemplate->description,
                            'sequence' => $checklistTemplate->sequence,
                            'is_completed' => false,
                        ]);
                        $counts['checklists']++;
                    }
                }
            }
        });

        app(SubtaskPairs::class)->copyFromTemplate($made);

        ActivityLog::record(
            $cabinet->project_id,
            auth()->id(),
            $cabinet,
            'template_applied',
            "สร้างโครงสร้างงานให้ตู้ {$cabinet->mo_no} จากเทมเพลต \"{$template->name}\" ({$counts['tasks']} Tasks, {$counts['subtasks']} Sub Tasks, {$counts['checklists']} Checklists, จ่ายงานให้แผนกแล้ว {$counts['assigned']} Sub Tasks)"
        );
    }
}
