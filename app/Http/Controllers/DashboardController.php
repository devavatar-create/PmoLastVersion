<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Department;
use App\Models\Project;
use App\Services\DepartmentDashboard;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DepartmentDashboard $departmentDashboard)
    {
        $projects = Project::visibleTo($request->user())   // the projects this person may see (WorkScope)
            ->withAvg('cabinets', 'progress')
            ->withAvg('projectTasks', 'progress')
            ->withCount('cabinets')
            ->get();
        $cabinets = fn () => Cabinet::whereIn('project_id', $projects->modelKeys());

        $activeProjects = $projects->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]);

        $nearDue = $activeProjects->filter(function (Project $project) {
            return $project->due_date
                && $project->due_date->greaterThanOrEqualTo(now())
                && now()->diffInDays($project->due_date) <= 7;
        });

        $delayed = $activeProjects->filter(fn (Project $project) => $project->risk_level === 'overdue');
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
}
