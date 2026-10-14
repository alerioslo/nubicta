<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Departamentos y municipios DANE desde datos.gov.co (mismo origen que usa colombiawisp). */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('countries')->upsert([['code' => 'CO', 'name' => 'Colombia']], ['code']);

        if (DB::table('municipalities')->count() > 1000) {
            return;
        }

        $rows = Http::timeout(60)->get('https://www.datos.gov.co/resource/gdxc-w37w.json', ['$limit' => 5000])->throw()->json();
        $title = fn (string $s) => Str::title(Str::lower(trim($s)));

        $departments = [];
        $municipalities = [];
        foreach ($rows as $r) {
            $dep = str_pad((string) $r['cod_dpto'], 2, '0', STR_PAD_LEFT);
            $mun = str_pad((string) $r['cod_mpio'], 5, '0', STR_PAD_LEFT);
            $departments[$dep] = ['code' => $dep, 'name' => $title($r['dpto'])];
            $municipalities[$mun] = ['code' => $mun, 'department_code' => $dep, 'name' => $title($r['nom_mpio'])];
        }

        DB::table('departments')->upsert(array_values($departments), ['code'], ['name']);
        foreach (array_chunk(array_values($municipalities), 500) as $chunk) {
            DB::table('municipalities')->upsert($chunk, ['code'], ['name', 'department_code']);
        }
    }
}
