<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\CrmTask;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\Documents\DocumentMailer;
use App\Services\Mail\CompanyMailer;
use App\Support\CurrentCompany;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Configuración > Recordatorios automáticos y Tareas de seguimiento de cobranza.
 * Se ejecuta a diario (collections:run); cada aviso queda en collection_notices para no repetirlo.
 */
class CollectionAutomation
{
    /** Si el cron dejó de correr unos días, las tareas pendientes se generan dentro de esta ventana. */
    private const TASK_WINDOW_DAYS = 6;

    public function __construct(private readonly DocumentMailer $mailer) {}

    public static function settings(string $page, ?int $companyId = null): array
    {
        $defaults = collect(config("settings_pages.$page.sections", []))->flatMap(fn ($s) => $s['fields'])
            ->mapWithKeys(fn ($f) => [$f['key'] => $f['default'] ?? null])->all();
        $saved = json_decode((string) DB::table('app_settings')->where('company_id', $companyId ?? CurrentCompany::id())->where('key', $page)->value('value'), true) ?: [];

        return $saved + $defaults;
    }

    /** @return array{sent:int, skipped:int, errors:list<string>} */
    public function reminders(?Carbon $today = null): array
    {
        $s = self::settings('recordatorios');
        $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
        if (! $s['enabled'] || ! CompanyMailer::configured(CurrentCompany::id())) {
            return $result;
        }
        $today = ($today ?? now())->copy()->startOfDay();
        foreach ($this->openInvoices() as $doc) {
            $stage = $this->reminderStage($s, $doc, $today);
            if (! $stage || $this->noticed($doc, 'recordatorio', $stage)) {
                continue;
            }
            try {
                $this->sendReminder($doc, $s);
                $this->notice($doc, 'recordatorio', $stage, $today, $doc->contact?->email);
                $result['sent']++;
            } catch (Throwable $e) {
                $result['skipped']++;
                if (count($result['errors']) < 10) {
                    $result['errors'][] = "{$doc->name}: {$e->getMessage()}";
                }
            }
        }

        return $result;
    }

    /** @return array{created:int} */
    public function tasks(?Carbon $today = null): array
    {
        $s = self::settings('cobranza');
        if (! $s['enabled']) {
            return ['created' => 0];
        }
        $today = ($today ?? now())->copy()->startOfDay();
        $stages = array_filter([1 => (int) $s['first_days'], 2 => (int) $s['second_days'], 3 => (int) $s['third_days']], fn ($d) => $d > 0);
        $responsible = $this->user((string) ($s['responsible'] ?? ''));
        $created = 0;
        foreach ($this->openInvoices()->where('balance', '>=', (float) $s['min_balance']) as $doc) {
            $overdue = (int) $doc->due_date->diffInDays($today, false);
            foreach (array_reverse($stages, true) as $n => $days) {
                if ($overdue < $days || $overdue > $days + self::TASK_WINDOW_DAYS) {
                    continue;
                }
                if (! $this->noticed($doc, 'tarea', "gestion-$n")) {
                    CrmTask::create([
                        'contact_id' => $doc->contact_id, 'assigned_to' => $this->collector($doc) ?? $responsible, 'category' => '2 - Comercial',
                        'description' => mb_substr("Gestión de cobro {$n} ({$s['channel']}): {$doc->name} con saldo $".number_format((float) $doc->balance, 0, ',', '.')
                            .", vencida desde el {$doc->due_date->format('d/m/Y')}.", 0, 500),
                        'due_date' => $today->toDateString(), 'origin' => 'Cobranza', 'expected_value' => $doc->balance, 'status' => 'Pendiente',
                        'source_document' => $doc->name,
                    ]);
                    $this->notice($doc, 'tarea', "gestion-$n", $today, $s['channel']);
                    $created++;
                }
                break;
            }
        }

        return ['created' => $created];
    }

    public function sendReminder(SalesDocument $doc, array $s, ?string $to = null): void
    {
        $to = trim((string) ($to ?: $doc->contact?->email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('el cliente no tiene un correo electrónico válido');
        }
        if (CompanyMailer::blocked($doc->company_id, $to)) {
            throw new RuntimeException("el correo {$to} está bloqueado");
        }
        $company = Company::query()->find($doc->company_id);
        $from = $company?->business_name ?: trim("{$company?->first_name} {$company?->last_name}");
        [$fromEmail, $fromName] = CompanyMailer::from($doc->company_id, $from);
        $text = strtr((string) $s['message'], [
            '{cliente}' => (string) $doc->contact?->name, '{factura}' => $doc->name,
            '{saldo}' => '$'.number_format((float) $doc->balance, 0, ',', '.'), '{vencimiento}' => $doc->due_date?->format('d/m/Y') ?? '',
            '{empresa}' => $from,
        ]);
        $pdf = $s['attach_pdf'] ? $this->mailer->pdf($doc) : null;
        try {
            CompanyMailer::mailer($doc->company_id)->send([], [], function (Message $m) use ($doc, $to, $company, $from, $fromEmail, $fromName, $text, $pdf) {
                $m->from($fromEmail, $fromName)->to($to)->subject("Recordatorio de pago {$doc->name} - {$from}");
                if ($company?->email) {
                    $m->replyTo($company->email, $from);
                }
                $m->html(nl2br(e($text)));
                if ($pdf) {
                    $m->attach($pdf, ['as' => "{$doc->name}.pdf", 'mime' => 'application/pdf']);
                }
            });
        } finally {
            $pdf && @unlink($pdf);
        }
    }

    /** @return Collection<int, SalesDocument> */
    public function openInvoices(): Collection
    {
        return SalesDocument::query()->where('type', 'FV')->where('balance', '>', 0)->whereNotNull('due_date')
            ->whereNull('annulled_at')->with('contact:id,name,email,collector_id')->get();
    }

    private function reminderStage(array $s, SalesDocument $doc, Carbon $today): ?string
    {
        $days = (int) $doc->due_date->copy()->startOfDay()->diffInDays($today, false);
        $after = (int) $s['after_days'];
        $repeat = (int) $s['repeat_days'];

        return match (true) {
            (int) $s['before_days'] > 0 && $days === -(int) $s['before_days'] => 'antes',
            (bool) $s['on_due'] && $days === 0 => 'vence',
            $after > 0 && $days === $after => "vencida-$days",
            $after > 0 && $repeat > 0 && $days > $after && ($days - $after) % $repeat === 0 => "vencida-$days",
            default => null,
        };
    }

    private function noticed(SalesDocument $doc, string $kind, string $stage): bool
    {
        return DB::table('collection_notices')->where('sales_document_id', $doc->id)->where('kind', $kind)->where('stage', $stage)->exists();
    }

    private function notice(SalesDocument $doc, string $kind, string $stage, Carbon $day, ?string $detail): void
    {
        DB::table('collection_notices')->insertOrIgnore([
            'company_id' => $doc->company_id, 'sales_document_id' => $doc->id, 'kind' => $kind, 'stage' => $stage,
            'sent_on' => $day->toDateString(), 'detail' => $detail ? mb_substr($detail, 0, 255) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $name): ?int
    {
        $name = trim($name);

        return $name === '' ? null : User::query()->where('company_id', CurrentCompany::id())
            ->where(fn ($q) => $q->where('email', $name)->orWhere('name', 'like', "%$name%"))->value('id');
    }

    /** Usuario del cobrador asignado al cliente. */
    private function collector(SalesDocument $doc): ?int
    {
        return $doc->contact?->collector_id ? DB::table('sellers')->where('id', $doc->contact->collector_id)->value('user_id') : null;
    }
}
