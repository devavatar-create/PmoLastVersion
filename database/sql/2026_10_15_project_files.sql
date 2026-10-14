/* Avatar Electric PMO - 2026-10-15/16: project files in folders (project_folders, project_files) + the older project / cabinet attachments copied in
   (the stored files stay where they are) + who may see a folder (project_folders.department_ids). New tables are InnoDB; foreign keys only where both tables are InnoDB.
   Safe on any state and to run twice. Back up first; phpMyAdmin > Import. Run BEFORE uploading the matching code. */


/* 2026_10_15_000001_create_project_files_tables */
create table if not exists `project_folders` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `parent_id` bigint unsigned null, `cabinet_id` bigint unsigned null, `name` varchar(120) not null, `system` varchar(20) null, `restricted` tinyint(1) not null default '0', `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_folders` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_folders', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_folders` add constraint `project_folders_project_id_foreign` foreign key if not exists `project_folders_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_folders', 'project_folders') AND engine = 'InnoDB') = 1, 'alter table `project_folders` add constraint `project_folders_parent_id_foreign` foreign key if not exists `project_folders_parent_id_foreign` (`parent_id`) references `project_folders` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_folders', 'cabinets') AND engine = 'InnoDB') = 2, 'alter table `project_folders` add constraint `project_folders_cabinet_id_foreign` foreign key if not exists `project_folders_cabinet_id_foreign` (`cabinet_id`) references `cabinets` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_folders', 'users') AND engine = 'InnoDB') = 2, 'alter table `project_folders` add constraint `project_folders_created_by_foreign` foreign key if not exists `project_folders_created_by_foreign` (`created_by`) references `users` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `project_folders` add index if not exists `project_folders_project_id_parent_id_index`(`project_id`, `parent_id`);
create table if not exists `project_files` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `folder_id` bigint unsigned null, `cabinet_id` bigint unsigned null, `name` varchar(255) not null, `disk` varchar(20) not null default 'local', `path` varchar(500) not null, `mime` varchar(150) null, `size` bigint unsigned not null default '0', `version` int unsigned not null default '1', `previous_id` bigint unsigned null, `is_current` tinyint(1) not null default '1', `uploaded_by` bigint unsigned null, `deleted_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `project_files` engine = InnoDB;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'projects') AND engine = 'InnoDB') = 2, 'alter table `project_files` add constraint `project_files_project_id_foreign` foreign key if not exists `project_files_project_id_foreign` (`project_id`) references `projects` (`id`) on delete cascade', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'project_folders') AND engine = 'InnoDB') = 2, 'alter table `project_files` add constraint `project_files_folder_id_foreign` foreign key if not exists `project_files_folder_id_foreign` (`folder_id`) references `project_folders` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'cabinets') AND engine = 'InnoDB') = 2, 'alter table `project_files` add constraint `project_files_cabinet_id_foreign` foreign key if not exists `project_files_cabinet_id_foreign` (`cabinet_id`) references `cabinets` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'project_files') AND engine = 'InnoDB') = 1, 'alter table `project_files` add constraint `project_files_previous_id_foreign` foreign key if not exists `project_files_previous_id_foreign` (`previous_id`) references `project_files` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'users') AND engine = 'InnoDB') = 2, 'alter table `project_files` add constraint `project_files_uploaded_by_foreign` foreign key if not exists `project_files_uploaded_by_foreign` (`uploaded_by`) references `users` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
SET @pmo_sql = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_files', 'users') AND engine = 'InnoDB') = 2, 'alter table `project_files` add constraint `project_files_deleted_by_foreign` foreign key if not exists `project_files_deleted_by_foreign` (`deleted_by`) references `users` (`id`) on delete set null', 'DO 0');
PREPARE pmo_st FROM @pmo_sql; EXECUTE pmo_st; DEALLOCATE PREPARE pmo_st;
alter table `project_files` add index if not exists `project_files_project_id_folder_id_is_current_index`(`project_id`, `folder_id`, `is_current`);
INSERT INTO project_files (project_id, folder_id, cabinet_id, name, disk, path, mime, size, version, is_current, uploaded_by, created_at, updated_at)
            SELECT project_id, NULL, NULL, original_name, 'public', CONCAT('uploads/project-attachments/', path), mime_type, size, 1, 1, uploaded_by, created_at, updated_at FROM project_attachments pa WHERE NOT EXISTS (SELECT 1 FROM project_files pf WHERE pf.disk = 'public' AND pf.path = CONCAT('uploads/project-attachments/', pa.path));
INSERT INTO project_files (project_id, folder_id, cabinet_id, name, disk, path, mime, size, version, is_current, uploaded_by, created_at, updated_at)
            SELECT c.project_id, NULL, a.cabinet_id, a.original_name, 'public', CONCAT('uploads/cabinet-attachments/', a.path), a.mime_type, a.size, 1, 1, a.uploaded_by, a.created_at, a.updated_at
            FROM cabinet_attachments a JOIN cabinets c ON c.id = a.cabinet_id WHERE NOT EXISTS (SELECT 1 FROM project_files pf WHERE pf.disk = 'public' AND pf.path = CONCAT('uploads/cabinet-attachments/', a.path));
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_10_15_000001_create_project_files_tables', b FROM (SELECT COALESCE(MAX(`batch`), 0) + 1 AS b FROM `migrations`) x
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_15_000001_create_project_files_tables');

/* 2026_10_16_000001_add_department_ids_to_project_folders */
alter table `project_folders` add column if not exists `department_ids` text null after `restricted`;
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_10_16_000001_add_department_ids_to_project_folders', b FROM (SELECT COALESCE(MAX(`batch`), 0) + 1 AS b FROM `migrations`) x
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_16_000001_add_department_ids_to_project_folders');
