<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a template's Sub Task shows another Sub Task's checklist next to its own (e.g. ตรวจรับอุปกรณ์ ← สั่งซื้ออุปกรณ์);
        // copied to every cabinet made from the template. Replaces Settings > การส่งต่องาน (department_links), kept for its data.
        Schema::create('cabinet_subtask_template_pairs', function (Blueprint $table) {
            $table->id();
            // short constraint names: MySQL allows 64 characters
            $table->foreignId('cabinet_subtask_template_id')->constrained('cabinet_subtask_templates', 'id', 'cst_pairs_to_fk')->cascadeOnDelete();   // the one that compares
            $table->foreignId('source_subtask_template_id')->constrained('cabinet_subtask_templates', 'id', 'cst_pairs_from_fk')->cascadeOnDelete();  // the one it compares with
            $table->timestamps();
            $table->unique(['cabinet_subtask_template_id', 'source_subtask_template_id'], 'cst_pairs_unique');
        });

        // the same, in a cabinet
        Schema::create('cabinet_subtask_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_subtask_id')->constrained('cabinet_subtasks', 'id', 'cs_pairs_to_fk')->cascadeOnDelete();
            $table->foreignId('source_subtask_id')->constrained('cabinet_subtasks', 'id', 'cs_pairs_from_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cabinet_subtask_id', 'source_subtask_id'], 'cs_pairs_unique');
            $table->index('source_subtask_id');
        });

        // the date a department gives each item for the one comparing with it (e.g. จัดซื้อ: "วันของเข้า"), now kept on the department
        Schema::table('departments', function (Blueprint $table) {
            $table->string('handoff_date_label', 40)->nullable()->after('sees_all');
        });

        // one plain statement (no UPDATE ... JOIN) so it runs on MySQL and sqlite and pmo:migration-sql writes it out
        DB::statement("UPDATE departments SET handoff_date_label = (SELECT l.date_label FROM department_links l WHERE l.from_department_id = departments.id AND l.date_label IS NOT NULL AND l.date_label <> '' ORDER BY l.id LIMIT 1) WHERE handoff_date_label IS NULL");
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('handoff_date_label'));
        Schema::dropIfExists('cabinet_subtask_pairs');
        Schema::dropIfExists('cabinet_subtask_template_pairs');
    }
};
