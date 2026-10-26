<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a Sub Task comparing with another (cabinet_subtask_pairs) keeps the same list as it: each of its items made from
        // the other's points at that item (SharedChecklist). No foreign key: items are soft-deleted, and a link that
        // outlives its item is simply ignored.
        Schema::table('cabinet_checklists', function (Blueprint $table) {
            $table->unsignedBigInteger('source_checklist_id')->nullable()->after('checklist_template_id');
            $table->index('source_checklist_id');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_checklists', function (Blueprint $table) {
            $table->dropIndex(['source_checklist_id']);
            $table->dropColumn('source_checklist_id');
        });
    }
};
