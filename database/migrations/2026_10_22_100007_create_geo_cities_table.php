<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_cities', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3);
            $table->string('country');
            $table->string('state_code', 10);
            $table->string('state');
            $table->string('code', 10);
            $table->string('name');
            $table->index(['country_code', 'state_code']);
        });

        $rows = json_decode(file_get_contents(database_path('data/geo-cities.json')), true);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('geo_cities')->insert(array_map(fn ($r) => array_combine(['country_code', 'country', 'state_code', 'state', 'code', 'name'], $r), $chunk));
        }

        DB::table('municipalities')->insertOrIgnore([
            ['code' => '27086', 'department_code' => '27', 'name' => 'Belén De Bajirá'],
            ['code' => '94663', 'department_code' => '94', 'name' => 'Mapiripana'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cities');
    }
};
