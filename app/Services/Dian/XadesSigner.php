<?php

namespace App\Services\Dian;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Firma XAdES-EPES enveloped (RSA-SHA256, C14N inclusiva) segun la politica de firma v2 de la DIAN.
 * La firma va en el segundo ext:UBLExtension del documento UBL.
 */
class XadesSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const POLICY_HASH = 'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=';

    /** @param array{cert:string, pkey:string} $bundle */
    public function __construct(private readonly array $bundle) {}

    public function sign(string $xml, string $role = 'supplier'): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        if (! $doc->loadXML($xml)) {
            throw new RuntimeException('El XML a firmar no es válido.');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $holder = $xp->query('//ext:UBLExtensions/ext:UBLExtension[2]/ext:ExtensionContent')->item(0)
            ?? $xp->query('/*/ext:UBLExtensions/ext:UBLExtension[last()]/ext:ExtensionContent[not(*)]')->item(0);
        if (! $holder instanceof DOMElement) {
            throw new RuntimeException('El documento no tiene la extensión UBL para la firma.');
        }

        $id = 'xmldsig-'.Str::uuid();
        $der = $this->der();
        $x509 = openssl_x509_parse($this->bundle['cert']);
        $issuer = $this->issuerName($x509['issuer'] ?? []);
        $serial = $this->serialDecimal($x509);
        $signingTime = now()->setTimezone('America/Bogota')->format('Y-m-d\TH:i:s.vP');

        $sig = $doc->createElementNS(self::DS, 'ds:Signature');
        $sig->setAttribute('Id', $id);
        $holder->appendChild($sig);

        $signedInfo = $this->child($sig, 'ds:SignedInfo');
        $this->child($signedInfo, 'ds:CanonicalizationMethod')->setAttribute('Algorithm', self::C14N);
        $this->child($signedInfo, 'ds:SignatureMethod')->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $refDoc = $this->reference($signedInfo, $id.'-ref0', '', true);
        $refKey = $this->reference($signedInfo, null, '#'.$id.'-keyinfo');
        $refProps = $this->reference($signedInfo, null, '#'.$id.'-signedprops', false, 'http://uri.etsi.org/01903#SignedProperties');

        $sigValue = $this->child($sig, 'ds:SignatureValue');
        $sigValue->setAttribute('Id', $id.'-sigvalue');

        $keyInfo = $this->child($sig, 'ds:KeyInfo');
        $keyInfo->setAttribute('Id', $id.'-keyinfo');
        $this->child($this->child($keyInfo, 'ds:X509Data'), 'ds:X509Certificate', base64_encode($der));

        $object = $this->child($sig, 'ds:Object');
        $qp = $doc->createElementNS(self::XADES, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#'.$id);
        $object->appendChild($qp);
        $props = $this->x($qp, 'xades:SignedProperties');
        $props->setAttribute('Id', $id.'-signedprops');
        $ssp = $this->x($props, 'xades:SignedSignatureProperties');
        $this->x($ssp, 'xades:SigningTime', $signingTime);
        $cert = $this->x($this->x($ssp, 'xades:SigningCertificate'), 'xades:Cert');
        $digest = $this->x($cert, 'xades:CertDigest');
        $this->child($digest, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $this->child($digest, 'ds:DigestValue', base64_encode(hash('sha256', $der, true)));
        $issuerSerial = $this->x($cert, 'xades:IssuerSerial');
        $this->child($issuerSerial, 'ds:X509IssuerName', $issuer);
        $this->child($issuerSerial, 'ds:X509SerialNumber', $serial);
        $policy = $this->x($this->x($ssp, 'xades:SignaturePolicyIdentifier'), 'xades:SignaturePolicyId');
        $policyId = $this->x($policy, 'xades:SigPolicyId');
        $this->x($policyId, 'xades:Identifier', (string) config('dian.signature_policy_url'));
        $this->x($policyId, 'xades:Description', 'Política de firma para facturas electrónicas de la República de Colombia.');
        $policyHash = $this->x($policy, 'xades:SigPolicyHash');
        $this->child($policyHash, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $this->child($policyHash, 'ds:DigestValue', self::POLICY_HASH);
        $this->x($this->x($this->x($ssp, 'xades:SignerRole'), 'xades:ClaimedRoles'), 'xades:ClaimedRole', $role);

        $this->setDigest($refDoc, $this->documentDigest($doc));
        $this->setDigest($refKey, base64_encode(hash('sha256', $keyInfo->C14N(false, false), true)));
        $this->setDigest($refProps, base64_encode(hash('sha256', $props->C14N(false, false), true)));

        $key = openssl_pkey_get_private($this->bundle['pkey']);
        if (! $key || ! openssl_sign($signedInfo->C14N(false, false), $raw, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No fue posible firmar el documento con el certificado.');
        }
        $sigValue->nodeValue = base64_encode($raw);

        return $doc->saveXML();
    }

    /** Verifica digest y firma de un XML firmado por esta clase (pruebas internas). */
    public static function verify(string $xml): bool
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS);
        $sig = $xp->query('//ds:Signature')->item(0);
        $signedInfo = $xp->query('ds:SignedInfo', $sig)->item(0);
        foreach ($xp->query('ds:Reference', $signedInfo) as $ref) {
            $uri = $ref->getAttribute('URI');
            $expected = $xp->query('ds:DigestValue', $ref)->item(0)->nodeValue;
            if ($uri === '') {
                $actual = (new self(['cert' => '', 'pkey' => '']))->documentDigest($doc);
            } else {
                $node = $xp->query("//*[@Id='".substr($uri, 1)."']")->item(0);
                $actual = base64_encode(hash('sha256', $node->C14N(false, false), true));
            }
            if ($actual !== $expected) {
                return false;
            }
        }
        $cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($xp->query('//ds:X509Certificate')->item(0)->nodeValue, 64)."-----END CERTIFICATE-----\n";

        return openssl_verify($signedInfo->C14N(false, false), base64_decode($xp->query('ds:SignatureValue', $sig)->item(0)->nodeValue), $cert, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Digest del documento completo sin el nodo Signature (transformacion enveloped). */
    private function documentDigest(DOMDocument $doc): string
    {
        $copy = new DOMDocument;
        $copy->loadXML($doc->saveXML());
        $xp = new DOMXPath($copy);
        $xp->registerNamespace('ds', self::DS);
        foreach ($xp->query('//ds:Signature') as $s) {
            $s->parentNode->removeChild($s);
        }

        return base64_encode(hash('sha256', $copy->documentElement->C14N(false, false), true));
    }

    private function reference(DOMElement $signedInfo, ?string $id, string $uri, bool $enveloped = false, ?string $type = null): DOMElement
    {
        $ref = $this->child($signedInfo, 'ds:Reference');
        if ($id) {
            $ref->setAttribute('Id', $id);
        }
        if ($type) {
            $ref->setAttribute('Type', $type);
        }
        $ref->setAttribute('URI', $uri);
        if ($enveloped) {
            $this->child($this->child($ref, 'ds:Transforms'), 'ds:Transform')->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        }
        $this->child($ref, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $this->child($ref, 'ds:DigestValue');

        return $ref;
    }

    private function setDigest(DOMElement $ref, string $value): void
    {
        foreach ($ref->childNodes as $n) {
            if ($n instanceof DOMElement && $n->localName === 'DigestValue') {
                $n->nodeValue = $value;
            }
        }
    }

    private function child(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $parent->ownerDocument->createElementNS(self::DS, $name);
        if ($value !== null) {
            $el->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($el);

        return $el;
    }

    private function x(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $parent->ownerDocument->createElementNS(self::XADES, $name);
        if ($value !== null) {
            $el->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($el);

        return $el;
    }

    private function der(): string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $this->bundle['cert'], $m)) {
            openssl_x509_export($this->bundle['cert'], $pem);
            preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m);
        }

        return base64_decode(preg_replace('/\s+/', '', $m[1]));
    }

    /** Nombre del emisor en orden RFC 4514 (del mas especifico al mas general), como lo valida la DIAN. */
    private function issuerName(array $issuer): string
    {
        $parts = [];
        foreach ($issuer as $k => $v) {
            foreach ((array) $v as $value) {
                $parts[] = $k.'='.$value;
            }
        }

        return implode(',', array_reverse($parts));
    }

    private function serialDecimal(array $x509): string
    {
        $hex = $x509['serialNumberHex'] ?? null;
        if (! $hex) {
            return (string) ($x509['serialNumber'] ?? '0');
        }
        $dec = '0';
        foreach (str_split(strtolower($hex)) as $h) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($h));
        }

        return $dec;
    }
}
