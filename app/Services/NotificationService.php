<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PmoNotice;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Who hears about what:
 *  - work handed to a department      -> that department's active users
 *  - department accepts/starts/finishes -> the project's Project Manager
 *  - due soon / overdue (daily job)   -> the department (+ PM when overdue)
 * The person who did the action is never notified about it.
 */
class NotificationService
{
    /** Work handed out while batch() runs: one notice per department and project at the end, not one per sub task. */
    private static ?array $batch = null;   // static: every NotificationService instance in this request shares it

    /**
     * Runs $fn and sends the "work handed to your department" notices it caused as ONE notice per department / project
     * ("มีงานใหม่ 10 งาน ใน SO…"), instead of one per sub task. Used around a Planning save (PlanningSync::apply).
     */
    public function batch(callable $fn): mixed
    {
        if (self::$batch !== null) {
            return $fn();   // already inside one
        }
        self::$batch = [];
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::$batch = null;   // refused / failed: nothing it queued is sent
            throw $e;
        }
        $queued = self::$batch;
        self::$batch = null;
        foreach (collect($queued)->groupBy(fn ($q) => $q[0]->department_id.'|'.$q[0]->cabinetTask->cabinet->project_id.'|'.(int) $q[2]) as $group) {
            if ($group->count() === 1) {
                $this->assigned(...$group->first());
                continue;
            }
            [$first, $actor, $moved] = $group->first();
            $project = $first->cabinetTask->cabinet->project;
            $dues = $group->map(fn ($q) => $q[0]->fresh()?->due_date)->filter();
            $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'assigned',
                ($moved ? 'งานถูกย้ายมาที่แผนกของคุณ ' : 'มีงานใหม่ให้แผนกของคุณ ').$group->count().' งาน',
                "{$project->project_no} {$project->project_name}".($dues->isNotEmpty() ? ' · กำหนดส่ง '.$dues->min()->thaiDate().($dues->min()->ne($dues->max()) ? ' – '.$dues->max()->thaiDate() : '') : ''),
                route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($first->department_id)]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['assigned' => $group->count()],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), departmentId: $first->department_id);
        }

        return $result;
    }

    public function assigned(CabinetSubtask $subtask, User $actor, bool $moved): void
    {
        if (self::$batch !== null) {
            $subtask->loadMissing('cabinetTask.cabinet.project', 'department');
            self::$batch[] = [$subtask, $actor, $moved];

            return;
        }
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');

        $this->send(
            $this->departmentUsers($subtask, $actor),
            new PmoNotice(
                'assigned',
                $moved ? 'งานถูกย้ายมาที่แผนกของคุณ' : 'มีงานใหม่ถูกมอบหมายให้แผนกของคุณ',
                $this->describe($subtask).$this->dueText($subtask),
                // opens the project AND the department's window, where its "รับงาน" button is
                route('planning.board', ['project' => $subtask->cabinetTask->cabinet->project_id, 'stage' => PlanningBoard::deptKey($subtask->department_id)]),
                projectId: $subtask->cabinetTask->cabinet->project_id,
                group: "project:{$subtask->cabinetTask->cabinet->project_id}",
                parts: ['assigned' => 1],
                projectLabel: "{$subtask->cabinetTask->cabinet->project->project_no} · {$subtask->cabinetTask->cabinet->project->project_name}",
            ),
            departmentId: $subtask->department_id,
        );
    }

    /**
     * "ส่งเป็นการแจ้งเตือนไปแผนก" on a project comment: every active user of that department (not the sender)
     * gets an urgent notice that pops up on their next page load (kind 'alert' is in StatusReport::URGENT_KINDS).
     *
     * @return int how many people were notified
     */
    public function projectAlert(Project $project, Department $department, User $actor, string $note): int
    {
        $users = User::where('department_id', $department->id)->where('is_active', true)->where('id', '!=', $actor->id)->get();

        return $this->send($users, new PmoNotice(
            'alert',
            "{$actor->name}: {$project->project_no} {$project->project_name}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
        ));
    }

    /**
     * "@name" in a project comment: that person gets an urgent notice (bell + popup) that opens the project on the Planning
     * page. The sender is never notified about tagging themselves.
     */
    public function mentioned(Project $project, User $actor, User $target, string $note): int
    {
        if ($target->id === $actor->id || ! $target->is_active) {
            return 0;
        }

        return $this->send(collect([$target]), new PmoNotice(
            'mention',
            "{$actor->name} แท็กคุณในโครงการ {$project->project_no}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
        ));
    }

    /** Made one of the people in charge of a project on the Planning page. */
    public function assignedPerson(Project $project, User $actor, User $target): int
    {
        if ($target->id === $actor->id || ! $target->is_active) {
            return 0;
        }

        return $this->send(collect([$target]), new PmoNotice(
            'assigned',
            "{$actor->name} ให้คุณเป็นผู้รับผิดชอบ {$project->project_no}",
            $project->project_name,
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ['owner' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    public function progressed(CabinetSubtask $subtask, User $actor, string $action): void
    {
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');
        $project = $subtask->cabinetTask->cabinet->project;

        $verb = ['accepted' => 'รับงาน', 'started' => 'เริ่มงาน', 'completed' => 'ทำงานเสร็จแล้ว'][$action] ?? $action;

        $this->send(
            $this->followers($subtask, $actor),
            new PmoNotice(
                $action,
                "แผนก {$subtask->department?->name} {$verb}",
                $this->describe($subtask),
                route('planning.board', ['project' => $project->id]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ["{$action}|{$subtask->department?->name}" => 1],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ),
            departmentId: $subtask->department_id,
        );

        // the last unfinished piece of work of the whole project: tell the people in charge once
        if ($action === 'completed' && ! CabinetSubtask::query()
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->exists()) {
            $this->send($this->peopleInCharge($project, $actor), new PmoNotice(
                'project_done', "งานทุกแผนกของ {$project->project_no} เสร็จแล้ว", $project->project_name,
                route('planning.board', ['project' => $project->id]), "project_done:{$project->id}", projectId: $project->id,
                group: "project:{$project->id}", parts: ['project_done' => 1], projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true);
        }
    }

    /**
     * Who hears that a department accepted / started / finished: the project's PM, its people in charge, and whoever handed
     * this work out. When that is nobody, every active Admin / PM (so the news never goes nowhere). Never the person who did it.
     */
    private function followers(CabinetSubtask $subtask, User $actor): Collection
    {
        $project = $subtask->cabinetTask->cabinet->project;
        $dispatcher = \App\Models\ActivityLog::where('loggable_type', CabinetSubtask::class)->where('loggable_id', $subtask->id)
            ->whereIn('action', ['assigned', 'department_changed'])->latest('id')->value('user_id');
        $people = $this->peopleInCharge($project, $actor)
            ->merge(User::where('id', $dispatcher)->where('is_active', true)->where('id', '!=', $actor->id)->get())->unique('id');

        return $people->isNotEmpty() ? $people : $this->pmoPeople($actor);
    }

    /** The project's PM and its people in charge (Planning), active, not $except. */
    private function peopleInCharge(Project $project, ?User $except = null): Collection
    {
        return User::where('is_active', true)
            ->where(fn ($q) => $q->where('id', $project->project_manager_id)->orWhereIn('id', $project->assignees()->pluck('users.id')))
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    private function pmoPeople(?User $except = null): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])->where('is_active', true)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    /**
     * A new comment / reply on a project: its PM, its people in charge, everyone who commented there before and the author of
     * the comment being answered - if they can open the project. $skip = people already told (tagged / alerted).
     *
     * @param  list<int>  $skip
     */
    public function commented(Project $project, User $actor, \App\Models\ActivityLog $note, array $skip = []): int
    {
        $ids = \App\Models\ActivityLog::where('project_id', $project->id)->where('action', 'note')->where('id', '!=', $note->id)->pluck('user_id')
            ->merge($this->peopleInCharge($project)->pluck('id'))
            ->filter()->unique()->reject(fn ($id) => $id === $actor->id || in_array($id, $skip, true));
        // the author of the comment being answered hears it too (even if they never wrote here otherwise - they did, by definition)

        $people = User::whereIn('id', $ids)->where('is_active', true)->get()->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u));

        return $this->send($people, new PmoNotice(
            'comment',
            ! empty($note->changes['reply_to']) ? "{$actor->name} ตอบกลับใน {$project->project_no}" : "{$actor->name} คอมเมนต์ใน {$project->project_no}",
            mb_strimwidth($note->description, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ['comment' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    /**
     * Due dates of a department's work were moved by someone else: that department hears it, once per department / project.
     *
     * @param  Collection<int, CabinetSubtask>  $subtasks  the sub tasks whose due date changed (already saved)
     */
    public function dueChanged(Collection $subtasks, User $actor): int
    {
        $sent = 0;
        // a member moving their own department's dates is not news to that department
        foreach ($subtasks->filter(fn ($s) => $s->department_id && $s->department_id !== $actor->department_id)->groupBy(fn ($s) => $s->department_id.'|'.$s->cabinetTask->cabinet->project_id) as $group) {
            $first = $group->first()->loadMissing('cabinetTask.cabinet.project');
            $project = $first->cabinetTask->cabinet->project;
            $dues = $group->pluck('due_date')->filter();
            $sent += $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'due_changed',
                "วันส่งงานของแผนกเปลี่ยน · {$project->project_no}",
                $project->project_name.' · '.$group->count().' งาน'.($dues->isNotEmpty() ? ' · ส่ง '.$dues->min()->thaiDate().($dues->min()->ne($dues->max()) ? ' – '.$dues->max()->thaiDate() : '') : ' · ไม่มีวันส่ง'),
                route('planning.board', ['project' => $project->id]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['due_changed' => 1],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), departmentId: $first->department_id);
        }

        return $sent;
    }

    /**
     * "ทวงถาม": Admin / PM reminds the departments of work still "รอรับ" - the same notice as the morning reminder, once a day
     * per department and project (pressing again the same day sends nothing).
     *
     * @return list<array{project: Project, department: string, count: int, sent: int}> per department and project
     */
    public function nudge(Collection $subtasks, User $actor): array
    {
        $out = [];
        $today = now()->toDateString();
        foreach ($subtasks->filter(fn ($s) => $s->department_id && $s->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED)
            ->groupBy(fn ($s) => $s->department_id.'|'.$s->cabinetTask->cabinet->project_id) as $rows) {
            $first = $rows->first()->loadMissing('cabinetTask.cabinet.project', 'department');
            $project = $first->cabinetTask->cabinet->project;
            $days = (int) $rows->max(fn ($s) => $s->assigned_at ? (int) $s->assigned_at->diffInDays(now()) : 0);
            $out[] = ['project' => $project, 'department' => $first->department?->name ?? '-', 'count' => $rows->count(), 'sent' => $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'waiting',
                "{$actor->name} ทวงถาม: งานรอรับ {$rows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · รอมาแล้ว {$days} วัน — กดรับงานในหน้า Planning",
                route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($first->department_id)]),
                'nudge:'.$first->department_id.':'.$project->id.':'.$today,
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['waiting' => ['n' => $rows->count(), 'days' => $days]],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true, departmentId: $first->department_id)];
        }

        return $out;
    }

    /**
     * Settings > การส่งต่องาน: news from the department passing work on (dates set, items ticked, the daily "due today / late"),
     * folded into each receiver's project notice. Not filtered by "only my department" - it is meant for the receiver.
     */
    public function handoff(Collection $people, Project $project, string $title, string $body, array $parts, ?string $key = null, string $kind = 'handoff'): int
    {
        return $this->send($people, new PmoNotice(
            $kind, $title, $body,
            route('planning.board', ['project' => $project->id]),
            $key,
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: $parts,
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ), dedupe: $key !== null);
    }

    /** How many active people a department has (0 = nobody would ever see what is sent there). */
    public function headcount(int $departmentId): int
    {
        return User::where('department_id', $departmentId)->where('is_active', true)->count();
    }

    /**
     * Daily reminders, as ONE notice per department per project (not one per sub task): "งานเกินกำหนด 18 งาน · SO…",
     * with how late the worst one is and how many are due soon. The project's PM / people in charge get one per project
     * when something is overdue. Sent once a day (the key holds the date). Returns how many notices were sent.
     */
    public function sendReminders(int $soonDays = 2): int
    {
        $sent = 0;
        $today = today();

        $rows = CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNotNull('due_date')
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->whereDate('due_date', '<=', $today->copy()->addDays($soonDays))
            ->with('cabinetTask.cabinet.project', 'department')
            ->get()
            // a closed project is read-only and finished - nobody needs chasing about it
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked());

        $summary = function (Collection $group) use ($today): array {
            $late = $group->filter(fn ($s) => $s->due_date->lt($today));
            $soon = $group->count() - $late->count();
            $worst = $late->isNotEmpty() ? (int) $late->min(fn ($s) => $today->diffInDays($s->due_date, false)) : 0;

            return [$late->count(), $soon, abs($worst)];
        };

        foreach ($rows->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id) as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;

            // each department: its own work in this project
            foreach ($projectRows->groupBy('department_id') as $deptRows) {
                [$late, $soon, $worst] = $summary($deptRows);
                $sent += $this->send($this->departmentUsers($deptRows->first()), new PmoNotice(
                    $late ? 'overdue' : 'due_soon',
                    $late ? "งานเกินกำหนด {$late} งาน · {$project->project_no}" : "งานใกล้ครบกำหนด {$soon} งาน · {$project->project_no}",
                    $project->project_name.($late ? " · เกินสุด {$worst} วัน" : '').($late && $soon ? " · ใกล้ครบอีก {$soon} งาน" : ''),
                    route('planning.board', ['project' => $project->id]),
                    'reminder:'.$deptRows->first()->department_id.':'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: array_filter(['overdue' => $late ? ['n' => $late, 'worst' => $worst] : null, 'due_soon' => $soon ?: null]),
                    projectLabel: "{$project->project_no} · {$project->project_name}",
                ), dedupe: true, departmentId: $deptRows->first()->department_id);
            }

            // the people in charge: one notice per project, only when something is late
            $lateRows = $projectRows->filter(fn ($s) => $s->due_date->lt($today));
            if ($lateRows->isNotEmpty()) {
                [$late, , $worst] = $summary($lateRows);
                $depts = $lateRows->pluck('department.name')->filter()->unique()->implode(', ');
                $sent += $this->send($this->peopleInCharge($project)->reject(fn ($u) => $lateRows->pluck('department_id')->contains($u->department_id)), new PmoNotice(
                    'overdue',
                    "งานเกินกำหนด {$late} งาน · {$project->project_no}",
                    "{$project->project_name} · {$depts} · เกินสุด {$worst} วัน",
                    route('planning.board', ['project' => $project->id]),
                    'reminder-lead:'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: ['overdue' => ['n' => $late, 'worst' => $worst]],
                    projectLabel: "{$project->project_no} · {$project->project_name}",
                ), dedupe: true);
            }
        }

        // work handed out with no due date: the people who set dates (the project's PM / people in charge, else every Admin / PM)
        $undated = CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNull('due_date')
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->with('cabinetTask.cabinet.project', 'department')->get()
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked())
            ->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);
        foreach ($undated as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;
            $people = $this->peopleInCharge($project);
            $depts = $projectRows->pluck('department.name')->filter()->unique()->implode(', ');
            $sent += $this->send($people->isNotEmpty() ? $people : $this->pmoPeople(), new PmoNotice(
                'undated',
                "งานยังไม่มีวันส่ง {$projectRows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · {$depts}",
                route('planning.board', ['project' => $project->id]),
                'undated:'.$project->id.':'.$today->toDateString(),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['undated' => $projectRows->count()],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true);
        }

        $sent += $this->waitingReminders($today);

        // Settings > การส่งต่องาน: what the department passing work on said would arrive today / is late
        $sent += app(HandoffService::class)->reminders($this, $today);

        return $sent;
    }

    /**
     * Work handed out and still "รอรับ" after Settings > กฎการทำงาน's number of days: the department hears it (one notice per
     * project) and so do the people in charge of the project - every Admin / PM when nobody is - one notice per project
     * naming the departments. Once a day.
     */
    private function waitingReminders(\Carbon\Carbon $today): int
    {
        $days = (int) \App\Models\AppSetting::get('waiting_reminder_days');
        if ($days <= 0) {
            return 0;
        }
        $rows = CabinetSubtask::query()
            ->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->whereNotNull('department_id')
            ->whereNotNull('assigned_at')->where('assigned_at', '<=', now()->subDays($days))
            ->with('cabinetTask.cabinet.project', 'department')->get()
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked());

        $sent = 0;
        foreach ($rows->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id) as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;
            $label = "{$project->project_no} · {$project->project_name}";
            $longest = fn (Collection $g) => (int) $g->max(fn ($s) => (int) $s->assigned_at->diffInDays(now()));

            foreach ($projectRows->groupBy('department_id') as $deptRows) {
                $sent += $this->send($this->departmentUsers($deptRows->first()), new PmoNotice(
                    'waiting',
                    "งานรอรับ {$deptRows->count()} งาน · {$project->project_no}",
                    "{$project->project_name} · รอมาแล้ว {$longest($deptRows)} วัน — กดรับงานในหน้า Planning",
                    route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($deptRows->first()->department_id)]),
                    'waiting:'.$deptRows->first()->department_id.':'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: ['waiting' => ['n' => $deptRows->count(), 'days' => $longest($deptRows)]],
                    projectLabel: $label,
                ), dedupe: true, departmentId: $deptRows->first()->department_id);
            }

            $depts = $projectRows->pluck('department_id')->unique();
            $people = $this->peopleInCharge($project);
            $sent += $this->send(($people->isNotEmpty() ? $people : $this->pmoPeople())->reject(fn ($u) => $depts->contains($u->department_id)), new PmoNotice(
                'waiting',
                "ยังไม่มีคนรับงาน {$projectRows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · ".$projectRows->pluck('department.name')->filter()->unique()->implode(', ')." · รอมาแล้ว {$longest($projectRows)} วัน",
                route('planning.board', ['project' => $project->id]),
                'waiting-lead:'.$project->id.':'.$today->toDateString(),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['waiting' => ['n' => $projectRows->count(), 'days' => $longest($projectRows)]],
                projectLabel: $label,
            ), dedupe: true);
        }

        return $sent;
    }

    /** Which notice kinds each personal switch (Settings > การแจ้งเตือน) controls. Everything else (assigned, alert, mention, request) always arrives. */
    private const PREFERENCE_FOR_KIND = [
        'accepted' => 'progress', 'started' => 'progress', 'completed' => 'completed',
        'due_soon' => 'reminders', 'overdue' => 'reminders', 'undated' => 'reminders', 'waiting' => 'reminders',
        'comment' => 'comments', 'due_changed' => 'due_changes', 'project_done' => 'completed',
        'handoff' => 'progress', 'handoff_due' => 'reminders',
    ];

    /** @param  int|null  $departmentId  the department the news is about, for "only my department's work" */
    private function wants(User $user, ?NotificationPreference $pref, PmoNotice $notice, ?int $departmentId): bool
    {
        if (! $pref) {
            return true;
        }
        if (! $pref->enabled) {
            return false;
        }
        if (($switch = self::PREFERENCE_FOR_KIND[$notice->kind] ?? null) && ! $pref->{$switch}) {
            return false;
        }

        return ! ($pref->only_my_department && $departmentId && $user->department_id && $user->department_id !== $departmentId
            && isset(self::PREFERENCE_FOR_KIND[$notice->kind]));
    }

    private function send(Collection $users, PmoNotice $notice, bool $dedupe = false, ?int $departmentId = null): int
    {
        $count = 0;
        $delivered = collect();
        $prefs = NotificationPreference::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        foreach ($users as $user) {
            if (! $this->wants($user, $prefs->get($user->id), $notice, $departmentId)) {
                continue;
            }

            if ($dedupe && $notice->key && $this->alreadySent($user, $notice->key)) {
                continue;
            }

            if ($notice->group && $this->collapse($user, $notice)) {
                $delivered->push($user);
                $count++;
                continue;
            }

            $user->notify($notice->parts ? $this->asProjectNotice($notice) : $notice);
            $delivered->push($user);
            $count++;
        }

        // the same notice to their devices when the app is closed (Settings > การแจ้งเตือน › เครื่องนี้)
        app(WebPushService::class)->send($delivered, $notice->title, $notice->body, $notice->url, $notice->group,   // same group = replaces the one already on the device
            in_array($notice->kind, ['assigned', 'waiting'], true) ? ['accept' => true] : []);   // new / waiting work: a "รับงาน" button on the notice

        return $count;
    }

    /** How long a still-unread notice keeps absorbing new ones of its group (a project's notice: a week). */
    private const COLLAPSE_HOURS = 24 * 7;

    /** The project's notice: title = the project, body = the summary of everything that happened ("งานใหม่ 3 · …"). */
    private function asProjectNotice(PmoNotice $notice): PmoNotice
    {
        $n = clone $notice;   // kind stays the event's (the latest one), so filters by kind keep working
        $n->title = $notice->projectLabel ?? $notice->title;
        $n->latest = $notice->title.' — '.$notice->body;
        $n->body = PmoNotice::summary($notice->parts, isset($notice->parts['comment']) ? $notice->body : null);

        return $n;
    }

    /**
     * Folds $notice into the person's latest UNREAD notice of the same group (from the last few hours): one notice that
     * counts up ("แผนกขาย รับงาน 18 งาน", "3 คอมเมนต์ใหม่ใน SO…") and moves back to the top, instead of a new one each time.
     */
    private function collapse(User $user, PmoNotice $notice): bool
    {
        $existing = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $user->id)
            ->where('type', PmoNotice::class)->whereNull('read_at')->where('created_at', '>=', now()->subHours(self::COLLAPSE_HOURS))
            ->where('data', 'like', '%"group":'.json_encode($notice->group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'%')
            ->latest()->first();
        if (! $existing) {
            return false;
        }

        $data = $existing->data;
        $n = (int) ($data['count'] ?? 1) + $notice->count;
        if ($notice->parts) {   // a project's notice: add this event to its summary
            $parts = PmoNotice::mergeParts($data['parts'] ?? [], $notice->parts);
            $last = isset($notice->parts['comment']) ? $notice->body : ($data['last_comment'] ?? null);
            $data = array_merge($data, ['kind' => $notice->kind, 'parts' => $parts, 'last_comment' => $last, 'urgent' => PmoNotice::urgentParts($parts),
                'title' => $notice->projectLabel ?? $data['title'], 'body' => PmoNotice::summary($parts, $last), 'latest' => $notice->title.' — '.$notice->body,
                'keys' => array_values(array_unique(array_filter(array_merge($data['keys'] ?? [$data['key'] ?? null], [$notice->key])))), 'count' => $n]);
        } else {
            $data = array_merge($data, ['count' => $n, 'title' => str_replace(':n', (string) $n, $notice->groupTitle ?? $notice->title), 'body' => $notice->body]);
        }
        $existing->forceFill(['data' => array_merge($data, ['url' => $notice->url]), 'created_at' => now()])->save();

        return true;
    }

    private function alreadySent(User $user, string $key): bool
    {
        return DatabaseNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', PmoNotice::class)
            ->where('data', 'like', '%'.json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'%')
            ->exists();
    }

    private function departmentUsers(CabinetSubtask $subtask, ?User $except = null): Collection
    {
        if (! $subtask->department_id) {
            return collect();
        }

        return User::where('department_id', $subtask->department_id)->where('is_active', true)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    private function projectManager(CabinetSubtask $subtask, ?User $except = null): Collection
    {
        $subtask->loadMissing('cabinetTask.cabinet.project');
        $managerId = $subtask->cabinetTask?->cabinet?->project?->project_manager_id;

        if (! $managerId || $managerId === $except?->id) {
            return collect();
        }

        return User::where('id', $managerId)->where('is_active', true)->get();
    }

    private function describe(CabinetSubtask $subtask): string
    {
        $cabinet = $subtask->cabinetTask->cabinet;

        return "{$subtask->name} ({$cabinet->project->project_no} / {$cabinet->mo_no})";
    }

    private function dueText(CabinetSubtask $subtask): string
    {
        return $subtask->due_date ? ' · กำหนดส่ง '.$subtask->due_date->thaiDate() : '';
    }
}
