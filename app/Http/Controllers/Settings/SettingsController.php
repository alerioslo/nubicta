<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const SECTIONS = [
        'Empresa' => [
            ['label' => 'Datos de la empresa', 'url' => '/configuracion/empresa', 'permission' => 'configuracion.empresa', 'icon' => 'building'],
            ['label' => 'Facturación electrónica', 'url' => '/configuracion/facturacion-electronica', 'permission' => 'configuracion.facturacion_electronica', 'icon' => 'file-signature'],
        ],
        'Usuarios' => [
            ['label' => 'Usuarios', 'url' => '/configuracion/usuarios', 'permission' => 'configuracion.usuarios', 'icon' => 'users'],
            ['label' => 'Perfiles', 'url' => '/configuracion/perfiles', 'permission' => 'configuracion.usuarios', 'icon' => 'user-shield'],
            ['label' => 'Historial de actividades', 'url' => '/configuracion/auditoria', 'permission' => 'configuracion.auditoria', 'icon' => 'clock-rotate-left'],
        ],
        'Parámetros' => [
            ['label' => 'Tipos de documento', 'url' => '/configuracion/tipos-documento', 'permission' => 'configuracion.parametros', 'icon' => 'file-lines'],
            ['label' => 'Impuestos', 'url' => '/configuracion/impuestos', 'permission' => 'configuracion.parametros', 'icon' => 'percent'],
            ['label' => 'Formas de pago', 'url' => '/configuracion/formas-pago', 'permission' => 'configuracion.parametros', 'icon' => 'money-bill'],
            ['label' => 'Centros de costo', 'url' => '/configuracion/centros-costo', 'permission' => 'configuracion.parametros', 'icon' => 'sitemap'],
            ['label' => 'Vendedores', 'url' => '/configuracion/vendedores', 'permission' => 'configuracion.parametros', 'icon' => 'user-tag'],
            ['label' => 'Bodegas', 'url' => '/configuracion/bodegas', 'permission' => 'configuracion.parametros', 'icon' => 'warehouse'],
            ['label' => 'Listas de precios', 'url' => '/configuracion/listas-precios', 'permission' => 'configuracion.parametros', 'icon' => 'tags'],
            ['label' => 'Grupos de inventario', 'url' => '/configuracion/grupos-inventario', 'permission' => 'configuracion.parametros', 'icon' => 'cart-flatbed'],
            ['label' => 'Líneas de producto', 'url' => '/configuracion/lineas-producto', 'permission' => 'configuracion.parametros', 'icon' => 'sitemap'],
            ['label' => 'Catálogo de cuentas', 'url' => '/contabilidad/cuentas', 'permission' => 'configuracion.parametros', 'icon' => 'book'],
        ],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $sections = [];
        foreach (self::SECTIONS as $name => $items) {
            $visible = array_values(array_filter($items, fn ($i) => Navigation::allowed($user, $i['permission'])));
            if ($visible !== []) {
                $sections[$name] = $visible;
            }
        }

        return Inertia::render('Settings/Index', ['sections' => $sections]);
    }
}
