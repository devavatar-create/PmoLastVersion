/* Avatar Electric PMO - REPAIR for production (MariaDB 10.6): everything since 2026-10-05, safe to run on a partly done database.
   New tables are InnoDB (the host defaults to MyISAM); foreign keys are added only where both tables are InnoDB.
   Creates only what is missing (IF NOT EXISTS), skips what is already there, then records each migration ONCE.
   Back up first. Use phpMyAdmin > Import (or paste the whole file into the SQL tab). Running it twice is harmless. */

/* 2026_10_05_000001_migrate_remarks_to_activity_notes */

            INSERT INTO activity_logs (project_id, user_id, loggable_type, loggable_id, action, description, created_at, updated_at)
            SELECT t.id, NULL, 'App\\Models\\Project', t.id, 'remark_migrated', t.remark, '2026-10-09 09:00:22', '2026-10-09 09:00:22'
            FROM projects t
            WHERE t.remark IS NOT NULL AND t.remark <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM activity_logs a
                  WHERE a.loggable_type = 'App\\Models\\Project' AND a.loggable_id = t.id AND a.action = 'remark_migrated'
              )
        ;

            INSERT INTO activity_logs (project_id, user_id, loggable_type, loggable_id, action, description, created_at, updated_at)
            SELECT t.project_id, NULL, 'App\\Models\\Cabinet', t.id, 'remark_migrated', t.remark, '2026-10-09 09:00:22', '2026-10-09 09:00:22'
            FROM cabinets t
            WHERE t.remark IS NOT NULL AND t.remark <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM activity_logs a
                  WHERE a.loggable_type = 'App\\Models\\Cabinet' AND a.loggable_id = t.id AND a.action = 'remark_migrated'
              )
        ;

/* 2026_10_05_000002_add_soft_deletes_for_trash */
alter table `projects` add column if not exists `deleted_at` timestamp null;
alter table `cabinets` add column if not exists `deleted_at` timestamp null;
alter table `cabinet_tasks` add column if not exists `deleted_at` timestamp null;
alter table `cabinet_subtasks` add column if not exists `deleted_at` timestamp null;
alter table `cabinet_checklists` add column if not exists `deleted_at` timestamp null;
alter table `projects` drop index if exists `projects_project_no_unique`;
alter table `projects` add index if not exists `projects_project_no_index`(`project_no`(191));
alter table `cabinets` drop index if exists `cabinets_mo_no_unique`;
alter table `cabinets` add index if not exists `cabinets_mo_no_index`(`mo_no`(191));

/* 2026_10_05_000003_create_notifications_table */
create table if not exists `notifications` (`id` char(36) not null, `type` varchar(255) not null, `notifiable_type` varchar(255) not null, `notifiable_id` bigint unsigned not null, `data` text not null, `read_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `notifications` engine = InnoDB;
alter table `notifications` add index if not exists `notifications_notifiable_type_notifiable_id_index`(`notifiable_type`(191), `notifiable_id`);

/* 2026_10_07_000001_create_subtask_stars_table */
create table if not exists `subtask_stars` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `cabinet_subtask_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `subtask_stars` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('subtask_stars', 'users') AND engine = 'InnoDB') = 2, 'alter table `subtask_stars` add constraint `subtask_stars_user_id_foreign` foreign key if not exists `subtask_stars_user_id_foreign` (`user_id`) references `users` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('subtask_stars', 'cabinet_subtasks') AND engine = 'InnoDB') = 2, 'alter table `subtask_stars` add constraint `subtask_stars_cabinet_subtask_id_foreign` foreign key if not exists `subtask_stars_cabinet_subtask_id_foreign` (`cabinet_subtask_id`) references `cabinet_subtasks` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `subtask_stars` add unique index if not exists `subtask_stars_user_id_cabinet_subtask_id_unique`(`user_id`, `cabinet_subtask_id`);

