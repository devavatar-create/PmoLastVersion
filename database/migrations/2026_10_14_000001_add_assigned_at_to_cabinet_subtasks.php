<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // when the work was handed to its department (for "รอรับเกิน n วัน" reminders)
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assignment_status');
        });

        // work already waiting: best guess = when the row last changed
        DB::table('cabinet_subtasks')->where('assignment_status', 'ASSIGNED')->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('cabinet_subtasks', fn (Blueprint $table) => $table->dropColumn('assigned_at'));
    }
};
