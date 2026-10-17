<?php

namespace App\Services\Backup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Columnas que este sistema guarda cifradas, sacadas de los casts de los modelos ('all': todo el valor va cifrado)
 * mas las de config('backup.sealed_markers') ('markers': solo las partes que venian cifradas, p. ej. JSON con 'enc:').
 * El resto de columnas se restaura en texto plano, asi un respaldo de nube queda legible en privado y viceversa.
 */
final class SealedColumns
{
    /** @return array<string, array<string, 'all'|'markers'>> */
    public static function map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach ((new Finder)->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path('Models').DIRECTORY_SEPARATOR));
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $model = new $class;
            if ($model->getConnectionName() && $model->getConnectionName() !== config('database.default')) {
                continue;
            }
            foreach ($model->getCasts() as $column => $cast) {
                if (is_string($cast) && (str_starts_with($cast, 'encrypted') || str_ends_with($cast, 'Encrypted'))) {
                    $map[$model->getTable()][$column] = 'all';
                }
            }
        }
        foreach (config('backup.sealed_markers', []) as $spec) {
            [$table, $column] = explode('.', $spec, 2);
            $map[$table][$column] ??= 'markers';
        }

        return $map;
    }
}
