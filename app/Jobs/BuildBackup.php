<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Genera un respaldo en segundo plano (la contraseña viaja cifrada en la cola). */
class BuildBackup implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $backupId, public string $password)
    {
        $this->onConnection(config('backup.queue_connection'))->onQueue('backups');
    }

    public function handle(BackupExporter $exporter): void
    {
        $backup = Backup::query()->find($this->backupId);
        if (! $backup || $backup->status !== 'pendiente') {
            return;
        }
        @set_time_limit(0);
        $backup->update(['status' => 'procesando']);
        $exporter->export($backup, $this->password);
    }

    public function failed(\Throwable $e): void
    {
        Backup::query()->whereKey($this->backupId)->update(['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
    }
}