/* 2026_10_07_000002_create_automation_and_date_limit_rules_tables */
create table if not exists `automation_rules` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `department_id` bigint unsigned null, `trigger` varchar(255) not null, `action` varchar(255) not null, `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `automation_rules` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('automation_rules', 'departments') AND engine = 'InnoDB') = 2, 'alter table `automation_rules` add constraint `automation_rules_department_id_foreign` foreign key if not exists `automation_rules_department_id_foreign` (`department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
create table if not exists `date_limit_rules` (`id` bigint unsigned not null auto_increment primary key, `anchor_department_id` bigint unsigned not null, `blocked_department_id` bigint unsigned not null, `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `date_limit_rules` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('date_limit_rules', 'departments') AND engine = 'InnoDB') = 2, 'alter table `date_limit_rules` add constraint `date_limit_rules_anchor_department_id_foreign` foreign key if not exists `date_limit_rules_anchor_department_id_foreign` (`anchor_department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('date_limit_rules', 'departments') AND engine = 'InnoDB') = 2, 'alter table `date_limit_rules` add constraint `date_limit_rules_blocked_department_id_foreign` foreign key if not exists `date_limit_rules_blocked_department_id_foreign` (`blocked_department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `date_limit_rules` add unique index if not exists `date_limit_rules_pair_unique`(`anchor_department_id`, `blocked_department_id`);

/* 2026_10_07_000003_create_project_requests_table */
create table if not exists `project_requests` (`id` bigint unsigned not null auto_increment primary key, `requester_name` varchar(255) not null, `contact` varchar(255) not null, `customer_name` varchar(255) not null, `title` varchar(255) not null, `description` text null, `quantity` int unsigned null, `needed_by` date null, `status` varchar(255) not null default 'pending', `reject_reason` varchar(500) null, `project_id` bigint unsigned null, `handled_by` bigint unsigned null, `handled_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_requests` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_requests', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_requests` add constraint `project_requests_project_id_foreign` foreign key if not exists `project_requests_project_id_foreign` (`project_id`) references `projects` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_requests', 'users') AND engine = 'InnoDB') = 2, 'alter table `project_requests` add constraint `project_requests_handled_by_foreign` foreign key if not exists `project_requests_handled_by_foreign` (`handled_by`) references `users` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `project_requests` add index if not exists `project_requests_status_index`(`status`(191));

/* 2026_10_08_000001_create_app_settings_and_notification_preferences */
create table if not exists `app_settings` (`key` varchar(255) not null, `value` json null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `app_settings` engine = InnoDB;
create table if not exists `notification_preferences` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `enabled` tinyint(1) not null default '1', `only_my_department` tinyint(1) not null default '0', `progress` tinyint(1) not null default '1', `completed` tinyint(1) not null default '1', `reminders` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `notification_preferences` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('notification_preferences', 'users') AND engine = 'InnoDB') = 2, 'alter table `notification_preferences` add constraint `notification_preferences_user_id_foreign` foreign key if not exists `notification_preferences_user_id_foreign` (`user_id`) references `users` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `notification_preferences` add unique index if not exists `notification_preferences_user_id_unique`(`user_id`);
alter table `departments` add column if not exists `color` varchar(7) null after `description`;
alter table `departments` add column if not exists `icon` varchar(16) null after `color`;
alter table `departments` add column if not exists `sees_all` tinyint(1) not null default '0' after `icon`;

