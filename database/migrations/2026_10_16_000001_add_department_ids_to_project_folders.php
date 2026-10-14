<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_folders', function (Blueprint $table) {
            $table->text('department_ids')->nullable()->after('restricted');   // JSON list: only these departments (plus Admin / PM) see the folder
        });
    }

    public function down(): void
    {
        Schema::table('project_folders', function (Blueprint $table) {
            $table->dropColumn('department_ids');
        });
    }
};
