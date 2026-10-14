<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > การส่งต่องาน: department A's checklist is shown (read only) to department B, and B hears about A's progress
        Schema::create('department_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('date_label', 40)->nullable();   // the date the source fills in per item, e.g. "วันของเข้า" (none = no date)
            $table->timestamps();
            $table->unique(['from_department_id', 'to_department_id'], 'department_links_unique');
        });

        // when a checklist item is expected (e.g. Purchasing: the day the goods arrive)
        Schema::table('cabinet_checklists', function (Blueprint $table) {
            $table->date('expected_date')->nullable()->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_checklists', fn (Blueprint $table) => $table->dropColumn('expected_date'));
        Schema::dropIfExists('department_links');
    }
};
