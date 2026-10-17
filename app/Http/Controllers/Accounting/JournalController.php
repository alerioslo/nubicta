<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\JournalEntry;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        $types = DB::table('document_types')->where('company_id', CurrentCompany::id())->where('type', 'CC')->orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']);
        $contact = DB::table('journal_lines as l')->join('contacts as c', 'c.id', '=', 'l.contact_id')->whereColumn('l.journal_entry_id', 'journal_entries.id')->orderBy('l.id')->limit(1);

        $entries = JournalEntry::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('observations', 'like', "%$t%")
                ->orWhereIn('id', DB::table('journal_lines as l')->join('contacts as c', 'c.id', '=', 'l.contact_id')
                    ->where(fn ($c) => $c->where('c.name', 'like', "%$t%")->orWhere('c.identification', 'like', "$t%"))->select('l.journal_entry_id'))))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->integer('tipo'), fn ($q, $t) => $q->where('document_type_id', $t))
            ->when($request->input('estado'), fn ($q, $s) => $s === 'anulado' ? $q->where('status', 'annulled') : $q->where('status', '!=', 'annulled'))
            ->select('journal_entries.*')
            ->selectSub((clone $contact)->select('c.name'), 'contact_name')
            ->selectSub((clone $contact)->select('c.identification'), 'contact_identification')
            ->withCount('lines')
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (JournalEntry $e) => [
                'id' => $e->id,
                'date' => $e->date->format('d/m/Y'),
                'name' => $e->name,
                'type' => $types->firstWhere('id', $e->document_type_id)?->name,
                'contact' => $e->contact_name,
                'identification' => $e->contact_identification,
                'observations' => $e->observations,
                'lines' => $e->lines_count,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
                'annulled' => $e->status === 'annulled',
            ]);

        return Inertia::render('Accounting/Journals/Index', [
            'entries' => $entries,
            'tabs' => DashboardController::tabs('contabilidad'),
            'filters' => $request->only('q', 'desde', 'hasta', 'tipo', 'estado'),
            'types' => $types->map(fn ($t) => [$t->id, "CC-{$t->code} - {$t->name}"])->values(),
        ]);
    }

    public function show(JournalEntry $journal): Response
    {
        $journal->load(['lines.account:id,code,name', 'lines.contact:id,name,identification']);

        return Inertia::render('Accounting/Journals/Show', [
            'entry' => [
                ...$journal->only('id', 'name', 'observations'),
                'date' => $journal->date->format('d/m/Y'),
                'debit' => (float) $journal->debit,
                'credit' => (float) $journal->credit,
                'lines' => $journal->lines->map(fn ($l) => [
                    'account' => $l->account ? "{$l->account->code} {$l->account->name}" : '',
                    'contact' => $l->contact ? "{$l->contact->identification} {$l->contact->name}" : '',
                    'description' => $l->description,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                ]),
            ],
        ]);
    }
}
