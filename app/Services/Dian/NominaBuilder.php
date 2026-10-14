<?php

namespace App\Services\Dian;

/**
 * XML de nomina electronica segun el Anexo tecnico de la Resolucion 000013 de 2021 (v1.0):
 * NominaIndividual (TipoXML 102) y NominaIndividualDeAjuste (TipoXML 103, Reemplazar o Eliminar).
 * Recibe el documento normalizado por PayrollMapper y devuelve el XML sin firmar y el CUNE.
 */
class NominaBuilder
{
    private const NS = 'xmlns:xs="http://www.w3.org/2001/XMLSchema-instance" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '
        .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" '
        .'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#" '
        .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';

    /** Orden de los devengados en el XSD y su etiqueta: [contenedor, elemento, porcentaje]. */
    private const HOURS = [
        'hed' => ['HEDs', 'HED', '25.00'], 'hen' => ['HENs', 'HEN', '75.00'], 'rn' => ['HRNs', 'HRN', '35.00'],
        'hedf' => ['HEDDFs', 'HEDDF', '100.00'], 'rdf' => ['HRDDFs', 'HRDDF', '75.00'], 'henf' => ['HENDFs', 'HENDF', '150.00'], 'rndf' => ['HRNDFs', 'HRNDF', '110.00'],
    ];

    /** @return array{xml:string, cune:string, root:string} */
    public function build(array $d): array
    {
        $adjust = $d['note_type'] ?? null;
        $root = $adjust ? 'NominaIndividualDeAjuste' : 'NominaIndividual';
        $typeXml = $adjust ? '103' : '102';
        $delete = $adjust === 2;
        $earnings = $delete ? 0.0 : $d['totals']['earnings'];
        $deductions = $delete ? 0.0 : $d['totals']['deductions'];
        $cune = hash('sha384', $d['number'].$d['issue_date'].$d['issue_time'].$this->num($earnings).$this->num($deductions).$this->num($earnings - $deductions)
            .$d['employer']['nit'].($delete ? '0' : $d['worker']['document']).$typeXml.$d['software']['pin'].$d['environment']);
        $host = $d['environment'] === '1' ? 'https://catalogo-vpfe.dian.gov.co' : 'https://catalogo-vpfe-hab.dian.gov.co';

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
        $x .= '<'.$root.' xmlns="dian:gov:co:facturaelectronica:'.$root.'" '.self::NS.' SchemaLocation="" '
            .'xsi:schemaLocation="dian:gov:co:facturaelectronica:'.$root.' '.$root.'ElectronicaXSD.xsd">';
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>';

        $sequence = $this->tag('NumeroSecuenciaXML', ['CodigoTrabajador' => $delete ? null : $d['worker']['code'], 'Prefijo' => $d['prefix'], 'Consecutivo' => $d['consecutive'], 'Numero' => $d['number']]);
        $place = $this->tag('LugarGeneracionXML', ['Pais' => 'CO', 'DepartamentoEstado' => $d['employer']['department_code'], 'MunicipioCiudad' => $d['employer']['municipality_code'], 'Idioma' => 'es']);
        $provider = $this->tag('ProveedorXML', ['RazonSocial' => $d['employer']['name'], 'NIT' => $d['software']['provider_nit'], 'DV' => $d['software']['provider_dv'],
            'SoftwareID' => $d['software']['id'], 'SoftwareSC' => hash('sha384', $d['software']['id'].$d['software']['pin'].$d['number'])]);
        $qr = $this->el('CodigoQR', $host.'/document/searchqr?documentkey='.$cune);
        $general = $this->tag('InformacionGeneral', [
            'Version' => 'V1.0: '.($adjust ? 'Nota de Ajuste de Documento Soporte de Pago de Nómina Electrónica' : 'Documento Soporte de Pago de Nómina Electrónica'),
            'Ambiente' => $d['environment'], 'TipoXML' => $typeXml, 'CUNE' => $cune, 'EncripCUNE' => 'CUNE-SHA384', 'FechaGen' => $d['issue_date'], 'HoraGen' => $d['issue_time'],
            'PeriodoNomina' => $delete ? null : $d['period_code'], 'TipoMoneda' => $delete ? null : 'COP', 'TRM' => $delete ? null : '0',
        ]);
        $notes = filled($d['notes'] ?? null) ? $this->el('Notas', $d['notes']) : '';
        $e = $d['employer'];
        $employer = $this->tag('Empleador', ['RazonSocial' => $e['name'], 'NIT' => $e['nit'], 'DV' => $e['dv'], 'Pais' => 'CO',
            'DepartamentoEstado' => $e['department_code'], 'MunicipioCiudad' => $e['municipality_code'], 'Direccion' => $e['address']]);

        if ($adjust) {
            $x .= $this->el('TipoNota', (string) $adjust);
            $p = $d['predecessor'];
            $pred = ['NumeroPred' => $p['number'], 'CUNEPred' => $p['cune'], 'FechaGenPred' => $p['date']];
            if ($delete) {
                return ['xml' => $x.'<Eliminar>'.$this->tag('EliminandoPredecesor', $pred).$sequence.$place.$provider.$qr.$general.$notes.$employer.'</Eliminar></'.$root.'>', 'cune' => $cune, 'root' => $root];
            }
            $x .= '<Reemplazar>'.$this->tag('ReemplazandoPredecesor', $pred);
        } else {
            $x .= '<Novedad CUNENov="">false</Novedad>';
        }

        $x .= $this->tag('Periodo', ['FechaIngreso' => $d['worker']['start_date'], 'FechaRetiro' => $d['worker']['end_date'], 'FechaLiquidacionInicio' => $d['period_start'],
            'FechaLiquidacionFin' => $d['period_end'], 'TiempoLaborado' => $d['worked_time'], 'FechaGen' => $d['issue_date']]);
        $x .= $sequence.$place.$provider.$qr.$general.$notes.$employer;
        $w = $d['worker'];
        $x .= $this->tag('Trabajador', [
            'TipoTrabajador' => $w['type'], 'SubTipoTrabajador' => $w['subtype'], 'AltoRiesgoPension' => 'false', 'TipoDocumento' => $w['document_type'], 'NumeroDocumento' => $w['document'],
            'PrimerApellido' => $w['last_name'], 'SegundoApellido' => $w['second_last_name'], 'PrimerNombre' => $w['first_name'], 'OtrosNombres' => $w['other_names'],
            'LugarTrabajoPais' => 'CO', 'LugarTrabajoDepartamentoEstado' => $w['department_code'], 'LugarTrabajoMunicipioCiudad' => $w['municipality_code'],
            'LugarTrabajoDireccion' => $w['address'], 'SalarioIntegral' => $w['integral'] ? 'true' : 'false', 'TipoContrato' => $w['contract_type'],
            'Sueldo' => $this->num($w['salary']), 'CodigoTrabajador' => $w['code'],
        ]);
        $pay = $d['payment'];
        $x .= $this->tag('Pago', ['Forma' => '1', 'Metodo' => $pay['method'], 'Banco' => $pay['bank'], 'TipoCuenta' => $pay['account_type'], 'NumeroCuenta' => $pay['account']]);
        $x .= '<FechasPagos>'.implode('', array_map(fn ($f) => $this->el('FechaPago', $f), $d['payment_dates'])).'</FechasPagos>';
        $x .= $this->earnings($d['earnings']).$this->deductions($d['deductions']);
        $x .= $this->el('DevengadosTotal', $this->num($earnings)).$this->el('DeduccionesTotal', $this->num($deductions)).$this->el('ComprobanteTotal', $this->num($earnings - $deductions));
        if ($adjust) {
            $x .= '</Reemplazar>';
        }

        return ['xml' => $x.'</'.$root.'>', 'cune' => $cune, 'root' => $root];
    }

