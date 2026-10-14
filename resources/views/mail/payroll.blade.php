<div style="font-family: Arial, sans-serif; font-size: 14px; color: #34495e;">
    <p>Señor(a) <b>{{ $doc->contact?->name }}</b>,</p>
    <p>{{ $company }} le envía su {{ mb_strtolower($doc->type) }} de nómina electrónica <b>{{ $doc->consecutive }}</b>
        del periodo {{ $doc->period }} por un neto de <b>$ {{ number_format((float) $doc->net, 2, ',', '.') }}</b>.</p>
    <p>Encontrará adjunta la representación gráfica del documento.</p>
    <p>Cordialmente,<br>{{ $company }}</p>
</div>
