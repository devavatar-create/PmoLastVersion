# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Avatar Electric PMO: project and production tracking for electrical cabinets. Laravel 12, PHP 8.2, Blade + Tailwind (Vite) + Alpine.js, server-rendered. UI text and activity-log messages are in Thai; keep user-facing strings Thai.

## Commands

```bash
composer setup                          # install, .env, key, migrate, npm build
composer dev                            # serve + queue + pail + vite together
php artisan migrate --seed              # DemoProjectSeeder gives PJ-690001 with 4 cabinets
npm run dev | npm run build             # Vite

php artisan test                        # whole suite (composer test also clears config first)
php artisan test --filter=TrashAndLockTest
php artisan test tests/Feature/PlanningSyncTest.php

php artisan planning:build              # regenerate Planning assets (see below)
php artisan pmo:send-reminders          # due-soon/overdue notices; scheduled daily 08:00 in routes/console.php
php artisan pmo:migration-sql --since=<last migration prod has> --output=database/sql/<name>.sql
```

Tests run on in-memory **sqlite** (phpunit.xml) while the app runs on MySQL, so migrations and raw SQL must work on both (e.g. no `UPDATE ... JOIN`). Run the full suite after refactors: it is the main safety net.

## Production constraints

Production has **no shell, no `npm`, and no `artisan`**: schema changes go through phpMyAdmin and front-end assets must be built locally and uploaded. Consequences:
- Never write hand-made `.sql` for schema changes; generate it from the migrations with `pmo:migration-sql` and run it **before** uploading the matching code (and back up first).
- `public/build/` and `public/planning-assets/` are what prod serves; `public/build` is gitignored, so it has to be handed over separately.
- `DisableResponseCaching` middleware sends no-store on every web response because shared-hosting caches (LiteSpeed) otherwise show stale data. Don't remove it.
- Reminders need a `* * * * * php artisan schedule:run` cron entry.

## Domain model

`Project → Cabinet → CabinetTask → CabinetSubtask → CabinetChecklist`. Cabinets are created by copying a `CabinetTemplate` tree (`CabinetTemplateService`, `CabinetCopyService`); editing a cabinet never touches the template.

- **Progress** (`ProgressService`) is persisted bottom-up on every checklist toggle: checklist % → subtask → task → cabinet (plain averages; `weight` columns exist but are unused). Project `task_progress` and `production_progress` are computed and always shown separately. Derived status is `not_started/in_progress/completed`; `on_hold` is preserved.
- **Assignment workflow** (`SubtaskAssignmentService`): a subtask is dispatched to a department and moves `UNASSIGNED → ASSIGNED → ACCEPTED → IN_PROGRESS → COMPLETED`. The department is locked once accepted. Every transition re-reads the row under `lockForUpdate`, checks the actor, writes the activity log and notifies. Only `canDispatchWork()` roles (admin, project_manager) may dispatch; roles are on `User` (`admin`, `project_manager`, `sales`, `production`, `member`).
- **Visibility**: PMO roles see all departments; others only their own department's subtasks. `WorkScope`, `DepartmentSchedule::resolveDepartment` and `PlanningBoard` implement this and must stay in agreement. Enforce on the server, not just in the UI.
- **Checklist pairs** (`SubtaskPairs`, set on the cabinet template and per cabinet): a Sub Task comparing with another shares its list (`SharedChecklist`). The receiver holds a copy of each source item (`cabinet_checklists.source_checklist_id`); add / rename / order / delete happen at the source and reach the copies, a copy ticked by the receiver blocks deleting it at the source, and each side ticks its own copy. Any new code path that adds, renames, moves or deletes cabinet checklist items must go through `SharedChecklist`.
- **Automation** (`AutomationService`): admin-defined rules run after a checklist tick and call the normal `start()/complete()` service methods, so all workflow checks still apply; they only move work forward. Date-limit rules constrain dates.

## Cross-cutting model behaviour (traits in `app/Models/Concerns`)

- `LogsActivity`: a model opts in and overrides `activityProjectId()`, `activityLabel()`, `activityLoggableFields()`. Only listed fields are diffed; progress/status are deliberately excluded to avoid roll-up noise. Suppress the auto message with `Model::withoutEvents(...)`. `ActivityTreeBuilder` and `ActivityLogController` render the log; project comments are `activity_logs` rows with action `note` (the old `remark` columns were migrated into them).
- `GuardsClosedProject` + `ProjectLock`: a project with status `completed`/`cancelled` is read-only, enforced at model events (create/update/delete) via `ProjectLockedException` (HTTP 423 / flash error). Code that uses `saveQuietly()` or mass updates bypasses model events and must call `ProjectLock::assertOpen()` itself. Comments and reopening stay allowed.
- `CascadesSoftDeletes`: deleting a project/cabinet/task/subtask soft-deletes the subtree with the **same** `deleted_at` via mass updates (no per-row log spam); restore brings back only rows carrying that timestamp. `project_no` / `mo_no` are non-unique indexes so deleted numbers can be reused.
- Notifications: `NotificationService` + `PmoNotice` (database channel); per-user preferences in `NotificationPreference`, global settings in `AppSetting`.

## Planning page (generated assets, do not hand-edit)

`/planning/board` is the main working page (`/my-department` redirects to it). It is a single-file SPA, `resources/planning/index.html`, fed by `PlanningBoard` (maps PMO data to the JSON the page expects) and written back through `PlanningSync`, which applies each client operation through the same services and rules as the rest of the app (refused ops bounce back with a Thai reason). The page never writes data itself and cannot create projects/cabinets/stages.

`public/planning-assets/app.{css,js}` and `resources/planning/shell.html` are **generated** from `index.html` by `php artisan planning:build`. Edit the source, rebuild, and commit all of them; `PlanningPageTest` fails if they are stale.

## Other structure

- `MyDepartmentController` is thin; query/scope/serialising logic lives in `DepartmentSchedule` and `ProjectModal`. Prefer putting logic in `app/Services` over controllers.
- Project fields such as owner / sales person are plain text columns (`project_owner`, `sales_person`), not user FKs. Extra per-department project fields are `DepartmentField` / `ProjectFieldValue`; Planning boards, statuses and labels are `Board` / `BoardStatus` / `BoardLabel`.
- The public `/request` intake form was removed (2026-10). The `project_requests` table and `ProjectRequest` model are kept only for the old data; nothing in the app reads or writes them.
- Exports are CSV via `App\Support\Csv`.
- Admin-only settings routes use the `admin` middleware alias (`EnsureAdmin`); the `gzip` alias compresses the planning responses.
