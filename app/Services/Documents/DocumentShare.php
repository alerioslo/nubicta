<?php

namespace App\Services\Documents;

use App\Models\SalesDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * "Copiar enlace": deja una copia en PDF del documento y devuelve un enlace firmado que la entrega sin iniciar sesion.
 * El enlace solo sirve ese PDF y el XML ya generados, nunca consulta datos del cliente.
 */
class DocumentShare
{
    public function __construct(private readonly DocumentMailer $mailer) {}

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public static function path(SalesDocument $doc): string
    {
        return "compartidos/{$doc->company_id}/ventas-{$doc->id}.pdf";
    }

    public function publish(SalesDocument $doc): string
    {
        $pdf = $this->mailer->pdf($doc);
        try {
            self::disk()->put(self::path($doc), (string) file_get_contents($pdf));
        } finally {
            @unlink($pdf);
        }

        return self::url($doc);
    }

    public static function url(SalesDocument $doc, array $query = []): string
    {
        return URL::signedRoute('documents.public', ['id' => $doc->id, 'c' => $doc->company_id] + $query);
    }
}