    /** @param array<string, array{quantity:float, value:float, label:string}> $e */
    private function earnings(array $e): string
    {
        $v = fn (string $c) => $e[$c]['value'] ?? 0.0;
        $q = fn (string $c) => $e[$c]['quantity'] ?? 0.0;
        $x = '<Devengados>'.$this->tag('Basico', ['DiasTrabajados' => (string) (int) round($q('sueldo') ?: $q('salario_integral')), 'SueldoTrabajado' => $this->num($v('sueldo') + $v('salario_integral'))]);
        if ($v('auxilio_transporte') > 0) {
            $x .= $this->tag('Transporte', ['AuxilioTransporte' => $this->num($v('auxilio_transporte'))]);
        }
        foreach (self::HOURS as $code => [$group, $tag, $pct]) {
            if ($v($code) > 0) {
                $x .= "<$group>".$this->tag($tag, ['Cantidad' => $this->num($q($code)), 'Porcentaje' => $pct, 'Pago' => $this->num($v($code))])."</$group>";
            }
        }
        if ($v('vacaciones') + $v('vacaciones_compensadas') > 0) {
            $x .= '<Vacaciones>'.($v('vacaciones') > 0 ? $this->tag('VacacionesComunes', ['Cantidad' => (string) (int) $q('vacaciones'), 'Pago' => $this->num($v('vacaciones'))]) : '')
                .($v('vacaciones_compensadas') > 0 ? $this->tag('VacacionesCompensadas', ['Cantidad' => (string) (int) $q('vacaciones_compensadas'), 'Pago' => $this->num($v('vacaciones_compensadas'))]) : '').'</Vacaciones>';
        }
        if ($v('prima') > 0) {
            $x .= $this->tag('Primas', ['Cantidad' => (string) (int) $q('prima'), 'Pago' => $this->num($v('prima'))]);
        }
        if ($v('cesantias') + $v('intereses_cesantias') > 0) {
            $x .= $this->tag('Cesantias', ['Pago' => $this->num($v('cesantias')), 'Porcentaje' => '12.00', 'PagoIntereses' => $this->num($v('intereses_cesantias'))]);
        }
        $inc = ['incapacidad_general' => '1', 'incapacidad_laboral' => '3'];
        if (array_sum(array_map($v, array_keys($inc))) > 0) {
            $x .= '<Incapacidades>';
            foreach ($inc as $c => $type) {
                if ($v($c) > 0) {
                    $x .= $this->tag('Incapacidad', ['Cantidad' => (string) (int) $q($c), 'Tipo' => $type, 'Pago' => $this->num($v($c))]);
                }
            }
            $x .= '</Incapacidades>';
        }
        if ($v('licencia_maternidad') + $v('licencia_remunerada') + $q('licencia_no_remunerada') > 0) {
            $x .= '<Licencias>'.($v('licencia_maternidad') > 0 ? $this->tag('LicenciaMP', ['Cantidad' => (string) (int) $q('licencia_maternidad'), 'Pago' => $this->num($v('licencia_maternidad'))]) : '')
                .($v('licencia_remunerada') > 0 ? $this->tag('LicenciaR', ['Cantidad' => (string) (int) $q('licencia_remunerada'), 'Pago' => $this->num($v('licencia_remunerada'))]) : '')
                .($q('licencia_no_remunerada') > 0 ? $this->tag('LicenciaNR', ['Cantidad' => (string) (int) $q('licencia_no_remunerada')]) : '').'</Licencias>';
        }
        if ($v('bonificacion_salarial') + $v('bonificacion') > 0) {
            $x .= '<Bonificaciones>'.$this->tag('Bonificacion', ['BonificacionS' => $v('bonificacion_salarial') > 0 ? $this->num($v('bonificacion_salarial')) : null,
                'BonificacionNS' => $v('bonificacion') > 0 ? $this->num($v('bonificacion')) : null]).'</Bonificaciones>';
        }
        if ($v('otro_devengo') > 0) {
            $x .= '<OtrosConceptos>'.$this->tag('OtroConcepto', ['DescripcionConcepto' => 'Otro devengo no salarial', 'ConceptoNS' => $this->num($v('otro_devengo'))]).'</OtrosConceptos>';
        }
        if ($v('comision') > 0) {
            $x .= '<Comisiones>'.$this->el('Comision', $this->num($v('comision'))).'</Comisiones>';
        }
        if ($v('indemnizacion') > 0) {
            $x .= $this->el('Indemnizacion', $this->num($v('indemnizacion')));
        }

        return $x.'</Devengados>';
    }

