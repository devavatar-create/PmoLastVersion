/* Avatar Electric PMO - 2026-10-13 + 2026-10-14:
   project form (phone, contract value, margin, job types Service / ตู้ / Trading) + cabinet_subtasks.assigned_at ("รอรับเกิน n วัน").
   Includes everything in 2026_10_13_project_form_fields.sql - use this file alone. Safe on any state and to run twice.
   Back up first; phpMyAdmin > Import. Run BEFORE uploading the matching code. */


/* 2026_10_13_000001_add_phone_value_margin_to_projects */
alter table `projects` add column if not exists `customer_phone` varchar(50) null after `customer_name`;
alter table `projects` add column if not exists `contract_value` decimal(15, 2) null after `payment_terms`;
alter table `projects` add column if not exists `margin` decimal(15, 2) null after `contract_value`;
insert ignore into `job_types` (`name`, `is_active`, `created_at`, `updated_at`) values ('Service', 1, '2026-10-09 11:19:18', '2026-10-09 11:19:18');
insert ignore into `job_types` (`name`, `is_active`, `created_at`, `updated_at`) values ('ตู้', 1, '2026-10-09 11:19:18', '2026-10-09 11:19:18');
insert ignore into `job_types` (`name`, `is_active`, `created_at`, `updated_at`) values ('Trading', 1, '2026-10-09 11:19:18', '2026-10-09 11:19:18');
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_10_13_000001_add_phone_value_margin_to_projects', b FROM (SELECT COALESCE(MAX(`batch`), 0) + 1 AS b FROM `migrations`) x
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_13_000001_add_phone_value_margin_to_projects');

/* 2026_10_14_000001_add_assigned_at_to_cabinet_subtasks */
alter table `cabinet_subtasks` add column if not exists `assigned_at` timestamp null after `assignment_status`;
update `cabinet_subtasks` set `assigned_at` = COALESCE(updated_at, created_at) where `assignment_status` = 'ASSIGNED' and `assigned_at` is null;
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_10_14_000001_add_assigned_at_to_cabinet_subtasks', b FROM (SELECT COALESCE(MAX(`batch`), 0) + 1 AS b FROM `migrations`) x
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_14_000001_add_assigned_at_to_cabinet_subtasks');
