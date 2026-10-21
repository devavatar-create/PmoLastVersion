<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\User;
use App\Notifications\PmoNotice;
use Carbon\Carbon;

/**
 * Data behind the "รายงานสถานะงาน" popup (opened once a day, or on demand from the
 * header) and the urgent-alert list shown ahead of it.
 *
 * Scope comes from DepartmentDashboard::scopeFor(), so a department user can only
 * ever get their own department's work in here.
 */
class StatusReport
{
    /** Notice kinds that are "urgent" enough to interrupt: new work for the department, overdue work, and a comment sent as an alert to the department. */
    public const URGENT_KINDS = ['assigned', 'overdue', 'alert', 'mention'];

    /** A Sub Task due within this many days (inclusive of today) counts as "due soon". */
    public const SOON_DAYS = 3;

    public function __construct(private DepartmentDashboard $dashboard) {}

    public function build(User $user, ?Carbon $today = null): array
    {
        $today ??= today();
        $scope = DepartmentDashboard::scopeFor($user);
        $totals = $this->dashboard->build($scope, $today)['totals'];

        return [
            'date' => $today->format('Y-m-d'),
            'totals' => [
                'pending' => $totals['total'] - $totals['completed'],
                'completed' => $totals['completed'],
                'overdue' => $totals['overdue'],
                'checklist_pct' => $totals['checklist_pct'],
                'checklist_done' => $totals['checklist_done'],
                'checklist_total' => $totals['checklist_total'],
            ],
            'items' => $this->attention($scope, $today),
            'alerts' => $this->alerts($user),
        ];
    }

    /**
     * Overdue first (most overdue on top), then due soon (soonest first). Capped so the popup stays
     * a glance, not a report - the full list is one click away on My Department.
     */
    private function attention(?array $scope, Carbon $today, int $limit = 12): array
    {
        if ($scope === []) {
            return [];
        }

        return CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNotNull('due_date')
            ->whereIn('assignment_status', [
                CabinetSubtask::ASSIGNMENT_ASSIGNED,
                CabinetSubtask::ASSIGNMENT_ACCEPTED,
                CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
            ])
            ->notDone()
            ->whereDate('due_date', '<=', $today->copy()->addDays(self::SOON_DAYS))
            ->when($scope !== null, fn ($q) => $q->whereIn('department_id', $scope))
            ->with('cabinetTask.cabinet.project', 'department')
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->map(function (CabinetSubtask $s) use ($today) {
                $days = (int) $today->diffInDays($s->due_date, false);

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'project_no' => $s->cabinetTask->cabinet->project->project_no,
                    'cabinet_mo' => $s->cabinetTask->cabinet->mo_no,
                    'department_name' => $s->department?->name,
                    'due_date' => $s->due_date->format('Y-m-d'),
                    'days_remaining' => $days,
                    'overdue' => $days < 0,
                ];
            })
            ->values()
            ->all();
    }

    /** The viewer's own unread urgent notices (newest first). */
    private function alerts(User $user, int $limit = 10): array
    {
        return $user->unreadNotifications()
            ->where('type', PmoNotice::class)
            ->latest()
            ->limit(50)
            ->get()
            ->filter(fn ($n) => ($n->data['urgent'] ?? false) || in_array($n->data['kind'] ?? null, self::URGENT_KINDS, true))
            ->take($limit)
            ->map(fn ($n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'],
                'title' => $n->data['title'],
                'body' => $n->data['body'],
                'url' => $n->data['url'],
            ])
            ->values()
            ->all();
    }
}
