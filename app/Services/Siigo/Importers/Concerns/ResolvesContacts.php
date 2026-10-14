<?php

namespace App\Services\Siigo\Importers\Concerns;

use App\Models\Contact;

/** Ubica el tercero de un documento de Siigo; si fue borrado en Siigo se crea uno minimo para no perder el documento. */
trait ResolvesContacts
{
    /** @var array<string, int>|null identificacion|sucursal => contact_id */
    private ?array $contactsByIdentification = null;

    protected function contactId(array $ref, string $flag = 'is_customer'): int
    {
        if ($id = $this->localId('contact', $ref['id'] ?? null)) {
            return $id;
        }

        $this->contactsByIdentification ??= Contact::withoutGlobalScopes()->where('company_id', $this->companyId)
            ->get(['id', 'identification', 'branch_office'])
            ->mapWithKeys(fn ($c) => ["{$c->identification}|{$c->branch_office}" => $c->id])->all();

        $identification = trim((string) ($ref['identification'] ?? ''));
        $branch = (int) ($ref['branch_office'] ?? 0);
        $key = "$identification|$branch";
        if (isset($this->contactsByIdentification[$key])) {
            return $this->contactsByIdentification[$key];
        }

        $contact = Contact::create([
            'company_id' => $this->companyId,
            'siigo_id' => $ref['id'] ?? null,
            $flag => true,
            'identification' => $identification ?: 'SIN-ID',
            'branch_office' => $branch,
            'first_name' => 'Tercero',
            'last_name' => $identification,
            'fiscal_responsibilities' => ['R-99-PN'],
            'observations' => 'Creado al importar documentos de Siigo: el tercero no existe en el listado de terceros.',
        ]);

        return $this->contactsByIdentification[$key] = $contact->id;
    }
}
