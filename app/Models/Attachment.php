<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function store(Model $owner, UploadedFile $file): self
    {
        $path = $file->store('attachments/'.$owner->company_id, 'local');

        return self::create([
            'company_id' => $owner->company_id, 'attachable_type' => $owner::class, 'attachable_id' => $owner->getKey(),
            'name' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize(), 'user_id' => auth()->id(),
        ]);
    }

    /** Adjunta una copia de un archivo que ya esta en el disco local (XML recibido, soporte por legalizar). */
    public static function copyFrom(Model $owner, string $sourcePath, string $name): ?self
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($sourcePath)) {
            return null;
        }
        $path = 'attachments/'.$owner->company_id.'/'.\Illuminate\Support\Str::random(40).'.'.pathinfo($name, PATHINFO_EXTENSION);
        $disk->copy($sourcePath, $path);

        return self::create([
            'company_id' => $owner->company_id, 'attachable_type' => $owner::class, 'attachable_id' => $owner->getKey(),
            'name' => $name, 'path' => $path, 'size' => $disk->size($path), 'user_id' => auth()->id(),
        ]);
    }

    /** @return list<array{id:int, name:string, url:string}> */
    public static function listFor(Model $owner): array
    {
        return self::where('attachable_type', $owner::class)->where('attachable_id', $owner->getKey())->orderBy('id')->get()
            ->map(fn (self $a) => ['id' => $a->id, 'name' => $a->name, 'url' => "/adjuntos/{$a->id}"])->all();
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $a) => Storage::disk('local')->delete($a->path));
    }
}
