<?php

namespace App\Services\Dian;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente SOAP 1.2 del servicio WcfDianCustomerServices con WS-Security:
 * BinarySecurityToken X.509 y firma RSA-SHA256 (exc-c14n) sobre wsa:To, como lo exige la DIAN.
 */
class SoapDianTransport implements DianTransport
{
    /** Parametros que acepta cada operacion, en el orden del WSDL. */
    private const PARAMS = [
        'SendBillSync' => ['fileName', 'contentFile'],
        'SendBillAsync' => ['fileName', 'contentFile'],
        'SendTestSetAsync' => ['fileName', 'contentFile', 'testSetId'],
        'SendNominaSync' => ['contentFile'],
        'SendEventUpdateStatus' => ['contentFile'],
        'GetStatus' => ['trackId'],
        'GetStatusZip' => ['trackId'],
        'GetNumberingRange' => ['accountCode', 'accountCodeT', 'softwareCode'],
        'GetXmlByDocumentKey' => ['trackId'],
    ];

    private const WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    private const WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private const X509 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';

    /** @param array{cert:string, pkey:string} $bundle */
    public function __construct(private readonly string $endpoint, private readonly array $bundle, private readonly int $timeout = 60) {}

    public function call(string $operation, array $params): DianTransportResponse
    {
        if (! isset(self::PARAMS[$operation])) {
            throw new RuntimeException("Operación DIAN no soportada: $operation");
        }
        $envelope = $this->envelope($operation, $params);
        $started = microtime(true);
        $action = config('dian.soap_action_base').$operation;
        $response = Http::withHeaders(['Content-Type' => 'application/soap+xml;charset=UTF-8;action="'.$action.'"'])
            ->withBody($envelope, 'application/soap+xml;charset=UTF-8;action="'.$action.'"')
            ->timeout($this->timeout)->connectTimeout(15)
            ->post($this->endpoint);

        return new DianTransportResponse($response->status(), (string) $response->body(), (int) ((microtime(true) - $started) * 1000));
    }

    public function envelope(string $operation, array $params): string
    {
        $der = $this->der();
        $tokenId = 'X509-'.Str::upper(Str::uuid()->getHex());
        $toId = 'id-'.Str::upper(Str::uuid()->getHex());
        $created = now()->utc();
        $body = '';
        foreach (self::PARAMS[$operation] as $p) {
            $body .= '<wcf:'.$p.'>'.htmlspecialchars((string) ($params[$p] ?? ''), ENT_XML1).'</wcf:'.$p.'>';
        }

        $xml = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:wcf="http://wcf.dian.colombia">'
            .'<soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">'
            .'<wsse:Security xmlns:wsse="'.self::WSSE.'" xmlns:wsu="'.self::WSU.'">'
            .'<wsu:Timestamp wsu:Id="TS-'.Str::upper(Str::uuid()->getHex()).'"><wsu:Created>'.$created->format('Y-m-d\TH:i:s.v\Z').'</wsu:Created>'
            .'<wsu:Expires>'.$created->copy()->addMinutes(10)->format('Y-m-d\TH:i:s.v\Z').'</wsu:Expires></wsu:Timestamp>'
            .'<wsse:BinarySecurityToken EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary" ValueType="'.self::X509.'" wsu:Id="'.$tokenId.'">'.base64_encode($der).'</wsse:BinarySecurityToken>'
            .'<ds:Signature Id="SIG-'.Str::upper(Str::uuid()->getHex()).'" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
            .'<ds:SignedInfo><ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"><ec:InclusiveNamespaces PrefixList="wsa soap wcf" xmlns:ec="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:CanonicalizationMethod>'
            .'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            .'<ds:Reference URI="#'.$toId.'"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"><ec:InclusiveNamespaces PrefixList="soap wcf" xmlns:ec="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transform></ds:Transforms>'
            .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue></ds:DigestValue></ds:Reference></ds:SignedInfo>'
            .'<ds:SignatureValue></ds:SignatureValue>'
            .'<ds:KeyInfo Id="KI-'.Str::upper(Str::uuid()->getHex()).'"><wsse:SecurityTokenReference wsu:Id="STR-'.Str::upper(Str::uuid()->getHex()).'"><wsse:Reference URI="#'.$tokenId.'" ValueType="'.self::X509.'"/></wsse:SecurityTokenReference></ds:KeyInfo>'
            .'</ds:Signature></wsse:Security>'
            .'<wsa:Action>'.config('dian.soap_action_base').$operation.'</wsa:Action>'
            .'<wsa:To wsu:Id="'.$toId.'" xmlns:wsu="'.self::WSU.'">'.htmlspecialchars($this->endpoint, ENT_XML1).'</wsa:To>'
            .'</soap:Header><soap:Body><wcf:'.$operation.'>'.$body.'</wcf:'.$operation.'></soap:Body></soap:Envelope>';

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xp->registerNamespace('wsa', 'http://www.w3.org/2005/08/addressing');
        $to = $xp->query('//wsa:To')->item(0);
        $xp->query('//ds:DigestValue')->item(0)->nodeValue = base64_encode(hash('sha256', $to->C14N(true, false, null, ['soap', 'wcf']), true));
        $signedInfo = $xp->query('//ds:SignedInfo')->item(0);
        $key = openssl_pkey_get_private($this->bundle['pkey']);
        if (! $key || ! openssl_sign($signedInfo->C14N(true, false, null, ['wsa', 'soap', 'wcf']), $raw, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No fue posible firmar la petición SOAP con el certificado.');
        }
        $xp->query('//ds:SignatureValue')->item(0)->nodeValue = base64_encode($raw);

        return $doc->saveXML($doc->documentElement);
    }

    private function der(): string
    {
        preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $this->bundle['cert'], $m);

        return base64_decode(preg_replace('/\s+/', '', $m[1] ?? ''));
    }
}
