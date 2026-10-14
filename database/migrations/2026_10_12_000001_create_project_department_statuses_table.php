<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a status Admin / PM picked by hand for ONE department's work in a project (none = worked out from that work)
        Schema::create('project_department_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_status_id')->constrained('board_statuses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'department_id'], 'project_department_status_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_department_statuses');
    }
};
