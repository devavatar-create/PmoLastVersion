<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one place a Project's editable header fields are validated and saved - used by the My Department project modal
 * (autosave) and by the /planning page. Callers decide WHO may edit (admin / PM); this decides WHAT is valid.
 */
class ProjectEditor
{
    public const RULES = [
        'project_name' => ['sometimes', 'required', 'string', 'max:255'],
        'status' => ['sometimes', 'required', 'in:draft,planning,in_progress,on_hold,completed,cancelled'],
        'priority' => ['sometimes', 'required', 'in:low,normal,high,urgent'],
        'start_date' => ['sometimes', 'nullable', 'date'],
        'due_date' => ['sometimes', 'nullable', 'date'],
        'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        // the commercial / ownership fields (same rules as the project form - ProjectController::validateData)
        'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
        'customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        'contract_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
        'margin' => ['sometimes', 'nullable', 'numeric', 'max:9999999999999'],
        'po_no' => ['sometimes', 'nullable', 'string', 'max:100'],
        'sales_order_no' => ['sometimes', 'nullable', 'string', 'max:100'],
        'project_owner' => ['sometimes', 'nullable', 'string', 'max:255'],
        'sales_person' => ['sometimes', 'nullable', 'string', 'max:255'],
        'project_manager_id' => ['sometimes', 'nullable', 'exists:users,id'],
        'payment_terms' => ['sometimes', 'nullable', 'string', 'max:255'],
        'job_type_id' => ['sometimes', 'nullable', 'exists:job_types,id'],
    ];

    /** @throws ValidationException */
    public function update(Project $project, array $input): Project
    {
        $input = array_intersect_key($input, self::RULES);
        foreach (['contract_value', 'margin'] as $money) {   // "1,250,000" as typed
            if (array_key_exists($money, $input)) {
                $input[$money] = Project::cleanAmount($input[$money]);
            }
        }
        $rules = self::RULES;
        // a new Project Manager must be Admin / PM (one already on the project before this rule may stay)
        $rules['project_manager_id'][] = function (string $attr, mixed $value, \Closure $fail) use ($project) {
            if ($value && (int) $value !== $project->project_manager_id && ! \App\Models\User::whereKey($value)->whereIn('role', Project::MANAGER_ROLES)->exists()) {
                $fail('ผู้จัดการโครงการต้องเป็น Admin หรือ Project Manager');
            }
        };
        $data = Validator::make($input, $rules, ['contract_value.numeric' => 'มูลค่างานต้องเป็นตัวเลข', 'margin.numeric' => 'Margin ต้องเป็นตัวเลข'])->validate();

        // the date order is judged on the values the Project will have AFTER this save
        $start = array_key_exists('start_date', $data) ? $data['start_date'] : $project->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $data) ? $data['due_date'] : $project->due_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องไม่ก่อนวันเริ่ม']);
        }

        $project->update($data);

        return $project;
    }
}