/* 2026_10_08_000002_create_department_fields */
create table if not exists `department_fields` (`id` bigint unsigned not null auto_increment primary key, `department_id` bigint unsigned not null, `name` varchar(255) not null, `type` varchar(10) not null default 'select', `sort` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `department_fields` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('department_fields', 'departments') AND engine = 'InnoDB') = 2, 'alter table `department_fields` add constraint `department_fields_department_id_foreign` foreign key if not exists `department_fields_department_id_foreign` (`department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
create table if not exists `department_field_options` (`id` bigint unsigned not null auto_increment primary key, `department_field_id` bigint unsigned not null, `group_name` varchar(255) null, `label` varchar(255) not null, `sort` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `department_field_options` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('department_field_options', 'department_fields') AND engine = 'InnoDB') = 2, 'alter table `department_field_options` add constraint `department_field_options_department_field_id_foreign` foreign key if not exists `department_field_options_department_field_id_foreign` (`department_field_id`) references `department_fields` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
create table if not exists `project_field_values` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `department_field_id` bigint unsigned not null, `value` varchar(500) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_field_values` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_field_values', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_field_values` add constraint `project_field_values_project_id_foreign` foreign key if not exists `project_field_values_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_field_values', 'department_fields') AND engine = 'InnoDB') = 2, 'alter table `project_field_values` add constraint `project_field_values_department_field_id_foreign` foreign key if not exists `project_field_values_department_field_id_foreign` (`department_field_id`) references `department_fields` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `project_field_values` add unique index if not exists `project_field_unique`(`project_id`, `department_field_id`);

/* 2026_10_09_000001_create_boards_statuses_and_labels */
create table if not exists `board_statuses` (`id` bigint unsigned not null auto_increment primary key, `key` varchar(20) null, `name` varchar(40) not null, `color` varchar(7) not null, `is_done` tinyint(1) not null default '0', `sort` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `board_statuses` engine = InnoDB;
alter table `board_statuses` add unique index if not exists `board_statuses_key_unique`(`key`);
insert ignore into `board_statuses` (`key`, `name`, `color`, `is_done`, `sort`, `created_at`, `updated_at`) values ('todo', 'รอรับงาน', '#98a2b3', 0, 1, '2026-10-09 09:00:22', '2026-10-09 09:00:22');
insert ignore into `board_statuses` (`key`, `name`, `color`, `is_done`, `sort`, `created_at`, `updated_at`) values ('doing', 'กำลังทำ', '#3b82f6', 0, 2, '2026-10-09 09:00:22', '2026-10-09 09:00:22');
insert ignore into `board_statuses` (`key`, `name`, `color`, `is_done`, `sort`, `created_at`, `updated_at`) values ('accepted', 'รับงานแล้ว', '#f59e0b', 0, 3, '2026-10-09 09:00:22', '2026-10-09 09:00:22');
insert ignore into `board_statuses` (`key`, `name`, `color`, `is_done`, `sort`, `created_at`, `updated_at`) values ('done', 'เสร็จแล้ว', '#10b981', 1, 4, '2026-10-09 09:00:22', '2026-10-09 09:00:22');
insert ignore into `board_statuses` (`key`, `name`, `color`, `is_done`, `sort`, `created_at`, `updated_at`) values ('late', 'เกินกำหนด', '#ef4444', 0, 5, '2026-10-09 09:00:22', '2026-10-09 09:00:22');
create table if not exists `boards` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(60) not null, `sort` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `boards` engine = InnoDB;
create table if not exists `board_labels` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(40) not null, `color` varchar(7) not null default '#7b68ee', `sort` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `board_labels` engine = InnoDB;
create table if not exists `board_label_project` (`board_label_id` bigint unsigned not null, `project_id` bigint unsigned not null, primary key (`board_label_id`, `project_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `board_label_project` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('board_label_project', 'board_labels') AND engine = 'InnoDB') = 2, 'alter table `board_label_project` add constraint `board_label_project_board_label_id_foreign` foreign key if not exists `board_label_project_board_label_id_foreign` (`board_label_id`) references `board_labels` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('board_label_project', 'projects') AND engine = 'InnoDB') = 2, 'alter table `board_label_project` add constraint `board_label_project_project_id_foreign` foreign key if not exists `board_label_project_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `projects` add column if not exists `board_id` bigint unsigned null after `color`;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('projects', 'boards') AND engine = 'InnoDB') = 2, 'alter table `projects` add constraint `projects_board_id_foreign` foreign key if not exists `projects_board_id_foreign` (`board_id`) references `boards` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `projects` add column if not exists `board_status_id` bigint unsigned null after `board_id`;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('projects', 'board_statuses') AND engine = 'InnoDB') = 2, 'alter table `projects` add constraint `projects_board_status_id_foreign` foreign key if not exists `projects_board_status_id_foreign` (`board_status_id`) references `board_statuses` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;

/* 2026_10_10_000001_planning_full_page_features */
alter table `cabinets` add column if not exists `board_status_id` bigint unsigned null after `status`;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cabinets', 'board_statuses') AND engine = 'InnoDB') = 2, 'alter table `cabinets` add constraint `cabinets_board_status_id_foreign` foreign key if not exists `cabinets_board_status_id_foreign` (`board_status_id`) references `board_statuses` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
create table if not exists `project_user` (`project_id` bigint unsigned not null, `user_id` bigint unsigned not null, primary key (`project_id`, `user_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_user` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_user', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_user` add constraint `project_user_project_id_foreign` foreign key if not exists `project_user_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_user', 'users') AND engine = 'InnoDB') = 2, 'alter table `project_user` add constraint `project_user_user_id_foreign` foreign key if not exists `project_user_user_id_foreign` (`user_id`) references `users` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `users` add column if not exists `avatar_path` varchar(255) null after `position`;
alter table `department_fields` add column if not exists `show_on_chip` tinyint(1) not null default '0' after `type`;
create table if not exists `status_rules` (`id` bigint unsigned not null auto_increment primary key, `target` varchar(10) not null, `logic` varchar(3) not null default 'and', `conditions` json not null, `board_status_id` bigint unsigned not null, `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `status_rules` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('status_rules', 'board_statuses') AND engine = 'InnoDB') = 2, 'alter table `status_rules` add constraint `status_rules_board_status_id_foreign` foreign key if not exists `status_rules_board_status_id_foreign` (`board_status_id`) references `board_statuses` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;

