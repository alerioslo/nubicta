<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = JournalEntry::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('observations', 'like', "%$t%")))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->withCount('lines')
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (JournalEntry $e) => [
                'id' => $e->id,
                'date' => $e->date->translatedFormat('j M Y'),
                'name' => $e->name,
                'observations' => $e->observations,
                'lines' => $e->lines_count,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
            ]);

        return Inertia::render('Accounting/Journals/Index', [
            'entries' => $entries,
            'tabs' => DashboardController::tabs('contabilidad'),
            'filters' => $request->only('q', 'desde', 'hasta'),
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
