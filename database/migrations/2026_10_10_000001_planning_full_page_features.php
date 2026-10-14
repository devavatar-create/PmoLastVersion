<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a status picked by hand for one cabinet (null = worked out from its departments' progress, as before)
        Schema::table('cabinets', function (Blueprint $table) {
            $table->foreignId('board_status_id')->nullable()->after('status')->constrained('board_statuses')->nullOnDelete();
        });

        // people in charge of a project, on top of the departments that hold its work
        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('position');
        });

        Schema::table('department_fields', function (Blueprint $table) {
            $table->boolean('show_on_chip')->default(false)->after('type');   // show the chosen value on the calendar bars
        });

        // Settings > กฎสถานะ: "when <conditions> -> set the Planning status of the cabinet / project"
        Schema::create('status_rules', function (Blueprint $table) {
            $table->id();
            $table->string('target', 10);                       // cabinet | project
            $table->string('logic', 3)->default('and');         // and | or
            $table->json('conditions');                         // [{when: all_done|dept_done|dept_started|item|status|not_status, department_id?, item?, status_id?}]
            $table->foreignId('board_status_id')->constrained('board_statuses')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_rules');
        Schema::table('department_fields', fn (Blueprint $t) => $t->dropColumn('show_on_chip'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('avatar_path'));
        Schema::dropIfExists('project_user');
        Schema::table('cabinets', fn (Blueprint $t) => $t->dropConstrainedForeignId('board_status_id'));
    }
};