/* 2026_10_10_000002_create_push_subscriptions_table */
create table if not exists `push_subscriptions` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `endpoint` varchar(700) not null, `endpoint_hash` varchar(64) not null, `p256dh` varchar(255) not null, `auth` varchar(255) not null, `user_agent` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `push_subscriptions` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('push_subscriptions', 'users') AND engine = 'InnoDB') = 2, 'alter table `push_subscriptions` add constraint `push_subscriptions_user_id_foreign` foreign key if not exists `push_subscriptions_user_id_foreign` (`user_id`) references `users` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `push_subscriptions` add unique index if not exists `push_subscriptions_endpoint_hash_unique`(`endpoint_hash`);

/* 2026_10_11_000001_add_comment_and_due_switches_to_notification_preferences */
alter table `notification_preferences` add column if not exists `comments` tinyint(1) not null default '1' after `reminders`;
alter table `notification_preferences` add column if not exists `due_changes` tinyint(1) not null default '1' after `comments`;

/* 2026_10_12_000001_create_project_department_statuses_table */
create table if not exists `project_department_statuses` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `department_id` bigint unsigned not null, `board_status_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_department_statuses` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_department_statuses', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_department_statuses` add constraint `project_department_statuses_project_id_foreign` foreign key if not exists `project_department_statuses_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_department_statuses', 'departments') AND engine = 'InnoDB') = 2, 'alter table `project_department_statuses` add constraint `project_department_statuses_department_id_foreign` foreign key if not exists `project_department_statuses_department_id_foreign` (`department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_department_statuses', 'board_statuses') AND engine = 'InnoDB') = 2, 'alter table `project_department_statuses` add constraint `project_department_statuses_board_status_id_foreign` foreign key if not exists `project_department_statuses_board_status_id_foreign` (`board_status_id`) references `board_statuses` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `project_department_statuses` add unique index if not exists `project_department_status_unique`(`project_id`, `department_id`);

/* 2026_10_12_000002_create_department_links_table */
create table if not exists `department_links` (`id` bigint unsigned not null auto_increment primary key, `from_department_id` bigint unsigned not null, `to_department_id` bigint unsigned not null, `date_label` varchar(40) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `department_links` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('department_links', 'departments') AND engine = 'InnoDB') = 2, 'alter table `department_links` add constraint `department_links_from_department_id_foreign` foreign key if not exists `department_links_from_department_id_foreign` (`from_department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('department_links', 'departments') AND engine = 'InnoDB') = 2, 'alter table `department_links` add constraint `department_links_to_department_id_foreign` foreign key if not exists `department_links_to_department_id_foreign` (`to_department_id`) references `departments` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `department_links` add unique index if not exists `department_links_unique`(`from_department_id`, `to_department_id`);
alter table `cabinet_checklists` add column if not exists `expected_date` date null after `remark`;

/* record each migration once (the earlier attempts left repeated rows) - last, so it only happens when everything above worked */
DELETE FROM `migrations` WHERE `migration` >= '2026_10_05';
SET @pmo_batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2026_10_05_000001_migrate_remarks_to_activity_notes', @pmo_batch),
('2026_10_05_000002_add_soft_deletes_for_trash', @pmo_batch),
('2026_10_05_000003_create_notifications_table', @pmo_batch),
('2026_10_07_000001_create_subtask_stars_table', @pmo_batch),
('2026_10_07_000002_create_automation_and_date_limit_rules_tables', @pmo_batch),
('2026_10_07_000003_create_project_requests_table', @pmo_batch),
('2026_10_08_000001_create_app_settings_and_notification_preferences', @pmo_batch),
('2026_10_08_000002_create_department_fields', @pmo_batch),
('2026_10_09_000001_create_boards_statuses_and_labels', @pmo_batch),
('2026_10_10_000001_planning_full_page_features', @pmo_batch),
('2026_10_10_000002_create_push_subscriptions_table', @pmo_batch),
('2026_10_11_000001_add_comment_and_due_switches_to_notification_preferences', @pmo_batch),
('2026_10_12_000001_create_project_department_statuses_table', @pmo_batch),
('2026_10_12_000002_create_department_links_table', @pmo_batch);
