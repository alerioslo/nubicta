<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** El perfil Vendedor deja de ver costos y movimientos. Quien ya ve inventario, salvo Vendedor y Cajero, conserva los costos. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $cost = Permission::findOrCreate('inventario.ver_costos', 'web');

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->name === 'Vendedor' && Permission::query()->where('name', 'movimientos.ver')->exists()) {
                $role->revokePermissionTo('movimientos.ver');
            }
            if (in_array($role->name, ['Vendedor', 'Cajero'], true)) {
                continue;
            }
            if ($role->hasPermissionTo('inventario.ver')) {
                $role->givePermissionTo($cost);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        Permission::query()->where('name', 'inventario.ver_costos')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
