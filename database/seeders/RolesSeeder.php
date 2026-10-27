<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('erp.permissions') as $module => $def) {
            foreach ($def['actions'] as $action) {
                Permission::findOrCreate("$module.$action", 'web');
            }
        }

        Role::findOrCreate('Administrador', 'web');

        $profiles = [
            'Vendedor' => ['ventas.ver', 'ventas.crear', 'ventas.imprimir', 'terceros.ver', 'terceros.crear', 'inventario.ver'],
            'Cajero' => ['ventas.ver', 'ventas.crear', 'ventas.imprimir', 'terceros.ver', 'terceros.crear', 'bancos.ver', 'inventario.ver'],
            'Contador' => [
                'ventas.ver', 'ventas.exportar', 'compras.ver', 'compras.crear', 'compras.editar', 'compras.exportar',
                'terceros.ver', 'terceros.crear', 'terceros.editar', 'inventario.ver', 'inventario.ver_costos', 'bancos.ver', 'bancos.crear',
                'nomina.ver', 'nomina.crear', 'nomina.liquidar', 'nomina.enviar_dian',
                'contabilidad.ver', 'contabilidad.crear', 'contabilidad.editar', 'contabilidad.anular', 'contabilidad.cerrar_periodo',
                'reportes.ver', 'reportes.exportar', 'movimientos.ver', 'movimientos.exportar', 'configuracion.parametros',
            ],
            'Bodega' => ['inventario.ver', 'inventario.ver_costos', 'inventario.crear', 'inventario.editar', 'compras.ver', 'movimientos.ver'],
        ];

        foreach ($profiles as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');
            if ($role->permissions()->count() === 0) {
                $role->syncPermissions($permissions);
            }
        }
    }
}