    /** @param array<string, array{quantity:float, value:float, rate:float, label:string}> $d */
    private function deductions(array $d): string
    {
        $v = fn (string $c) => $d[$c]['value'] ?? 0.0;
        $r = fn (string $c, string $default) => isset($d[$c]['rate']) && $d[$c]['rate'] > 0 ? $this->num($d[$c]['rate'] <= 1 ? $d[$c]['rate'] * 100 : $d[$c]['rate']) : $default;
        $x = '<Deducciones>'.$this->tag('Salud', ['Porcentaje' => $r('salud', '4.00'), 'Deduccion' => $this->num($v('salud'))])
            .$this->tag('FondoPension', ['Porcentaje' => $r('pension', '4.00'), 'Deduccion' => $this->num($v('pension'))]);
        if ($v('fsp') > 0) {
            $x .= $this->tag('FondoSP', ['Porcentaje' => $r('fsp', '1.00'), 'DeduccionSP' => $this->num($v('fsp'))]);
        }
        if ($v('libranza') > 0) {
            $x .= '<Libranzas>'.$this->tag('Libranza', ['Descripcion' => 'Libranza', 'Deduccion' => $this->num($v('libranza'))]).'</Libranzas>';
        }
        if ($v('prestamo') > 0) {
            $x .= '<Anticipos>'.$this->el('Anticipo', $this->num($v('prestamo'))).'</Anticipos>';
        }
        if ($v('otra_deduccion') > 0) {
            $x .= '<OtrasDeducciones>'.$this->el('OtraDeduccion', $this->num($v('otra_deduccion'))).'</OtrasDeducciones>';
        }
        foreach (['pension_voluntaria' => 'PensionVoluntaria', 'retefuente' => 'RetencionFuente', 'afc' => 'AFC', 'embargo' => 'EmbargoFiscal'] as $c => $tag) {
            if ($v($c) > 0) {
                $x .= $this->el($tag, $this->num($v($c)));
            }
        }

        return $x.'</Deducciones>';
    }

    private function tag(string $name, array $attrs, ?string $text = null): string
    {
        $a = '';
        foreach ($attrs as $k => $val) {
            if ($val !== null && $val !== '') {
                $a .= ' '.$k.'="'.$this->e((string) $val).'"';
            }
        }

        return $text === null ? "<$name$a/>" : "<$name$a>".$this->e($text)."</$name>";
    }

    private function el(string $tag, string $value): string
    {
        return "<$tag>".$this->e($value)."</$tag>";
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function num(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }
}
