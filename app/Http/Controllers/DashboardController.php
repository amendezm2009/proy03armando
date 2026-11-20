<?php

namespace App\Http\Controllers;

use App\Actions\BuildAdminDashboard;
use App\Actions\BuildClientDashboard;
use App\Http\Requests\DashboardRequest;
use Illuminate\View\View;

/**
 * Paneles por rol. Cada uno exige su permiso en la ruta (routes/web.php): dashboard.admin o dashboard.client.
 * Al iniciar sesión o registrarse, User::homeRoute() lleva a cada persona al de su rol. Cada lista pagina con su
 * propio tamaño (ver DashboardRequest).
 */
class DashboardController extends Controller
{
    public function admin(DashboardRequest $request, BuildAdminDashboard $build): View
    {
        return view('dashboard.admin', ['stats' => $build->handle(
            attentionSize: $request->size('attention_per_page'),
            productsSize: $request->size('products_per_page'),
            usersSize: $request->size('users_per_page'),
        )]);
    }

    public function client(DashboardRequest $request, BuildClientDashboard $build): View
    {
        return view('dashboard.client', $build->handle($request->size('per_page')));
    }
}
