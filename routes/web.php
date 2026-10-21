<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\CabinetChecklistController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\CabinetSubtaskController;
use App\Http\Controllers\CabinetTaskController;
use App\Http\Controllers\CabinetTemplateController;
use App\Http\Controllers\CabinetTemplateItemController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MyDepartmentController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\StatusReportController;
use App\Http\Controllers\Settings\AutomationController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\JobTypeController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Real per-user login is required for everything below. A project (or anything in one) opens only for people who see it;
// 'perm:…' = Settings > สิทธิ์การใช้งาน (App\Support\Access).
Route::middleware(['auth', 'project.visible'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('perm:page.dashboard')->name('dashboard');

    Route::get('projects/export', [ProjectController::class, 'export'])->middleware('perm:page.export')->name('projects.export');
    Route::middleware('perm:data.comments')->group(function () {   // the change log and comments
        Route::get('projects/{project}/activity-export', [ActivityLogController::class, 'projectExport'])->middleware('perm:page.export')->name('projects.activity-export');
        Route::get('cabinets/{cabinet}/activity-export', [ActivityLogController::class, 'cabinetExport'])->middleware('perm:page.export')->name('cabinets.activity-export');
        Route::get('projects/{project}/activity-tree', [ActivityLogController::class, 'projectTree'])->name('projects.activity-tree');
        Route::get('cabinets/{cabinet}/activity-tree', [ActivityLogController::class, 'cabinetTree'])->name('cabinets.activity-tree');
        Route::put('activity-logs/{activityLog}', [ActivityLogController::class, 'update'])->name('activity-logs.update');
        Route::post('projects/{project}/notes', [ProjectController::class, 'storeNote'])->name('projects.notes.store');
        Route::post('cabinets/{cabinet}/notes', [CabinetController::class, 'storeNote'])->name('cabinets.notes.store');
        Route::post('my-department/projects/{project}/notes', [MyDepartmentController::class, 'storeProjectNote'])->name('my-department.project.note');
    });

    // "งานแผนกของฉัน" and Planning are ONE page now: the Planning board. The old URL (bookmarks, old notices) lands there.
    Route::get('my-department', fn (\Illuminate\Http\Request $r) => redirect()->route('planning.board', $r->only('project')))->name('my-department.index');
    // Trial: the original Avatar Planning page running on PMO data (read-only) - see PlanningController.
    Route::middleware(['gzip', 'perm:page.planning'])->group(function () {
        Route::get('planning', fn (\Illuminate\Http\Request $r) => redirect()->route('planning.board', $r->only('project')))->name('planning.index');
        // the original single-file page, kept only as a hidden fallback (not linked anywhere)
        Route::get('planning/classic', [PlanningController::class, 'index'])->name('planning.spa');
        Route::get('planning/board', [PlanningController::class, 'board'])->name('planning.board');
        Route::get('planning/data', [PlanningController::class, 'data'])->name('planning.data');
        Route::get('planning/cabinets/{cabinet}', [PlanningController::class, 'cabinet'])->name('planning.cabinet');
        Route::get('planning/work-order', [PlanningController::class, 'workOrder'])->name('planning.work-order');
        Route::post('planning/comment', [PlanningController::class, 'comment'])->middleware(['throttle:60,1', 'perm:data.comments'])->name('planning.comment');
        Route::post('planning/sync', [PlanningController::class, 'sync'])->middleware('throttle:240,1')->name('planning.sync');
    });
    Route::get('my-department/tasks', [MyDepartmentController::class, 'tasks'])->name('my-department.tasks');
    Route::get('my-department/projects/{project}', [MyDepartmentController::class, 'project'])->name('my-department.project');
    Route::patch('my-department/projects/{project}', [MyDepartmentController::class, 'updateProject'])->name('my-department.project.update');
    Route::get('my-department/dashboard', [MyDepartmentController::class, 'dashboard'])->name('my-department.dashboard');
    Route::get('my-department/export', [MyDepartmentController::class, 'export'])->middleware('perm:page.export')->name('my-department.export');
    Route::get('my-department/subtasks/{cabinetSubtask}', [MyDepartmentController::class, 'show'])->name('my-department.show');
    Route::post('my-department/subtasks/{cabinetSubtask}/star', [MyDepartmentController::class, 'toggleStar'])->name('my-department.star');

    Route::get('status-report', [StatusReportController::class, 'show'])->name('status-report.show');
    Route::post('status-report/acknowledge', [StatusReportController::class, 'acknowledge'])->name('status-report.acknowledge');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/projects/{id}/restore', [TrashController::class, 'restoreProject'])->name('trash.projects.restore');
    Route::post('trash/cabinets/{id}/restore', [TrashController::class, 'restoreCabinet'])->name('trash.cabinets.restore');
    Route::post('trash/tasks/{id}/restore', [TrashController::class, 'restoreTask'])->name('trash.tasks.restore');
    Route::post('trash/subtasks/{id}/restore', [TrashController::class, 'restoreSubtask'])->name('trash.subtasks.restore');

    Route::get('assignments', [AssignmentController::class, 'index'])->middleware('perm:page.assignments')->name('assignments.index');

    // project data and dates: 'pmo' = edit.manage (Admin / Project Manager by default); reading, comments and the department's work stay open
    Route::resource('projects', ProjectController::class)->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'pmo')->middlewareFor('index', 'perm:page.projects');

    Route::patch('projects/{project}/color', [ProjectController::class, 'updateColor'])->name('projects.color');

    Route::post('projects/{project}/tasks', [ProjectTaskController::class, 'store'])->middleware('pmo')->name('project-tasks.store');
    Route::put('project-tasks/{projectTask}', [ProjectTaskController::class, 'update'])->middleware('pmo')->name('project-tasks.update');
    Route::delete('project-tasks/{projectTask}', [ProjectTaskController::class, 'destroy'])->middleware('pmo')->name('project-tasks.destroy');

    Route::post('projects/{project}/cabinets', [CabinetController::class, 'store'])->middleware('pmo')->name('cabinets.store');
    Route::post('cabinets/{cabinet}/copy', [CabinetController::class, 'copy'])->middleware('pmo')->name('cabinets.copy');
    Route::get('cabinets/{cabinet}', [CabinetController::class, 'show'])->name('cabinets.show');
    Route::get('cabinets/{cabinet}/edit', [CabinetController::class, 'edit'])->middleware('pmo')->name('cabinets.edit');
    Route::put('cabinets/{cabinet}', [CabinetController::class, 'update'])->middleware('pmo')->name('cabinets.update');
    Route::delete('cabinets/{cabinet}', [CabinetController::class, 'destroy'])->middleware('pmo')->name('cabinets.destroy');
    Route::get('cabinets', [CabinetController::class, 'index'])->middleware('perm:page.cabinets')->name('cabinets.index');

    Route::put('cabinet-tasks/{cabinetTask}', [CabinetTaskController::class, 'update'])->middleware('pmo')->name('cabinet-tasks.update');
    Route::post('cabinet-subtasks/remind', [CabinetSubtaskController::class, 'remind'])->name('cabinet-subtasks.remind');   // "ทวงถาม" work still รอรับ
    Route::post('cabinet-subtasks/bulk-department', [CabinetSubtaskController::class, 'bulkDepartment'])->name('cabinet-subtasks.bulk-department');   // many at once (cabinet page ticks / a whole step)
    Route::put('cabinet-subtasks/{cabinetSubtask}/pairs', [\App\Http\Controllers\TemplatePairController::class, 'updateCabinet'])->name('cabinet-subtasks.pairs');   // "แก้คู่เทียบ" of one cabinet (Admin / PM)
    Route::put('cabinet-subtasks/{cabinetSubtask}', [CabinetSubtaskController::class, 'update'])->middleware('pmo')->name('cabinet-subtasks.update');
    Route::delete('cabinet-tasks/{cabinetTask}', [CabinetTaskController::class, 'destroy'])->middleware('pmo')->name('cabinet-tasks.destroy');
    Route::delete('cabinet-subtasks/{cabinetSubtask}', [CabinetSubtaskController::class, 'destroy'])->middleware('pmo')->name('cabinet-subtasks.destroy');
    Route::patch('cabinet-subtasks/{cabinetSubtask}/department', [CabinetSubtaskController::class, 'updateDepartment'])->name('cabinet-subtasks.department');
    Route::post('cabinet-subtasks/{cabinetSubtask}/accept', [CabinetSubtaskController::class, 'accept'])->name('cabinet-subtasks.accept');
    Route::post('cabinet-subtasks/{cabinetSubtask}/start', [CabinetSubtaskController::class, 'start'])->name('cabinet-subtasks.start');
    Route::post('cabinet-subtasks/{cabinetSubtask}/complete', [CabinetSubtaskController::class, 'complete'])->name('cabinet-subtasks.complete');

    Route::post('cabinet-subtasks/{cabinetSubtask}/checklists', [CabinetChecklistController::class, 'store'])->name('checklists.store');
    Route::post('cabinet-subtasks/{cabinetSubtask}/copy-checklists', [CabinetChecklistController::class, 'copyChecklists'])->name('checklists.copy');
    Route::patch('checklists/{checklist}/toggle', [CabinetChecklistController::class, 'toggle'])->name('checklists.toggle');
    // the one checklist editor (cabinet page + template page): read the list / its move-copy choices, apply one operation
    Route::get('projects/{project}/checklist-bulk', [\App\Http\Controllers\ChecklistBulkController::class, 'options'])->middleware('pmo')->name('projects.checklist-bulk.options');
    Route::post('projects/{project}/checklist-bulk', [\App\Http\Controllers\ChecklistBulkController::class, 'run'])->middleware('pmo')->name('projects.checklist-bulk.run');
    Route::get('checklist-editor/{kind}/{id}', [\App\Http\Controllers\ChecklistEditorController::class, 'show'])->whereNumber('id')->name('checklist-editor.show');
    Route::post('checklist-editor/{kind}/{id}', [\App\Http\Controllers\ChecklistEditorController::class, 'apply'])->whereNumber('id')->name('checklist-editor.apply');
    Route::patch('checklists/{checklist}/remark', [CabinetChecklistController::class, 'updateRemark'])->name('checklists.update-remark');
    Route::delete('checklists/{checklist}', [CabinetChecklistController::class, 'destroy'])->name('checklists.destroy');

    Route::middleware('perm:edit.templates')->controller(\App\Http\Controllers\TemplateToolsController::class)->group(function () {   // template tools
        Route::post('cabinet-templates/import', 'import')->name('cabinet-templates.import');
        Route::get('cabinet-templates/{cabinetTemplate}/export', 'export')->name('cabinet-templates.export');
        Route::post('cabinet-templates/{cabinetTemplate}/duplicate', 'duplicateTemplate')->name('cabinet-templates.duplicate');
        Route::get('cabinet-templates/{cabinetTemplate}/sync', 'syncPreview')->name('cabinet-templates.sync');
        Route::post('cabinet-templates/{cabinetTemplate}/sync', 'syncApply')->name('cabinet-templates.sync.apply');
        Route::post('task-templates/{taskTemplate}/duplicate', 'duplicateTask')->name('task-templates.duplicate');
        Route::post('subtask-templates/{subtaskTemplate}/duplicate', 'duplicateSubtask')->name('subtask-templates.duplicate');
    });
    Route::resource('cabinet-templates', CabinetTemplateController::class)->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'perm:edit.templates')
        ->middlewareFor(['index', 'show'], 'perm:page.templates');
    Route::middleware('perm:edit.templates')->group(function () {   // the template's tasks / sub tasks / checklists
    Route::post('cabinet-templates/{cabinetTemplate}/task-templates', [CabinetTemplateItemController::class, 'storeTask'])->name('task-templates.store');
    Route::patch('task-templates/reorder', [CabinetTemplateItemController::class, 'reorderTasks'])->name('task-templates.reorder');
    Route::put('task-templates/{taskTemplate}', [CabinetTemplateItemController::class, 'updateTask'])->name('task-templates.update');
    Route::delete('task-templates/{taskTemplate}', [CabinetTemplateItemController::class, 'destroyTask'])->name('task-templates.destroy');
    Route::post('task-templates/{taskTemplate}/subtask-templates', [CabinetTemplateItemController::class, 'storeSubtask'])->name('subtask-templates.store');
    Route::patch('subtask-templates/reorder', [CabinetTemplateItemController::class, 'reorderSubtasks'])->name('subtask-templates.reorder');
    Route::put('subtask-templates/{subtaskTemplate}', [CabinetTemplateItemController::class, 'updateSubtask'])->name('subtask-templates.update');
    Route::delete('subtask-templates/{subtaskTemplate}', [CabinetTemplateItemController::class, 'destroySubtask'])->name('subtask-templates.destroy');
    Route::post('subtask-templates/{subtaskTemplate}/checklist-templates', [CabinetTemplateItemController::class, 'storeChecklist'])->name('checklist-templates.store');
    Route::patch('checklist-templates/reorder', [CabinetTemplateItemController::class, 'reorderChecklists'])->name('checklist-templates.reorder');
    Route::put('subtask-templates/{subtaskTemplate}/pairs', [\App\Http\Controllers\TemplatePairController::class, 'update'])->name('subtask-templates.pairs');   // "เทียบกับ…"
    Route::post('cabinet-templates/{cabinetTemplate}/pairs/apply', [\App\Http\Controllers\TemplatePairController::class, 'apply'])->name('cabinet-templates.pairs.apply');
    Route::put('checklist-templates/{checklistTemplate}', [CabinetTemplateItemController::class, 'updateChecklist'])->name('checklist-templates.update');
    Route::delete('checklist-templates/{checklistTemplate}', [CabinetTemplateItemController::class, 'destroyChecklist'])->name('checklist-templates.destroy');
    });

    // Settings hub: every tab is admin-only except "การแจ้งเตือน" (your own).
    Route::get('settings', [\App\Http\Controllers\Settings\SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/notifications', [\App\Http\Controllers\Settings\SettingsController::class, 'editNotifications'])->name('settings.notifications.edit');
    Route::put('settings/notifications', [\App\Http\Controllers\Settings\SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::get('settings/rules', [\App\Http\Controllers\Settings\SettingsController::class, 'editRules'])->middleware('admin')->name('settings.rules.edit');
    Route::put('settings/rules', [\App\Http\Controllers\Settings\SettingsController::class, 'updateRules'])->middleware('admin')->name('settings.rules.update');

    Route::middleware('admin')->prefix('settings')->group(function () {
        Route::get('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'index'])->name('settings.statuses.edit');
        Route::put('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'update'])->name('settings.statuses.update');
        Route::post('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'store'])->name('settings.statuses.store');
        Route::delete('statuses/{status}', [\App\Http\Controllers\Settings\StatusController::class, 'destroy'])->name('settings.statuses.destroy');
        Route::patch('statuses/{status}/move', [\App\Http\Controllers\Settings\StatusController::class, 'move'])->name('settings.statuses.move');
        $boards = \App\Http\Controllers\Settings\BoardController::class;
        Route::get('boards', [$boards, 'boards'])->name('settings.boards.index');
        Route::post('boards', [$boards, 'storeBoard'])->name('settings.boards.store');
        Route::put('boards/{board}', [$boards, 'updateBoard'])->name('settings.boards.update');
        Route::delete('boards/{board}', [$boards, 'destroyBoard'])->name('settings.boards.destroy');
        Route::get('labels', [$boards, 'labels'])->name('settings.labels.index');
        Route::post('labels', [$boards, 'storeLabel'])->name('settings.labels.store');
        Route::put('labels/{label}', [$boards, 'updateLabel'])->name('settings.labels.update');
        Route::delete('labels/{label}', [$boards, 'destroyLabel'])->name('settings.labels.destroy');
        $rules = \App\Http\Controllers\Settings\StatusRuleController::class;
        Route::get('status-rules', [$rules, 'index'])->name('settings.status-rules.index');
        Route::post('status-rules', [$rules, 'store'])->name('settings.status-rules.store');
        Route::patch('status-rules/{rule}', [$rules, 'toggle'])->name('settings.status-rules.toggle');
        Route::delete('status-rules/{rule}', [$rules, 'destroy'])->name('settings.status-rules.destroy');
        Route::put('status-rules-locked', [$rules, 'locked'])->name('settings.status-rules.locked');
        Route::get('permissions', [\App\Http\Controllers\Settings\PermissionController::class, 'edit'])->name('settings.permissions.edit');
        Route::put('permissions', [\App\Http\Controllers\Settings\PermissionController::class, 'update'])->name('settings.permissions.update');
        Route::delete('permissions', [\App\Http\Controllers\Settings\PermissionController::class, 'reset'])->name('settings.permissions.reset');
        Route::patch('fields/{field}/chip', [\App\Http\Controllers\Settings\FieldController::class, 'toggleChip'])->name('settings.fields.chip');
        Route::get('fields', [\App\Http\Controllers\Settings\FieldController::class, 'index'])->name('settings.fields.index');
        Route::post('fields', [\App\Http\Controllers\Settings\FieldController::class, 'storeField'])->name('settings.fields.store');
        Route::delete('fields/{field}', [\App\Http\Controllers\Settings\FieldController::class, 'destroyField'])->name('settings.fields.destroy');
        Route::post('fields/{field}/options', [\App\Http\Controllers\Settings\FieldController::class, 'storeOption'])->name('settings.fields.options.store');
        Route::delete('field-options/{option}', [\App\Http\Controllers\Settings\FieldController::class, 'destroyOption'])->name('settings.field-options.destroy');
    });

    // Settings are admin-only: user management in particular can mint admin accounts and reset passwords.
    Route::resource('settings/users', UserController::class)->parameters(['users' => 'user'])->names('settings.users')->except('show')->middleware('admin');
    Route::resource('settings/departments', DepartmentController::class)->parameters(['departments' => 'department'])->names('settings.departments')->only(['index', 'store', 'update', 'destroy'])->middleware('admin');
    // Admin-only (checked inside the controller): workflow automation + date-limit rules.
    Route::get('settings/automation', [AutomationController::class, 'index'])->name('settings.automation.index');
    Route::post('settings/automation/rules', [AutomationController::class, 'storeRule'])->name('settings.automation.rules.store');
    Route::patch('settings/automation/rules/{rule}', [AutomationController::class, 'toggleRule'])->name('settings.automation.rules.toggle');
    Route::delete('settings/automation/rules/{rule}', [AutomationController::class, 'destroyRule'])->name('settings.automation.rules.destroy');
    Route::post('settings/automation/date-limits', [AutomationController::class, 'storeLimit'])->name('settings.automation.limits.store');
    Route::patch('settings/automation/date-limits/{limit}', [AutomationController::class, 'toggleLimit'])->name('settings.automation.limits.toggle');
    Route::delete('settings/automation/date-limits/{limit}', [AutomationController::class, 'destroyLimit'])->name('settings.automation.limits.destroy');

    Route::resource('settings/job-types', JobTypeController::class)->parameters(['job-types' => 'jobType'])->names('settings.job-types')->only(['index', 'store', 'update', 'destroy'])->middleware('admin');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('push/subscribe', [\App\Http\Controllers\PushController::class, 'subscribe'])->name('push.subscribe');
    Route::delete('push/subscribe', [\App\Http\Controllers\PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('push/test', [\App\Http\Controllers\PushController::class, 'test'])->middleware('throttle:6,1')->name('push.test');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
