<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Menu "Reportes" de Siigo: catalogo por secciones con buscador. */
class ReportsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reports/Index', ['catalog' => config('reports')]);
    }
}
