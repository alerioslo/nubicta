<?php

namespace App\Services\Siigo\Importers;

use App\Models\Contact;
use App\Models\ContactPerson;
use Illuminate\Support\Str;

/** Importa terceros desde v1/customers (Siigo devuelve clientes, proveedores y otros en el mismo endpoint). */
class ContactsImporter extends BaseImporter
{
    private const TYPE_FLAGS = ['Customer' => 'is_customer', 'Supplier' => 'is_supplier', 'Other' => 'is_other'];

    public function entity(): string
    {
        return 'terceros';
    }

    protected function handle(array $params): void
    {
        $this->log('Terceros (v1/customers)...');
        $n = 0;
        foreach ($this->siigo->paginate('v1/customers') as $r) {
            $contact = $this->upsert(Contact::class, 'contact', $r, fn ($r) => $this->map($r));
            if ($contact) {
                try {
                    $this->syncPeople($contact, $r['contacts'] ?? []);
                } catch (\Throwable $e) {
                    $this->log("  contactos de {$contact->identification}: ".mb_substr($e->getMessage(), 0, 160));
                }
            }
            if (++$n % 500 === 0) {
                $this->log("  $n...");
            }
        }
    }

    private function map(array $r): array
    {
        $isCompany = ($r['person_type'] ?? 'Person') === 'Company';
        $names = array_values(array_filter(array_map('trim', $r['name'] ?? [])));
        $city = $r['address']['city'] ?? [];
        $phone = $this->cleanPhone($r['phones'][0] ?? null);
        $mainContact = $r['contacts'][0] ?? [];
        $responsibilities = array_values(array_filter(array_column($r['fiscal_responsibilities'] ?? [], 'code')));

        $flag = self::TYPE_FLAGS[$r['type'] ?? 'Customer'] ?? 'is_customer';

        return [
            'siigo_id' => $r['id'],
            $flag => true,
            'person_type' => $isCompany ? 'juridica' : 'natural',
            'identification_type' => (string) ($r['id_type']['code'] ?? '13'),
            'identification' => trim((string) $r['identification']),
            'dv' => isset($r['check_digit']) && $r['check_digit'] !== '' ? (string) $r['check_digit'] : null,
            'branch_office' => (int) ($r['branch_office'] ?? 0),
            'first_name' => $isCompany ? null : ($names[0] ?? null),
            'last_name' => $isCompany ? null : ($names[1] ?? null),
            'business_name' => $isCompany ? implode(' ', $names) : null,
            'name' => implode(' ', $names) ?: (string) $r['identification'],
            'trade_name' => $r['commercial_name'] ?? null,
            'vat_responsible' => (bool) ($r['vat_responsible'] ?? false),
            'fiscal_responsibilities' => $responsibilities ?: ['R-99-PN'],
            'address' => Str::limit(trim((string) ($r['address']['address'] ?? '')), 250, '') ?: null,
            'country_code' => strtoupper($city['country_code'] ?? 'CO'),
            'department_code' => isset($city['state_code']) ? str_pad((string) $city['state_code'], 2, '0', STR_PAD_LEFT) : null,
            'municipality_code' => isset($city['city_code']) ? str_pad((string) $city['city_code'], 5, '0', STR_PAD_LEFT) : null,
            'postal_code' => $r['address']['postal_code'] ?? null,
            'phone_indicative' => $phone['indicative'] ?? null,
            'phone' => $phone['number'] ?? null,
            'phone_extension' => $phone['extension'] ?? null,
            'billing_first_name' => $mainContact['first_name'] ?? null,
            'billing_last_name' => $mainContact['last_name'] ?? null,
            'email' => $this->cleanEmail($mainContact['email'] ?? null),
            'mobile' => $this->cleanPhone($mainContact['phone'] ?? null)['number'] ?? null,
            'seller_id' => $this->localId('seller', $r['related_users']['seller_id'] ?? null),
            'collector_id' => $this->localId('seller', $r['related_users']['collector_id'] ?? null),
            'observations' => $r['comments'] ?? null,
            'active' => (bool) ($r['active'] ?? true),
        ];
    }

    private function syncPeople(Contact $contact, array $people): void
    {
        $contact->people()->delete();
        foreach ($people as $i => $p) {
            if (empty($p['first_name']) && empty($p['email'])) {
                continue;
            }
            $phone = $this->cleanPhone($p['phone'] ?? null);
            ContactPerson::create([
                'contact_id' => $contact->id,
                'first_name' => mb_substr(($p['first_name'] ?? '') ?: '-', 0, 120),
                'last_name' => mb_substr((string) ($p['last_name'] ?? ''), 0, 120) ?: null,
                'email' => $this->cleanEmail($p['email'] ?? null),
                'phone_indicative' => $phone['indicative'] ?? null,
                'phone' => $phone['number'] ?? null,
                'is_payer' => $i === 0,
            ]);
        }
    }

    /** Siigo guarda "0000"/"0000000" cuando el telefono esta vacio. */
    private function cleanPhone(?array $phone): ?array
    {
        if (! $phone) {
            return null;
        }
        $clean = fn ($v, $max) => ($v = trim((string) $v)) === '' || trim($v, '0') === '' ? null : mb_substr($v, 0, $max);
        $indicative = $clean($phone['indicative'] ?? null, 30);
        $number = $clean($phone['number'] ?? null, 30);
        if ($indicative !== null && strlen($indicative) > 6) {
            $number ??= $indicative;
            $indicative = null;
        }

        return array_filter([
            'indicative' => $indicative,
            'number' => $number,
            'extension' => $clean($phone['extension'] ?? null, 10),
        ]) ?: null;
    }

    private function cleanEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
