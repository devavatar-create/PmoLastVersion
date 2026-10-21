<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Department;
use App\Models\Project;
use App\Services\DepartmentDashboard;
use App\Services\ExecutiveReport;
use App\Services\ProductionReport;
use App\Services\ReportFilter;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DepartmentDashboard $departmentDashboard, ExecutiveReport $executive)
    {
        // two views: "ภาพรวมผู้บริหาร" (opens first for people who see every department's work) and "งานตามแผนก"
        $view = match (true) {
            in_array($request->query('view'), ['exec', 'dept'], true) => $request->query('view'),
            $request->has('department') => 'dept',   // a department filter link
            default => $request->user()->canSeeAllWork() ? 'exec' : 'dept',
        };

        $projects = Project::visibleTo($request->user())   // the projects this person may see (WorkScope)
            ->withAvg('cabinets', 'progress')
            ->withAvg('projectTasks', 'progress')
            ->withCount('cabinets')
            ->get();
        $cabinets = fn () => Cabinet::whereIn('project_id', $projects->modelKeys());

        // in progress, the same projects the overview counts as ตามแผน + ล่าช้า (so the two tabs agree)
        $activeProjects = $projects->where('status', Project::STATUS_IN_PROGRESS);

        $nearDue = $activeProjects->filter(function (Project $project) {
            return $project->due_date
                && $project->due_date->greaterThanOrEqualTo(now())
                && now()->diffInDays($project->due_date) <= 7;
        });

        $delayed = $activeProjects->filter(fn (Project $project) => $project->due_date && $project->due_date->lt(today()));   // Project::scopeRunningLate
        $atRisk = $activeProjects->filter(fn (Project $project) => $project->risk_level === 'at_risk');

        $totalCabinets = $cabinets()->count();
        $completedCabinets = $cabinets()->where('status', Cabinet::STATUS_COMPLETED)->count();
        $inProductionCabinets = $cabinets()->where('status', Cabinet::STATUS_IN_PROGRESS)->count();
        $avgProduction = (int) round($cabinets()->avg('progress') ?? 0);

        $recentProjects = $projects->sortByDesc('created_at')->take(10);

        // Department section. Same scoping rule as My Department: admin/PM may look at
        // any department (or all); everyone else only ever gets their own - a hand-edited
        // ?department= is ignored for them, not just hidden in the UI.
        $user = $request->user();
        $canViewOthers = $user->canViewOtherDepartments();
        $filterDepartmentId = $canViewOthers ? ($request->integer('department') ?: null) : null;
        $scopeIds = DepartmentDashboard::scopeFor($user, $filterDepartmentId);

        return view('dashboard', [
            'view' => $view,
            'exec' => $view === 'exec' ? $executive->build($user) : null,
            'activeProjectsCount' => $activeProjects->count(),
            'nearDueCount' => $nearDue->count(),
            'delayedCount' => $delayed->count(),
            'atRiskCount' => $atRisk->count(),
            'totalCabinets' => $totalCabinets,
            'completedCabinets' => $completedCabinets,
            'inProductionCabinets' => $inProductionCabinets,
            'avgProduction' => $avgProduction,
            'recentProjects' => $recentProjects,
            'canViewOthers' => $canViewOthers,
            'filterDepartmentId' => $filterDepartmentId,
            'filterDepartments' => $canViewOthers ? Department::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'dept' => $departmentDashboard->build($scopeIds, today()),
        ]);
    }

    /** The CEO report: the same numbers as "ภาพรวมผู้บริหาร", laid out on the company letterhead for print / Save as PDF. */
    /** The CEO report's parts, in print order; "หัวข้อที่จะพิมพ์" turns them on and off. */
    public const CEO_SECTIONS = [
        'summary' => 'บทสรุปผู้บริหาร', 'highlights' => 'ประเด็นสำคัญรายโครงการ', 'kpis' => 'ตัวเลขหลัก',
        'status' => 'สถานะโครงการ / ตู้ที่ล่าช้า', 'due' => 'กำหนดส่งมอบใน 30 วัน', 'ready' => 'รอลูกค้ารับ',
        'months' => 'แผนส่งมอบรายเดือน', 'bottleneck' => 'คอขวดตามแผนก', 'follow' => 'ตู้ที่ต้องติดตาม', 'sign' => 'ช่องลงชื่อ',
    ];

    /**
     * The CEO report: ExecutiveReport (projects, months, bottlenecks) plus ProductionReport (cabinets, hand-overs), read
     * by project or by cabinet, for the SO / departments / hand-over period chosen, with the parts chosen.
     */
    public function report(Request $request, ExecutiveReport $executive, ProductionReport $production)
    {
        $filter = ReportFilter::fromRequest($request);
        $sections = $request->boolean('pick')   // the options form was sent: what is ticked (maybe nothing); else everything
            ? array_values(array_intersect(array_keys(self::CEO_SECTIONS), (array) $request->query('sections', [])))
            : array_keys(self::CEO_SECTIONS);

        $open = Project::visibleTo($request->user())->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED])
            ->orderBy('project_no')->get(['id', 'project_no', 'project_name', 'customer_name']);

        return view('reports.ceo', [
            'r' => $executive->build($request->user(), filter: $filter),
            'p' => $production->build($request->user(), filter: $filter),
            'by' => $request->query('by') === 'cabinet' ? 'cabinet' : 'project',
            'filter' => $filter,
            'sections' => $sections,
            'allSections' => self::CEO_SECTIONS,
            // "โครงการ / ตู้": the open projects, each with its cabinets (a project without one is listed too)
            'cabinetOptions' => (function () use ($open) {
                $byProject = Cabinet::whereIn('project_id', $open->modelKeys())->orderBy('mo_no')->get(['id', 'project_id', 'mo_no', 'cabinet_name'])->groupBy('project_id');

                return $open->map(fn ($p) => ['project' => $p, 'cabinets' => $byProject->get($p->id, collect())])->values();
            })(),
            'departmentOptions' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
