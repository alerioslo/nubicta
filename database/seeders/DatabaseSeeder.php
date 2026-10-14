<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([GeoSeeder::class, RolesSeeder::class]);

        $company = Company::firstOrCreate(
            ['nit' => env('ERP_COMPANY_NIT', '000000000')],
            ['business_name' => env('ERP_COMPANY_NAME', 'Mi empresa'), 'municipality_code' => '11001', 'department_code' => '11'],
        );

        $email = env('ERP_ADMIN_EMAIL', 'admin@example.com');
        if (! User::where('email', $email)->exists()) {
            $password = Str::password(14, symbols: false);
            $user = User::create([
                'company_id' => $company->id,
                'name' => env('ERP_ADMIN_NAME', 'Administrador'),
                'email' => $email,
                'password' => $password,
                'active' => true,
            ]);
            $user->assignRole('Administrador');
            $this->command->warn("Usuario administrador: $email / $password");
        }

        if (! \App\Models\Account::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $this->command->info('PUC: '.app(\App\Services\Accounting\PucLoader::class)->load($company->id).' cuentas cargadas');
        }

        $this->call(TaxSeeder::class);
    }
}
