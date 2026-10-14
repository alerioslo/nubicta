<?php

namespace App\Services\Dian;

use Illuminate\Support\Str;

/**
 * Responde como el servicio WcfDianCustomerServices sin salir a la red.
 * Mantiene el mismo formato SOAP para que el parser sea el mismo en modo real.
 */
class SimulatedDianTransport implements DianTransport
{
    /** @param array<array<string, mixed>> $ranges */
    public function __construct(private readonly array $ranges = []) {}

    public function call(string $operation, array $params): DianTransportResponse
    {
        $started = microtime(true);
        usleep(random_int(80, 250) * 1000);

        $result = match ($operation) {
            'SendTestSetAsync' => $this->zipKey($params),
            'SendBillSync', 'SendNominaSync', 'GetStatus', 'GetStatusZip' => $this->dianResponse($params),
            'GetNumberingRange' => $this->numberingRange($params),
            'SendEventUpdateStatus' => $this->eventResponse($params),
            'GetXmlByDocumentKey' => $this->xmlByKey($params),
            default => '<b:StatusCode>99</b:StatusCode><b:StatusDescription>Operación no soportada</b:StatusDescription>',
        };

        $body = '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing">'
            .'<s:Header><a:Action s:mustUnderstand="1">'.config('dian.soap_action_base').$operation.'Response</a:Action></s:Header>'
            .'<s:Body><'.$operation.'Response xmlns="http://wcf.dian.colombia"><'.$operation.'Result xmlns:b="http://schemas.datacontract.org/2004/07/DianResponse" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
            .$result
            .'</'.$operation.'Result></'.$operation.'Response></s:Body></s:Envelope>';

        return new DianTransportResponse(200, $body, (int) ((microtime(true) - $started) * 1000));
    }

    private function zipKey(array $params): string
    {
        if (blank($params['testSetId'] ?? null)) {
            return '<b:ErrorMessageList><c:XmlParamsResponseTrackId xmlns:c="http://schemas.datacontract.org/2004/07/XmlParamsResponseTrackId"><c:ProcessedMessage>TestSetId no informado o no existe.</c:ProcessedMessage></c:XmlParamsResponseTrackId></b:ErrorMessageList><b:ZipKey i:nil="true"/>';
        }

        return '<b:ErrorMessageList/><b:ZipKey>'.Str::uuid().'</b:ZipKey>';
    }

    private function dianResponse(array $params): string
    {
        $number = $params['number'] ?? 'SETP990000001';
        $kind = $params['kind'] ?? 'FV';
        $label = [
            'FV' => 'La Factura electrónica', 'NC' => 'La Nota Crédito electrónica', 'ND' => 'La Nota Débito electrónica',
            'DS' => 'El Documento Soporte', 'NA' => 'La Nota de Ajuste', 'NE' => 'La Nómina Individual electrónica',
        ][$kind] ?? 'El documento';
        $key = $params['cufe'] ?? hash('sha384', $number.microtime());

        return '<b:ErrorMessage xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays"/>'
            .'<b:IsValid>true</b:IsValid>'
            .'<b:StatusCode>00</b:StatusCode>'
            .'<b:StatusDescription>Procesado Correctamente.</b:StatusDescription>'
            .'<b:StatusMessage>'.$label.' '.$number.', ha sido autorizada.</b:StatusMessage>'
            .'<b:XmlBase64Bytes>'.base64_encode($this->applicationResponse($number, $key)).'</b:XmlBase64Bytes>'
            .'<b:XmlBytes i:nil="true"/>'
            .'<b:XmlDocumentKey>'.$key.'</b:XmlDocumentKey>'
            .'<b:XmlFileName>'.($params['fileName'] ?? 'fv'.$number).'</b:XmlFileName>';
    }

    /** ApplicationResponse como el que devuelve la DIAN (sin firma, marcado como simulado). */
    private function applicationResponse(string $number, string $key): string
    {
        $now = now()->setTimezone('America/Bogota');

        return '<?xml version="1.0" encoding="UTF-8"?><ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" '
            .'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:UBLVersionID>UBL 2.1</cbc:UBLVersionID><cbc:CustomizationID>1</cbc:CustomizationID><cbc:ProfileID>DIAN 2.1</cbc:ProfileID>'
            .'<cbc:ID>SIMULADO-'.$number.'</cbc:ID><cbc:IssueDate>'.$now->toDateString().'</cbc:IssueDate><cbc:IssueTime>'.$now->format('H:i:s').'-05:00</cbc:IssueTime>'
            .'<cbc:Note>Respuesta simulada: no fue emitida por la DIAN.</cbc:Note>'
            .'<cac:DocumentResponse><cac:Response><cbc:ResponseCode>02</cbc:ResponseCode><cbc:Description>Documento validado por la DIAN</cbc:Description></cac:Response>'
            .'<cac:DocumentReference><cbc:ID>'.$number.'</cbc:ID><cbc:UUID schemeName="CUFE-SHA384">'.$key.'</cbc:UUID></cac:DocumentReference></cac:DocumentResponse>'
            .'</ApplicationResponse>';
    }

