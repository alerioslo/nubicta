@php($money = fn ($v) => '$ '.number_format((float) $v, 2, ',', '.'))
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #34495e;">
    <p>Señor(a) <b>{{ $contact->name }}</b>,</p>
    <p>{{ $company }} le envía su estado de cuenta con corte al {{ $date }}.</p>
    <table style="border-collapse: collapse; margin: 12px 0;">
        <tr><td style="padding: 4px 16px 4px 0;">Deuda por cobrar</td><td style="text-align: right;"><b>{{ $money($totals['debt']) }}</b></td></tr>
        @if ($totals['favor'] != 0)
            <tr><td style="padding: 4px 16px 4px 0;">Valor a favor</td><td style="text-align: right;">{{ $money($totals['favor']) }}</td></tr>
        @endif
        <tr><td style="padding: 4px 16px 4px 0;">Vencido</td><td style="text-align: right; color: #e74c3c;">{{ $money($totals['overdue']) }}</td></tr>
        <tr><td style="padding: 4px 16px 4px 0;">Por vencer</td><td style="text-align: right;">{{ $money($totals['upcoming']) }}</td></tr>
        <tr><td style="padding: 4px 16px 4px 0;"><b>Saldo cartera</b></td><td style="text-align: right;"><b>{{ $money($totals['debt'] + $totals['favor']) }}</b></td></tr>
    </table>
    @if ($docs->isNotEmpty())
        <table style="border-collapse: collapse; width: 100%; font-size: 13px;">
            <thead>
                <tr style="background: #0b7ed0; color: #fff;">
                    <th style="padding: 6px; text-align: left;">Documento</th>
                    <th style="padding: 6px; text-align: left;">Vencimiento</th>
                    <th style="padding: 6px; text-align: right;">Saldo</th>
                    <th style="padding: 6px; text-align: right;">Días mora / por vencer</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($docs as $d)
                    <tr style="border-bottom: 1px solid #e3e8ee;">
                        <td style="padding: 6px;">{{ $d['name'] }}</td>
                        <td style="padding: 6px;">{{ $d['due'] ?? $d['date'] }}</td>
                        <td style="padding: 6px; text-align: right;">{{ $money($d['balance']) }}</td>
                        <td style="padding: 6px; text-align: right;">{{ $d['days'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p>Si ya realizó el pago, por favor haga caso omiso de este mensaje.</p>
    <p>Cordialmente,<br>{{ $company }}</p>
</div>
