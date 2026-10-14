<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('customer_phone', 50)->nullable()->after('customer_name');   // เบอร์ติดต่อลูกค้า
            $table->decimal('contract_value', 15, 2)->nullable()->after('payment_terms');   // มูลค่างาน (บาท)
            $table->decimal('margin', 15, 2)->nullable()->after('contract_value');   // Margin (บาท)
        });

        // the job types the project form offers (Settings > ประเภทงาน can rename / add / switch them off later)
        foreach (['Service', 'ตู้', 'Trading'] as $name) {
            if (! DB::table('job_types')->where('name', $name)->exists()) {
                DB::table('job_types')->insert(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['customer_phone', 'contract_value', 'margin']));
    }
};
