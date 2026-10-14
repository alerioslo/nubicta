<div style="font-family: Arial, sans-serif; font-size: 14px; color: #34495e;">
    <p>Señor(a) <b>{{ $doc->contact?->name }}</b>,</p>
    <p>{{ $company }} le envía el comprobante <b>{{ $doc->name }}</b> del {{ $doc->date->format('d/m/Y') }} por valor de
        <b>$ {{ number_format((float) $doc->total, 2, ',', '.') }}</b>.</p>
    @if ($doc->due_date && (float) $doc->balance > 0)
        <p>Fecha de vencimiento: {{ \Illuminate\Support\Carbon::parse($doc->due_date)->format('d/m/Y') }}.</p>
    @endif
    <p>Encontrará adjunta la representación gráfica del documento.</p>
    <p>Cordialmente,<br>{{ $company }}</p>
</div>
