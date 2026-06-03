<?php

use App\Http\Controladores\AdministradorControlador;
use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\RedAviation\AdminControlador as RedAviationAdminControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth.token', 'role:admin'])->group(function () {
    Route::get('/dashboard-marketplace', [AdministradorControlador::class, 'dashboard']);
    Route::get('/usuarios', [AdministradorControlador::class, 'users']);
    Route::get('/users', [AdministradorControlador::class, 'users']);
    Route::get('/usuarios/{user}', [AdministradorControlador::class, 'showUsuario']);
    Route::get('/users/{user}', [AdministradorControlador::class, 'showUsuario']);
    Route::put('/usuarios/{user}', [AdministradorControlador::class, 'updateUsuario']);
    Route::put('/users/{user}', [AdministradorControlador::class, 'updateUsuario']);
    Route::post('/usuarios/{user}/bloquear', [AdministradorControlador::class, 'blockUsuario']);
    Route::post('/users/{user}/block', [AdministradorControlador::class, 'blockUsuario']);
    Route::post('/usuarios/{user}/activar', [AdministradorControlador::class, 'activateUsuario']);
    Route::post('/users/{user}/activate', [AdministradorControlador::class, 'activateUsuario']);

    Route::get('/clientes', [AdministradorControlador::class, 'clients']);
    Route::get('/proveedores', [AdministradorControlador::class, 'providers']);
    Route::get('/proveedores/{provider}', [AdministradorControlador::class, 'showProveedor']);
    Route::post('/proveedores/{provider}/aprobar', [AdministradorControlador::class, 'approveProveedor']);
    Route::post('/proveedores/{provider}/rechazar', [AdministradorControlador::class, 'rejectProveedor']);
    Route::post('/proveedores/{provider}/suspender', [AdministradorControlador::class, 'suspendProveedor']);
    Route::get('/sobrecargos', [RedAviationAdminControlador::class, 'sobrecargos']);
    Route::put('/sobrecargos/{user}', [RedAviationAdminControlador::class, 'updateSobrecargo']);
    Route::get('/crew', [RedAviationAdminControlador::class, 'sobrecargos']);
    Route::put('/crew/{user}', [RedAviationAdminControlador::class, 'updateSobrecargo']);

    Route::get('/aeronaves', [AdministradorControlador::class, 'aircraft']);
    Route::get('/aeronaves/{aircraft}', [AdministradorControlador::class, 'showAeronave']);
    Route::get('/aircraft-documents/{document}/download', [AeronaveControlador::class, 'downloadAdminDocument']);
    Route::get('/aeronaves/documentos/{document}/descargar', [AeronaveControlador::class, 'downloadAdminDocument']);
    Route::post('/aeronaves/{aircraft}/bloquear', [AdministradorControlador::class, 'blockAeronave']);
    Route::post('/aeronaves/{aircraft}/activar', [AdministradorControlador::class, 'activateAeronave']);

    Route::get('/solicitudes', [AdministradorControlador::class, 'flightRequests']);
    Route::get('/cotizaciones', [AdministradorControlador::class, 'quotes']);
    Route::get('/reservas', [AdministradorControlador::class, 'reservations']);
    Route::get('/pagos', [AdministradorControlador::class, 'payments']);
    Route::get('/comisiones', [AdministradorControlador::class, 'commissions']);
    Route::post('/comisiones/{commission}/liberar', [AdministradorControlador::class, 'releaseComision']);

    Route::get('/demos', [AdministradorControlador::class, 'demos']);
    Route::get('/suscripciones', [AdministradorControlador::class, 'subscriptions']);
    Route::get('/planes', [AdministradorControlador::class, 'plans']);
    Route::post('/planes', [AdministradorControlador::class, 'storePlan']);
    Route::put('/planes/{plan}', [AdministradorControlador::class, 'updatePlan']);

    Route::get('/reportes', [AdministradorControlador::class, 'reports']);
    Route::get('/auditoria', [AdministradorControlador::class, 'audit']);
    Route::get('/configuracion', [AdministradorControlador::class, 'settings']);
    Route::put('/configuracion', [AdministradorControlador::class, 'updateSettings']);
});
