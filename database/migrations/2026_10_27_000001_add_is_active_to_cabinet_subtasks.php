<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "สรุปงาน" (reports/today): 0 = work that came in and nobody has opened from that page yet ("มีงานเข้า"),
        // 1 = seen. New Sub Tasks start at 0; what is already there counts as seen, so the page does not start flooded.
        // Some databases got the column by hand first: then only the existing rows are marked.
        if (! Schema::hasColumn('cabinet_subtasks', 'is_active')) {
            Schema::table('cabinet_subtasks', function (Blueprint $table) {
                $table->boolean('is_active')->default(false)->after('assignment_status');
            });
        }
        DB::table('cabinet_subtasks')->update(['is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
