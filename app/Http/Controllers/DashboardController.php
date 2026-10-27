<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $shortcuts = config('erp.shortcuts');
        $electronic = \App\Models\DocumentType::query()->where('type', 'FV')->where('active', true)->where('electronic_type', '!=', 'NoElectronic')->exists();
        if (! $electronic && isset($shortcuts[0])) {
            $shortcuts[0]['label'] = 'Crea una factura de venta';
        }

        return Inertia::render('Dashboard', [
            'shortcuts' => $shortcuts,
            'tabs' => IndicatorsController::homeTabs(),
        ]);
    }

    /** Pantalla de modulo aun no construido: cabecera y pestanas del modulo. */
    public function module(string $module): Response
    {
        $item = collect(config('erp.sidebar'))->firstWhere('key', $module);
        abort_if($item === null, 404);

        return Inertia::render('ModulePending', [
            'title' => $item['label'],
            'tabs' => self::tabs($module, $item['label']),
        ]);
    }

    /** Pestanas del modulo; las que ya tienen pantalla llevan su url. */
    public static function tabs(string $module, ?string $fallback = null): array
    {
        return collect(config("erp.module_tabs.$module", [$fallback]))
            ->map(fn ($label) => ['label' => $label, 'href' => config('erp.tab_links')["$module.$label"] ?? null])
            ->all();
    }
}
