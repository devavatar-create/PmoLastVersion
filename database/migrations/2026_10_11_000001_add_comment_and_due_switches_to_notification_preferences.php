<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('comments')->default(true)->after('reminders');      // new comments / replies on my projects
            $table->boolean('due_changes')->default(true)->after('comments');   // my department's due dates moved
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', fn (Blueprint $t) => $t->dropColumn(['comments', 'due_changes']));
    }
};
