<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a project's files, in folders like on a computer (App\Services\ProjectFiles)
        Schema::create('project_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_folders')->cascadeOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->cascadeOnDelete();   // a cabinet's own folder (made by the system)
            $table->string('name', 120);
            $table->string('system', 20)->nullable();              // 'cabinets' (the container) / 'cabinet' / 'default'
            $table->boolean('restricted')->default(false);          // only Admin / PM see it
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('project_folders')->nullOnDelete();   // null = the project's top level
            $table->foreignId('cabinet_id')->nullable()->constrained()->nullOnDelete();   // an older cabinet attachment, until it is put in that cabinet's folder
            $table->string('name');                                 // what people see (renamable)
            $table->string('disk', 20)->default('local');           // local = storage/app/private; public = an older upload under public/
            $table->string('path', 500);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('previous_id')->nullable()->constrained('project_files')->nullOnDelete();   // the version this one replaced
            $table->boolean('is_current')->default(true);            // false = an older version (shown in its history only)
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'folder_id', 'is_current']);
        });

        // the attachments made before this (project / cabinet pages) become files; the stored files stay where they are
        $cat = fn (string $prefix, string $col) => DB::getDriverName() === 'sqlite' ? "'{$prefix}' || {$col}" : "CONCAT('{$prefix}', {$col})";
        DB::statement("INSERT INTO project_files (project_id, folder_id, cabinet_id, name, disk, path, mime, size, version, is_current, uploaded_by, created_at, updated_at)
            SELECT project_id, NULL, NULL, original_name, 'public', {$cat('uploads/project-attachments/', 'path')}, mime_type, size, 1, 1, uploaded_by, created_at, updated_at FROM project_attachments");
        DB::statement("INSERT INTO project_files (project_id, folder_id, cabinet_id, name, disk, path, mime, size, version, is_current, uploaded_by, created_at, updated_at)
            SELECT c.project_id, NULL, a.cabinet_id, a.original_name, 'public', {$cat('uploads/cabinet-attachments/', 'a.path')}, a.mime_type, a.size, 1, 1, a.uploaded_by, a.created_at, a.updated_at
            FROM cabinet_attachments a JOIN cabinets c ON c.id = a.cabinet_id");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('project_folders');
    }
};
