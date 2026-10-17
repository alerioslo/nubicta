<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupExporter;
use App\Services\Backup\BackupImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Restaura un respaldo subido. Antes guarda una copia automatica del estado actual por si hay que volver atras. */
class RestoreBackup implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $backupId, public string $password, public int $userId, public string $userPassword)
    {
        $this->onConnection(config('backup.queue_connection'))->onQueue('backups');
    }

    public function handle(BackupExporter $exporter, BackupImporter $importer): void
    {
        $restore = Backup::query()->find($this->backupId);
        if (! $restore || $restore->status !== 'pendiente') {
            return;
        }
        @set_time_limit(0);
        $restore->update(['status' => 'procesando']);
        $user = User::query()->findOrFail($this->userId);

        $safety = Backup::query()->create(['kind' => 'respaldo', 'status' => 'procesando', 'label' => 'Automático antes de restaurar', 'user_id' => $user->id, 'user_name' => $user->name]);
        $exporter->export($safety, $this->password);

        $disk = Storage::disk(config('backup.disk'));
        $importer->import($restore, $disk->path($restore->path), $this->password, $user, $this->userPassword);
        $disk->delete($restore->path);
        $restore->update(['path' => null]);
    }

    public function failed(\Throwable $e): void
    {
        Backup::query()->whereKey($this->backupId)->update(['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
        Backup::query()->where('status', 'procesando')->where('label', 'Automático antes de restaurar')->update(['status' => 'error', 'message' => 'Se interrumpió.']);
    }
}
