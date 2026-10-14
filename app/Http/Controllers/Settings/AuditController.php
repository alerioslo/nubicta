<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->where('company_id', $request->user()->company_id)
            ->with('user:id,name')
            ->when($request->input('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'date' => $l->created_at->format('d/m/Y H:i:s'),
                'user' => $l->user?->name,
                'event' => $l->event,
                'entity' => $l->auditable_type ? class_basename($l->auditable_type).' #'.$l->auditable_id : null,
                'old' => $l->old_values,
                'new' => $l->new_values,
                'ip' => $l->ip,
            ]);

        return Inertia::render('Settings/Audit', [
            'logs' => $logs,
            'filters' => $request->only('event', 'from', 'to'),
        ]);
    }
}