    /** Valida las reglas basicas del registro de eventos RADIAN que aplica la DIAN. */
    private function eventResponse(array $params): string
    {
        $code = $params['code'] ?? '030';
        $error = match (true) {
            ($params['payment_means'] ?? '2') !== '2' => 'Regla: LGC01, Rechazo: La factura electrónica de venta no fue emitida con forma de pago a crédito.',
            $code === '032' && ! in_array('030', $params['previous'] ?? [], true) => 'Regla: LGC05, Rechazo: No se puede registrar el evento 032 sin el acuse de recibo (030).',
            in_array($code, ['031', '033'], true) && ! in_array('032', $params['previous'] ?? [], true) => 'Regla: LGC07, Rechazo: No se puede registrar el evento sin el recibo del bien o prestación del servicio (032).',
            default => null,
        };
        $key = $params['cufe'] ?? '';
        if ($error) {
            return '<b:ErrorMessage xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays"><c:string>'.$error.'</c:string></b:ErrorMessage>'
                .'<b:IsValid>false</b:IsValid><b:StatusCode>99</b:StatusCode><b:StatusDescription>Validación contiene errores en campos mandatorios.</b:StatusDescription>'
                .'<b:StatusMessage>'.$error.'</b:StatusMessage><b:XmlDocumentKey>'.$key.'</b:XmlDocumentKey>';
        }

        return '<b:ErrorMessage xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays"/>'
            .'<b:IsValid>true</b:IsValid><b:StatusCode>00</b:StatusCode><b:StatusDescription>Procesado Correctamente.</b:StatusDescription>'
            .'<b:StatusMessage>Evento '.$code.' '.($params['number'] ?? '').' registrado (simulado).</b:StatusMessage>'
            .'<b:XmlBase64Bytes>'.base64_encode($this->applicationResponse((string) ($params['number'] ?? ''), $key)).'</b:XmlBase64Bytes>'
            .'<b:XmlDocumentKey>'.$key.'</b:XmlDocumentKey>';
    }

    /** Devuelve el XML de una factura emitida desde este mismo ERP (por cualquier empresa); las demas no existen en modo simulado. */
    private function xmlByKey(array $params): string
    {
        $doc = \App\Models\SalesDocument::query()->withoutGlobalScopes()->where('cufe', $params['trackId'] ?? '')->first();
        $xml = $doc && $doc->xml_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->xml_path)
            ? \Illuminate\Support\Facades\Storage::disk('local')->get($doc->xml_path) : null;
        if (! $xml) {
            return '<b:Code>404</b:Code><b:Message>No se encontró documento con el CUFE indicado.</b:Message><b:XmlBytesBase64 i:nil="true"/>';
        }

        return '<b:Code>100</b:Code><b:Message>Accion completada OK</b:Message><b:XmlBytesBase64>'.base64_encode($xml).'</b:XmlBytesBase64>';
    }

    private function numberingRange(array $params): string
    {
        if (blank($params['softwareCode'] ?? null)) {
            return '<b:OperationCode>301</b:OperationCode><b:OperationDescription>El software no está asociado al NIT consultado.</b:OperationDescription><b:ResponseList/>';
        }
        $items = '';
        foreach ($this->ranges as $r) {
            $items .= '<c:NumberRangeResponse>'
                .'<c:ResolutionNumber>'.$r['resolution'].'</c:ResolutionNumber>'
                .'<c:ResolutionDate>'.$r['resolution_date'].'</c:ResolutionDate>'
                .'<c:Prefix>'.$r['prefix'].'</c:Prefix>'
                .'<c:FromNumber>'.$r['from'].'</c:FromNumber>'
                .'<c:ToNumber>'.$r['to'].'</c:ToNumber>'
                .'<c:ValidDateFrom>'.$r['valid_from'].'</c:ValidDateFrom>'
                .'<c:ValidDateTo>'.$r['valid_to'].'</c:ValidDateTo>'
                .'<c:TechnicalKey>'.($r['technical_key'] ?? hash('sha256', $r['resolution'].$r['prefix'])).'</c:TechnicalKey>'
                .'</c:NumberRangeResponse>';
        }

        return '<b:OperationCode>100</b:OperationCode><b:OperationDescription>Acción completada OK.</b:OperationDescription>'
            .'<b:ResponseList xmlns:c="http://schemas.datacontract.org/2004/07/NumberRangeResponse">'.$items.'</b:ResponseList>';
    }
}
