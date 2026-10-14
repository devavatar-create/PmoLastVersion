/* read only: what the deploy still has to do on this database (missing = empty -> all done). Run in the SQL tab. */
SELECT VERSION() AS db_version, COUNT(*) AS missing_count, GROUP_CONCAT(what ORDER BY what SEPARATOR ' , ') AS missing
FROM (
SELECT 'T:notifications' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notifications') AS ok
UNION ALL SELECT 'T:subtask_stars' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subtask_stars') AS ok
UNION ALL SELECT 'T:automation_rules' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'automation_rules') AS ok
UNION ALL SELECT 'T:date_limit_rules' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'date_limit_rules') AS ok
UNION ALL SELECT 'T:project_requests' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'project_requests') AS ok
UNION ALL SELECT 'T:app_settings' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_settings') AS ok
UNION ALL SELECT 'T:notification_preferences' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notification_preferences') AS ok
UNION ALL SELECT 'T:department_fields' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'department_fields') AS ok
UNION ALL SELECT 'T:department_field_options' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'department_field_options') AS ok
UNION ALL SELECT 'T:project_field_values' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'project_field_values') AS ok
UNION ALL SELECT 'T:board_statuses' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'board_statuses') AS ok
UNION ALL SELECT 'T:boards' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'boards') AS ok
UNION ALL SELECT 'T:board_labels' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'board_labels') AS ok
UNION ALL SELECT 'T:board_label_project' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'board_label_project') AS ok
UNION ALL SELECT 'T:project_user' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'project_user') AS ok
UNION ALL SELECT 'T:status_rules' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'status_rules') AS ok
UNION ALL SELECT 'T:push_subscriptions' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'push_subscriptions') AS ok
UNION ALL SELECT 'T:project_department_statuses' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'project_department_statuses') AS ok
UNION ALL SELECT 'T:department_links' AS what, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'department_links') AS ok
UNION ALL SELECT 'C:projects.deleted_at', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'deleted_at')
UNION ALL SELECT 'C:cabinets.deleted_at', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinets' AND column_name = 'deleted_at')
UNION ALL SELECT 'C:cabinet_tasks.deleted_at', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinet_tasks' AND column_name = 'deleted_at')
UNION ALL SELECT 'C:cabinet_subtasks.deleted_at', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinet_subtasks' AND column_name = 'deleted_at')
UNION ALL SELECT 'C:cabinet_checklists.deleted_at', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinet_checklists' AND column_name = 'deleted_at')
UNION ALL SELECT 'C:departments.color', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'departments' AND column_name = 'color')
UNION ALL SELECT 'C:departments.icon', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'departments' AND column_name = 'icon')
UNION ALL SELECT 'C:departments.sees_all', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'departments' AND column_name = 'sees_all')
UNION ALL SELECT 'C:projects.board_id', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'board_id')
UNION ALL SELECT 'C:projects.board_status_id', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'board_status_id')
UNION ALL SELECT 'C:cabinets.board_status_id', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinets' AND column_name = 'board_status_id')
UNION ALL SELECT 'C:users.avatar_path', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar_path')
UNION ALL SELECT 'C:department_fields.show_on_chip', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'department_fields' AND column_name = 'show_on_chip')
UNION ALL SELECT 'C:notification_preferences.comments', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'comments')
UNION ALL SELECT 'C:notification_preferences.due_changes', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'due_changes')
UNION ALL SELECT 'C:cabinet_checklists.expected_date', (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cabinet_checklists' AND column_name = 'expected_date')
UNION ALL SELECT 'I:projects.projects_project_no_index', (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'projects' AND index_name = 'projects_project_no_index') > 0
UNION ALL SELECT 'I:cabinets.cabinets_mo_no_index', (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cabinets' AND index_name = 'cabinets_mo_no_index') > 0
UNION ALL SELECT 'DROP:projects.projects_project_no_unique', (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'projects' AND index_name = 'projects_project_no_unique') = 0
UNION ALL SELECT 'DROP:cabinets.cabinets_mo_no_unique', (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cabinets' AND index_name = 'cabinets_mo_no_unique') = 0
) x WHERE ok = 0;
