<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Configuracion general de nomina (pantalla "Configuracion de nomina"). */
class PayrollSettingsController extends Controller
{
    public const PILA_OPERATORS = ['Aportes en Línea', 'Asopagos', 'Compensar - Mi Planilla', 'Enlace Operativo', 'Pila Fácil (Simple)', 'SOI'];

    public const HOURS = [235 => '235 horas mensuales: 47 horas semanales', 230 => '230 horas mensuales: 46 horas semanales', 220 => '220 horas mensuales: 44 horas semanales', 210 => '210 horas mensuales: 42 horas semanales'];

    /** Valores por defecto (Siigo: 235 horas y promedio mensual de UVT; datos de ley vigentes 2026). */
    public const DEFAULTS = [
        'pila_operator' => null, 'decimals' => false, 'exempt_health_parafiscal' => false, 'parafiscal_reduction' => false,
        'monthly_hours' => 235, 'uvt_mode' => 'promedio',
        'minimum_wage' => 1750905, 'transport_aid' => 249095, 'uvt' => 52374, 'year' => 2026,
        'payroll_groups' => ['Administración', 'Ventas', 'Producción'],
        'certificate_signer' => null, 'certificate_signer_position' => null, 'certificate_text' => null,
    ];

    public function edit(Request $request): Response
    {
        return Inertia::render('Settings/Payroll', [
            'settings' => [...self::DEFAULTS, ...($request->user()->company->payroll_settings ?? [])],
            'operators' => self::PILA_OPERATORS,
            'hours' => self::HOURS,
            'tab' => $request->input('tab', 'liquidacion'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pila_operator' => ['nullable', Rule::in(self::PILA_OPERATORS)],
            'decimals' => ['boolean'],
            'exempt_health_parafiscal' => ['boolean'],
            'parafiscal_reduction' => ['boolean'],
            'monthly_hours' => ['required', Rule::in(array_keys(self::HOURS))],
            'uvt_mode' => ['required', Rule::in(['promedio', 'acumulado'])],
            'minimum_wage' => ['required', 'numeric', 'min:0'],
            'transport_aid' => ['required', 'numeric', 'min:0'],
            'uvt' => ['required', 'numeric', 'min:0'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'payroll_groups' => ['array', 'min:1'],
            'payroll_groups.*' => ['string', 'max:60'],
            'certificate_signer' => ['nullable', 'string', 'max:160'],
            'certificate_signer_position' => ['nullable', 'string', 'max:120'],
            'certificate_text' => ['nullable', 'string', 'max:4000'],
        ]);
        $request->user()->company->update(['payroll_settings' => [...($request->user()->company->payroll_settings ?? []), ...$data]]);

        return back()->with('success', 'La configuración de nómina se guardó correctamente.');
    }
}
