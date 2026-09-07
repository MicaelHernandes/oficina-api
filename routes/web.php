<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Métricas para o Prometheus (scrape via ServiceMonitor). Sem autenticação:
// exposto apenas dentro do cluster (Service ClusterIP), não pelo Ingress.
Route::get('/metrics', MetricsController::class);
