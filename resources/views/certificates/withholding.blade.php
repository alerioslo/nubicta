<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificados de retención</title>
    <style>
        body { font: 12px/1.45 Arial, sans-serif; color: #222; margin: 0; background: #f3f6f9; }
        .bar { text-align: center; padding: 10px; }
        .bar button { background: #00a6e0; color: #fff; border: 0; padding: 6px 16px; border-radius: 2px; cursor: pointer; }
        .sheet { width: 760px; margin: 0 auto 24px; background: #fff; padding: 36px 40px; box-shadow: 0 0 6px #0002; page-break-after: always; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 13px; text-align: center; margin: 0 0 18px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin: 14px 0; }
        th { background: #00a6e0; color: #fff; text-align: left; padding: 5px; }
        td { border-bottom: 1px solid #ddd; padding: 5px; }
        .r { text-align: right; }
        .tt td { font-weight: bold; border-top: 2px solid #00a6e0; }
        .sign { margin-top: 60px; width: 280px; border-top: 1px solid #222; padding-top: 4px; }
        .small { font-size: 10.5px; color: #555; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; width: auto; } }
    </style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>
@php($companyName = $company?->business_name ?: trim(($company?->first_name ?? '').' '.($company?->last_name ?? '')))
@forelse ($data as $c)
    <div class="sheet">
        <h1>{{ $companyName }}</h1>
        <h2>NIT {{ $company?->nit }}{{ $company?->dv !== null ? '-'.$company->dv : '' }} · {{ $company?->address }} {{ $city ? '· '.$city : '' }}</h2>
        <h1>CERTIFICADO DE {{ mb_strtoupper($title) }}</h1>
        <h2>Periodo: {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}</h2>
        <p>Certificamos que durante el periodo indicado practicamos a <b>{{ $c['name'] }}</b>, identificado(a) con NIT / C.C. <b>{{ $c['identification'] }}</b>, las siguientes retenciones, que fueron declaradas y consignadas oportunamente en {{ $city ?: 'la ciudad del domicilio' }}:</p>
        <table>
            <thead><tr><th>Concepto</th><th class="r">Base</th><th class="r">Tarifa</th><th class="r">Valor retenido</th></tr></thead>
            <tbody>
            @foreach ($c['lines'] as $l)
                <tr><td>{{ $l['concept'] }}</td><td class="r">{{ number_format($l['base'], 0, ',', '.') }}</td><td class="r">{{ $l['rate'] }}</td><td class="r">{{ number_format($l['value'], 0, ',', '.') }}</td></tr>
            @endforeach
            <tr class="tt"><td>Total</td><td class="r">{{ number_format(array_sum(array_column($c['lines'], 'base')), 0, ',', '.') }}</td><td></td><td class="r">{{ number_format(array_sum(array_column($c['lines'], 'value')), 0, ',', '.') }}</td></tr>
            </tbody>
        </table>
        <p>Se expide en {{ $city ?: '' }} el {{ now()->translatedFormat('j \d\e F \d\e Y') }}.</p>
        <div class="sign">Firma autorizada<br><span class="small">{{ $companyName }}</span></div>
        <p class="small" style="margin-top: 24px">Este certificado no requiere firma autógrafa (artículo 10 del Decreto 836 de 1991).</p>
    </div>
@empty
    <div class="sheet"><p>No hay retenciones en el periodo seleccionado.</p></div>
@endforelse
</body>
</html>
