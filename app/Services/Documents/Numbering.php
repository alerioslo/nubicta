<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\PeriodLock;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tipo de documento y consecutivo de cada documento que se registra. */
class Numbering
{
    private const ELECTRONIC = ['DS' => 'SupportDocument', 'NA' => 'SupportDocumentAdjustment'];

    /** Prefijo con el que el documento queda en el libro (CE se muestra como RP). */
    public const LEDGER_PREFIX = ['CE' => 'RP', 'CT' => 'C'];

    /** @var array{0:int, 1:string}|null numero que conserva un documento al editarlo */
    private ?array $reuse = null;

    public function reuse(int $number, string $name): void
    {
        $this->reuse = [$number, $name];
    }

    /**
     * Acepta el id del tipo o la clave "DS-1" de los tipos que Siigo no expone por API;
     * en ese caso crea el tipo con la numeracion indicada.
     */
    public function type(mixed $value, string $expected, array $fallbacks = []): DocumentType
    {
        if (is_numeric($value)) {
            $type = DocumentType::query()->where('type', $expected)->find((int) $value);
        } elseif (is_string($value) && preg_match('/^([A-Z]+)-(\w+)$/', $value, $m) && $m[1] === $expected) {
            $type = DocumentType::query()->where('type', $expected)->where('code', $m[2])->first();
            if (! $type) {
                $fb = collect($fallbacks)->firstWhere('code', $m[2]);
                if (! $fb) {
                    $this->fail('document_type', 'El tipo de documento no existe');
                }
                $type = DocumentType::create([
                    'company_id' => CurrentCompany::id(), 'type' => $expected, 'code' => $m[2], 'name' => $fb['name'],
                    'consecutive' => $fb['consecutive'], 'automatic_number' => true,
                    'electronic_type' => self::ELECTRONIC[$expected] ?? 'NoElectronic', 'active' => true,
                ]);
            }
        } else {
            $type = null;
        }
        if (! $type) {
            $this->fail('document_type', 'El campo tipo es obligatorio');
        }

        return $type;
    }

    /** Tipo CC por codigo (ajustes de cartera 777, traslado de dinero 9901...). */
    public function journalType(string $code, string $name): DocumentType
    {
        return DocumentType::query()->where('type', 'CC')->where('code', $code)->first()
            ?? DocumentType::create(['company_id' => CurrentCompany::id(), 'type' => 'CC', 'code' => $code, 'name' => $name, 'consecutive' => 1, 'automatic_number' => true, 'electronic_type' => 'NoElectronic', 'active' => true]);
    }

    /**
     * Reserva el consecutivo. Nunca repite un numero que ya exista en el libro o en las tablas
     * importadas de Siigo, aunque el consecutivo guardado haya quedado atras.
     *
     * @return array{0:int, 1:string} numero y nombre (FV-2-71108)
     */
    public function take(DocumentType $type, ?string $table = null): array
    {
        if ($this->reuse) {
            [$number, $name] = $this->reuse;
            $this->reuse = null;

            return [$number, $name];
        }

        return DB::transaction(function () use ($type, $table) {
            $row = DocumentType::query()->whereKey($type->id)->lockForUpdate()->first();
            $prefix = (self::LEDGER_PREFIX[$row->type] ?? $row->type).'-'.$row->code.'-';
            $used = (int) DB::table('ledger_lines')->where('company_id', $row->company_id)
                ->where('document', 'like', $prefix.'%')
                ->max(DB::raw('cast(substring(document, '.(strlen($prefix) + 1).') as unsigned)'));
            if ($table) {
                $used = max($used, (int) DB::table($table)->where('company_id', $row->company_id)->where('document_type_id', $row->id)->max('number'));
            }
            $number = max((int) $row->consecutive, $used + 1, (int) ($row->range_from ?? 0));
            if ($row->range_to && $number > $row->range_to) {
                $this->fail('document_type', "El consecutivo $number supera el rango autorizado ({$row->range_to}) para {$row->name}.");
            }
            $row->update(['consecutive' => $number + 1]);

            return [$number, $prefix.$number];
        });
    }

    /** Rechaza fechas en periodos cerrados. */
    public function assertOpen(string $date): void
    {
        $closed = Company::query()->whereKey(CurrentCompany::id())->value('closed_until');
        if ($closed && $date <= substr((string) $closed, 0, 10)) {
            $this->fail('date', 'La fecha está en un periodo cerrado (hasta '.substr((string) $closed, 0, 10).').');
        }
        if (PeriodLock::query()->where('year', (int) substr($date, 0, 4))->where('month', (int) substr($date, 5, 2))->where('locked', true)->exists()) {
            $this->fail('date', 'El periodo '.substr($date, 0, 7).' está bloqueado.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
