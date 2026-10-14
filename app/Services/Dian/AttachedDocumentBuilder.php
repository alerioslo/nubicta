<?php

namespace App\Services\Dian;

/**
 * AttachedDocument (Anexo tecnico, seccion "Documento contenedor"): envuelve el XML firmado del documento y el
 * ApplicationResponse de la DIAN. Es el XML que va dentro del ZIP que se envia al adquiriente junto con el PDF.
 */
class AttachedDocumentBuilder
{
    /**
     * @param  array  $d  documento normalizado por DocumentMapper
     * @param  array{key:string, date:string, time:string, code:string}  $validation  datos del ApplicationResponse
     */
    public function build(array $d, string $signedXml, string $applicationResponse, array $validation): string
    {
        $isDs = in_array($d['kind'], ['DS', 'NA'], true);
        $sender = $isDs ? $d['customer'] : $d['supplier'];
        $receiver = $isDs ? $d['supplier'] : $d['customer'];
        $now = now()->setTimezone('America/Bogota');

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
        $x .= '<AttachedDocument xmlns="urn:oasis:names:specification:ubl:schema:xsd:AttachedDocument-2" '
            .'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
            .'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" '
            .'xmlns:ccts="urn:un:unece:uncefact:data:specification:CoreComponentTypeSchemaModule:2" '
            .'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '
            .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" '
            .'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#">';
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>';
        $x .= $this->el('cbc:UBLVersionID', 'UBL 2.1').$this->el('cbc:CustomizationID', 'Documentos adjuntos')
            .$this->el('cbc:ProfileID', 'Factura Electrónica de Venta').$this->el('cbc:ProfileExecutionID', $d['environment'])
            .$this->el('cbc:ID', $d['number']).$this->el('cbc:IssueDate', $now->toDateString()).$this->el('cbc:IssueTime', $now->format('H:i:s').'-05:00')
            .$this->el('cbc:DocumentType', 'Contenedor de Factura Electrónica').$this->el('cbc:ParentDocumentID', $d['number']);
        $x .= '<cac:SenderParty>'.$this->party($sender).'</cac:SenderParty>';
        $x .= '<cac:ReceiverParty>'.$this->party($receiver).'</cac:ReceiverParty>';
        $x .= '<cac:Attachment><cac:ExternalReference>'.$this->el('cbc:MimeCode', 'text/xml').$this->el('cbc:EncodingCode', 'UTF-8')
            .'<cbc:Description>'.$this->cdata($signedXml).'</cbc:Description></cac:ExternalReference></cac:Attachment>';
        $x .= '<cac:ParentDocumentLineReference>'.$this->el('cbc:LineID', 1).'<cac:DocumentReference>'.$this->el('cbc:ID', $d['number'])
            .'<cbc:UUID schemeName="'.($isDs ? 'CUDS-SHA384' : ($d['kind'] === 'FV' ? 'CUFE-SHA384' : 'CUDE-SHA384')).'">'.$this->e($validation['key']).'</cbc:UUID>'
            .$this->el('cbc:IssueDate', $d['issue_date']).$this->el('cbc:DocumentType', 'ApplicationResponse')
            .'<cac:Attachment><cac:ExternalReference>'.$this->el('cbc:MimeCode', 'text/xml').$this->el('cbc:EncodingCode', 'UTF-8')
            .'<cbc:Description>'.$this->cdata($applicationResponse).'</cbc:Description></cac:ExternalReference></cac:Attachment>'
            .'<cac:ResultOfVerification>'.$this->el('cbc:ValidatorID', 'Unidad Especial Dirección de Impuestos y Aduanas Nacionales')
            .$this->el('cbc:ValidationResultCode', $validation['code']).$this->el('cbc:ValidationDate', $validation['date']).$this->el('cbc:ValidationTime', $validation['time'])
            .'</cac:ResultOfVerification></cac:DocumentReference></cac:ParentDocumentLineReference>';

        return $x.'</AttachedDocument>';
    }

    private function party(array $p): string
    {
        return '<cac:PartyTaxScheme>'.$this->el('cbc:RegistrationName', $p['name'])
            .'<cbc:CompanyID schemeAgencyID="195" schemeAgencyName="CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)"'
            .($p['id_type'] === '31' ? ' schemeID="'.$this->e($p['dv']).'"' : '').' schemeName="'.$this->e($p['id_type']).'">'.$this->e($p['id']).'</cbc:CompanyID>'
            .'<cbc:TaxLevelCode listName="48">'.$this->e($p['tax_level']).'</cbc:TaxLevelCode>'
            .'<cac:TaxScheme>'.$this->el('cbc:ID', $p['tax_scheme'][0]).$this->el('cbc:Name', $p['tax_scheme'][1]).'</cac:TaxScheme></cac:PartyTaxScheme>';
    }

    private function cdata(string $xml): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $xml).']]>';
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
