<?php

namespace App\Console\Commands;

use Database\Seeders\SnapshotSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** Dumps every app table to database/seeders/snapshot/*.json so SnapshotSeeder can load the same data on another machine. */
class SnapshotExport extends Command
{
    protected $signature = 'pmo:snapshot';

    protected $description = 'Export the current database into database/seeders/snapshot for SnapshotSeeder';

    /** Framework/runtime tables: rebuilt by migrations or meaningless on another machine. */
    private const SKIP = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'push_subscriptions',
    ];

    public function handle(): int
    {
        $dir = SnapshotSeeder::DIR;
        File::ensureDirectoryExists($dir);
        File::delete(File::glob($dir.'/*.json'));

        foreach (Schema::getTableListing(DB::getDatabaseName(), false) as $table) {
            if (in_array($table, self::SKIP, true)) {
                continue;
            }

            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row);
            if ($table === 'users') {
                $rows = $rows->map(fn ($row) => array_merge($row, ['remember_token' => null]));
            }

            File::put("{$dir}/{$table}.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->line(str_pad($table, 34).$rows->count());
        }

        $this->info('บันทึกข้อมูลไว้ที่ database/seeders/snapshot แล้ว — โหลดบนเครื่องอื่นด้วย php artisan db:seed --class=SnapshotSeeder');

        return self::SUCCESS;
    }
}
