<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the tables with the copy made by `php artisan pmo:snapshot` (database/seeders/snapshot/*.json).
 * Run on a migrated database: php artisan db:seed --class=SnapshotSeeder
 */
class SnapshotSeeder extends Seeder
{
    public const DIR = __DIR__.'/snapshot';

    public function run(): void
    {
        $files = File::glob(self::DIR.'/*.json');
        if (! $files) {
            $this->command?->error('ไม่พบไฟล์ใน database/seeders/snapshot — รัน php artisan pmo:snapshot บนเครื่องที่มีข้อมูลก่อน');

            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ($files as $file) {
            $table = basename($file, '.json');
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();
            foreach (array_chunk(json_decode(File::get($file), true), 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}
