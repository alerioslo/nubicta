<?php

namespace App\Services\Dian;

/**
 * Eventos RADIAN del adquiriente (Anexo tecnico RADIAN, Res. DIAN 000085 de 2022) como ApplicationResponse UBL 2.1:
 * 030 Acuse de recibo, 031 Reclamo, 032 Recibo del bien o prestacion del servicio y 033 Aceptacion expresa.
 * CUDE = SHA-384(ID + FechaEmision + HoraEmision + NitEmisor + NitReceptor + ResponseCode + IdFactura + TipoDocumento + PIN).
 */
class RadianEventBuilder
{
    public const EVENTS = [
        '030' => 'Acuse de recibo de Factura Electrónica de Venta',
        '031' => 'Reclamo de la Factura Electrónica de Venta',
        '032' => 'Recibo del bien y/o prestación del servicio',
        '033' => 'Aceptación expresa',
    ];

    public const CLAIMS = [
        '01' => 'Documento con inconsistencias',
        '02' => 'Mercancía no entregada totalmente',
        '03' => 'Mercancía no entregada parcialmente',
        '04' => 'Servicio no prestado',
    ];

    private const DIAN = 'schemeAgencyID="195" schemeAgencyName="CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)"';

    /**
     * @param  array{number:string, code:string, claim:?string, issue_date:string, issue_time:string, environment:string,
     *     software:array{id:string, pin:string, provider_nit:string, provider_dv:string},
     *     sender:array, receiver:array, invoice:array{number:string, cufe:string}, person:?array}  $d
     * @return array{xml:string, cude:string}
     */
    public function build(array $d): array
    {
        $cude = $this->cude($d);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
        $x .= '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" '
            .'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
            .'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" '
            .'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '
            .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" '
            .'xmlns:sts="dian:gov:co:facturaelectronica:Structures-2-1" '
            .'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#">';
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent><sts:DianExtensions>'
            .'<sts:InvoiceSource><cbc:IdentificationCode listAgencyID="6" listAgencyName="United Nations Economic Commission for Europe" listSchemeURI="urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1">CO</cbc:IdentificationCode></sts:InvoiceSource>'
            .'<sts:SoftwareProvider><sts:ProviderID '.self::DIAN.' schemeID="'.$this->e($d['software']['provider_dv']).'" schemeName="31">'.$this->e($d['software']['provider_nit']).'</sts:ProviderID>'
            .'<sts:SoftwareID '.self::DIAN.'>'.$this->e($d['software']['id']).'</sts:SoftwareID></sts:SoftwareProvider>'
            .'<sts:SoftwareSecurityCode '.self::DIAN.'>'.hash('sha384', $d['software']['id'].$d['software']['pin'].$d['number']).'</sts:SoftwareSecurityCode>'
            .'<sts:AuthorizationProvider><sts:AuthorizationProviderID '.self::DIAN.' schemeID="4" schemeName="31">800197268</sts:AuthorizationProviderID></sts:AuthorizationProvider>'
            .$this->el('sts:QRCode', ($d['environment'] === '1' ? 'https://catalogo-vpfe.dian.gov.co' : 'https://catalogo-vpfe-hab.dian.gov.co').'/document/searchqr?documentkey='.$d['invoice']['cufe'])
            .'</sts:DianExtensions></ext:ExtensionContent></ext:UBLExtension>'
            .'<ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>';
        $x .= $this->el('cbc:UBLVersionID', 'UBL 2.1').$this->el('cbc:CustomizationID', '1')
            .$this->el('cbc:ProfileID', 'DIAN 2.1: ApplicationResponse de la Factura Electrónica de Venta')
            .$this->el('cbc:ProfileExecutionID', $d['environment']).$this->el('cbc:ID', $d['number'])
            .'<cbc:UUID schemeID="'.$this->e($d['environment']).'" schemeName="CUDE-SHA384">'.$cude.'</cbc:UUID>'
            .$this->el('cbc:IssueDate', $d['issue_date']).$this->el('cbc:IssueTime', $d['issue_time']);
        $x .= '<cac:SenderParty>'.$this->party($d['sender']).'</cac:SenderParty>';
        $x .= '<cac:ReceiverParty>'.$this->party($d['receiver']).'</cac:ReceiverParty>';
        $x .= '<cac:DocumentResponse><cac:Response>'
            .($d['code'] === '031' ? '<cbc:ResponseCode listID="'.$this->e($d['claim']).'">031</cbc:ResponseCode>' : $this->el('cbc:ResponseCode', $d['code']))
            .$this->el('cbc:Description', $d['code'] === '031' ? self::CLAIMS[$d['claim']] ?? self::EVENTS['031'] : self::EVENTS[$d['code']])
            .'</cac:Response>';
        $x .= '<cac:DocumentReference>'.$this->el('cbc:ID', $d['invoice']['number'])
            .'<cbc:UUID schemeName="CUFE-SHA384">'.$this->e($d['invoice']['cufe']).'</cbc:UUID>'
            .$this->el('cbc:DocumentTypeCode', '01').'</cac:DocumentReference>';
        if ($p = $d['person'] ?? null) {
            $x .= '<cac:IssuerParty><cac:Person>'
                .'<cbc:ID schemeName="'.$this->e($p['id_type']).'">'.$this->e($p['id_number']).'</cbc:ID>'
                .$this->el('cbc:FirstName', $p['first_name']).$this->el('cbc:FamilyName', $p['last_name'])
                .(filled($p['job'] ?? null) ? $this->el('cbc:JobTitle', $p['job']) : '')
                .(filled($p['department'] ?? null) ? $this->el('cbc:OrganizationDepartment', $p['department']) : '')
                .'</cac:Person></cac:IssuerParty>';
        }
        $x .= '</cac:DocumentResponse></ApplicationResponse>';

        return ['xml' => $x, 'cude' => $cude];
    }

    public function cude(array $d): string
    {
        return hash('sha384', $d['number'].$d['issue_date'].$d['issue_time'].$d['sender']['id'].$d['receiver']['id']
            .$d['code'].$d['invoice']['number'].'01'.$d['software']['pin']);
    }

    private function party(array $p): string
    {
        return '<cac:PartyTaxScheme>'.$this->el('cbc:RegistrationName', $p['name'])
            .'<cbc:CompanyID '.self::DIAN.($p['id_type'] === '31' ? ' schemeID="'.$this->e($p['dv']).'"' : '').' schemeName="'.$this->e($p['id_type']).'" schemeVersionID="'.$this->e($p['type'] ?? '1').'">'.$this->e($p['id']).'</cbc:CompanyID>'
            .'<cac:TaxScheme>'.$this->el('cbc:ID', $p['tax_scheme'][0]).$this->el('cbc:Name', $p['tax_scheme'][1]).'</cac:TaxScheme></cac:PartyTaxScheme>';
    }

    private function el(string $tag, mixed $value): string
    {
        return '<'.$tag.'>'.$this->e((string) $value).'</'.$tag.'>';
    }

    private function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
