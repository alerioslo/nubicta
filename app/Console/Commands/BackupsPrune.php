<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Borra los respaldos generados hace mas de config('backup.retention_days') dias. */
class BackupsPrune extends Command
{
    protected $signature = 'backups:prune';

    protected $description = 'Elimina respaldos viejos';

    public function handle(): int
    {
        $old = Backup::query()->where('created_at', '<', now()->subDays((int) config('backup.retention_days')))->get();
        foreach ($old as $b) {
            if ($b->path) {
                Storage::disk(config('backup.disk'))->delete($b->path);
            }
            $b->delete();
        }

        return self::SUCCESS;
    }
}
