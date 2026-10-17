<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\BuildBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Services\Backup\BackupImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Copias de seguridad de la empresa: generar, descargar y restaurar. Solo administradores. */
class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Settings/Backups', [
            'backups' => Backup::query()->latest('id')->limit(30)->get()->map(fn (Backup $b) => [
                'id' => $b->id, 'kind' => $b->kind, 'status' => $b->status, 'label' => $b->label, 'size' => $b->size, 'summary' => $b->summary,
                'message' => $b->message, 'user' => $b->user_name, 'created_at' => $b->created_at->format('d/m/Y H:i'),
                'downloadable' => $b->kind === 'respaldo' && $b->status === 'listo' && $b->path,
            ]),
            'busy' => Backup::query()->whereIn('status', ['pendiente', 'procesando'])->exists(),
            'retentionDays' => config('backup.retention_days'),
            'maxUpload' => $this->maxUploadMb(),
            'canRestore' => $this->canRestore(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']], [], ['password' => 'contraseña del respaldo']);
        $this->ensureIdle();
        $backup = Backup::query()->create(['kind' => 'respaldo', 'label' => 'Manual', 'user_id' => $request->user()->id, 'user_name' => $request->user()->name]);
        $this->queue(new BuildBackup($backup->id, $data['password']));

        return back()->with('success', 'Estamos generando tu respaldo. Puedes seguir trabajando; aparecerá aquí para descargar.');
    }

    public function download(Request $request, Backup $backup): StreamedResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($backup->kind === 'respaldo' && $backup->status === 'listo' && $backup->path, 404);

        return Storage::disk(config('backup.disk'))->download($backup->path, basename($backup->path));
    }

    public function restore(Request $request, BackupImporter $importer): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->canRestore(), 403, 'No puedes restaurar respaldos en este momento.');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.($this->maxUploadMb() * 1024)],
            'password' => ['required', 'string'],
            'my_password' => ['required', 'string'],
            'confirm' => ['required', 'in:RESTAURAR'],
        ], ['confirm.in' => 'Escribe RESTAURAR para confirmar.'], ['file' => 'archivo', 'password' => 'contraseña del respaldo', 'my_password' => 'tu contraseña']);
        if (! Hash::check($data['my_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['my_password' => 'Tu contraseña no es correcta.']);
        }
        $this->ensureIdle();
        $disk = Storage::disk(config('backup.disk'));
        $path = $request->file('file')->storeAs('imports', 'restaurar-'.now()->format('YmdHis').'.zip', config('backup.disk'));
        try {
            $manifest = $importer->inspect($disk->path($path), $data['password']);
        } catch (\RuntimeException $e) {
            $disk->delete($path);
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }
        $backup = Backup::query()->create([
            'kind' => 'restaurar', 'label' => 'Respaldo de '.($manifest['company']['name'] ?? '?').' del '.substr((string) ($manifest['created_at'] ?? ''), 0, 10),
            'path' => $path, 'user_id' => $request->user()->id, 'user_name' => $request->user()->name,
        ]);
        $this->queue(new RestoreBackup($backup->id, $data['password'], $request->user()->id, $data['my_password']));

        return back()->with('success', 'Restaurando el respaldo. Antes guardamos una copia automática de lo que tienes ahora. Al terminar tendrás que volver a ingresar.');
    }

    public function destroy(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_if(in_array($backup->status, ['pendiente', 'procesando'], true), 422);
        if ($backup->path) {
            Storage::disk(config('backup.disk'))->delete($backup->path);
        }
        $backup->delete();

        return back()->with('success', 'Respaldo eliminado.');
    }

    protected function queue(object $job): void
    {
        dispatch($job);
    }

    protected function canRestore(): bool
    {
        return true;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('Administrador'), 403, 'Solo un administrador puede manejar las copias de seguridad.');
    }

    private function ensureIdle(): void
    {
        $stale = Backup::query()->whereIn('status', ['pendiente', 'procesando'])->where('updated_at', '<', now()->subHours(2));
        $stale->update(['status' => 'error', 'message' => 'Se interrumpió.']);
        if (Backup::query()->whereIn('status', ['pendiente', 'procesando'])->exists()) {
            throw ValidationException::withMessages(['password' => 'Ya hay un respaldo o una restauración en curso. Espera a que termine.']);
        }
    }

    private function maxUploadMb(): int
    {
        $toMb = function (string $v): int {
            $n = (int) $v;

            return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1024, 'k' => intdiv($n, 1024), 'm' => $n, default => intdiv($n, 1048576) };
        };

        return max(1, min($toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size'))));
    }
}
