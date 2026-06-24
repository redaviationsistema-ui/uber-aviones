<?php

use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\CrewOperationIncidentController;
use App\Http\Controladores\RedAviation\AdminControlador;
use App\Http\Controladores\RedAviation\BillingPlanControlador;
use App\Http\Controladores\RedAviation\ChatControlador;
use App\Http\Controladores\RedAviation\ClientAccessBillingControlador;
use App\Http\Controladores\RedAviation\ClienteControlador;
use App\Http\Controladores\RedAviation\OperadorControlador;
use App\Http\Controladores\RedAviation\ProviderAircraftBillingControlador;
use App\Http\Controladores\RedAviation\SobrecargoControlador;
use App\Http\Controladores\RedAviation\SuscripcionControlador;
use App\Http\Controladores\NotificacionControlador;
use App\Http\Controladores\ReservaControlador;
use Illuminate\Support\Facades\Route;

Route::get('/plans', [BillingPlanControlador::class, 'index']);
Route::get('/billing/plans', [BillingPlanControlador::class, 'index']);
Route::get('/billing/plans/client-access', [BillingPlanControlador::class, 'clientAccess']);
Route::get('/billing/plans/provider-aircraft', [BillingPlanControlador::class, 'providerAircraft']);
Route::post('/client/quotes/preview', [ClienteControlador::class, 'previewQuotes']);
Route::get('/client/access-payment/mobile-return', [ClientAccessBillingControlador::class, 'mobileReturn']);

Route::middleware(['auth.token'])->group(function () {
    Route::get('/crew-operation-incidents', [CrewOperationIncidentController::class, 'index']);
    Route::post('/crew-operation-incidents', [CrewOperationIncidentController::class, 'store']);
    Route::get('/crew-operation-incidents/{id}', [CrewOperationIncidentController::class, 'show']);
    Route::put('/crew-operation-incidents/{id}', [CrewOperationIncidentController::class, 'update']);

    Route::post('/subscriptions/start-trial', [SuscripcionControlador::class, 'startTrial']);
    Route::post('/subscriptions/checkout', [SuscripcionControlador::class, 'checkout']);
    Route::get('/subscriptions/current', [SuscripcionControlador::class, 'current']);
    Route::post('/subscriptions/cancel', [SuscripcionControlador::class, 'cancel']);

    Route::prefix('client')->middleware(['role:client,admin'])->group(function () {
        Route::get('/access-status', [ClientAccessBillingControlador::class, 'status']);
        Route::post('/access-payment/create', [ClientAccessBillingControlador::class, 'create']);
        Route::post('/access-payment/payment-intent', [ClientAccessBillingControlador::class, 'createPaymentIntent']);
        Route::post('/access-payment/confirm', [ClientAccessBillingControlador::class, 'confirmPaymentIntent']);
        Route::get('/access-payment/success', [ClientAccessBillingControlador::class, 'success']);
        Route::get('/access-payment/cancel', [ClientAccessBillingControlador::class, 'cancel']);
        Route::post('/flight-requests', [ClienteControlador::class, 'storeFlightRequest']);
        Route::get('/flight-requests', [ClienteControlador::class, 'indexFlightRequests']);
        Route::get('/flight-requests/{flightRequest}', [ClienteControlador::class, 'showFlightRequest']);
        Route::get('/flight-requests/{reservation}/contract', [ReservaControlador::class, 'showContract']);
        Route::get('/flight-requests/{reservation}/contract/pdf', [ReservaControlador::class, 'downloadContractPdf']);
        Route::post('/flight-requests/{reservation}/contract/generate', [ReservaControlador::class, 'generateContract']);
        Route::post('/flight-requests/{reservation}/contract/docusign', [ReservaControlador::class, 'startEmbeddedSigning']);
        Route::post('/flight-requests/{reservation}/contract/sign', [ReservaControlador::class, 'signContract']);
        Route::get('/reservations/{reservation}/contract', [ReservaControlador::class, 'showContract']);
        Route::get('/reservations/{reservation}/contract/pdf', [ReservaControlador::class, 'downloadContractPdf']);
        Route::post('/reservations/{reservation}/contract/generate', [ReservaControlador::class, 'generateContract']);
        Route::post('/reservations/{reservation}/contract/docusign', [ReservaControlador::class, 'startEmbeddedSigning']);
        Route::post('/reservations/{reservation}/contract/sign', [ReservaControlador::class, 'signContract']);
    });

    Route::prefix('client')->middleware(['role:client,admin', 'subscription.active'])->group(function () {
        Route::get('/dashboard', [ClienteControlador::class, 'dashboard']);
        Route::get('/aircraft', [ClienteControlador::class, 'indexAircraft']);
        Route::get('/operations/{operation}/tracking', [ClienteControlador::class, 'tracking']);
    });

    Route::prefix('operator')->middleware(['role:provider,admin'])->group(function () {
        Route::post('/aircraft', [OperadorControlador::class, 'storeAircraft']);
        Route::post('/aircraft/{aircraft}/documents', [AeronaveControlador::class, 'storeDocument']);
        Route::get('/aircraft/{aircraft}/documents/{document}/download', [AeronaveControlador::class, 'downloadDocument']);
    });

    Route::prefix('operator')->middleware(['role:provider,admin', 'operator.verified'])->group(function () {
        Route::get('/dashboard', [OperadorControlador::class, 'dashboard']);
        Route::get('/my-dashboard', [OperadorControlador::class, 'dashboard']);
        Route::get('/my-aircraft', [OperadorControlador::class, 'indexAircraft']);
        Route::get('/aircraft', [OperadorControlador::class, 'indexAircraft']);
        Route::get('/aircraft/{aircraft}', [OperadorControlador::class, 'showAircraft']);
        Route::put('/aircraft/{aircraft}', [OperadorControlador::class, 'updateAircraft']);
        Route::post('/aircraft/{aircraft}/subscribe', [OperadorControlador::class, 'subscribeAircraft']);
        Route::get('/aircraft/{aircraft}/images', [AeronaveControlador::class, 'images']);
        Route::post('/aircraft/{aircraft}/images', [AeronaveControlador::class, 'storeImage']);
        Route::post('/aircraft/{aircraft}/images/attach-existing', [AeronaveControlador::class, 'attachExistingImage']);
        Route::delete('/aircraft/{aircraft}/images/{image}', [AeronaveControlador::class, 'destroyImage']);
        Route::post('/availability', [OperadorControlador::class, 'storeAvailability']);
        Route::get('/my-requests', [OperadorControlador::class, 'requests']);
        Route::get('/requests', [OperadorControlador::class, 'requests']);
        Route::post('/requests/{flightRequest}/accept', [OperadorControlador::class, 'accept']);
        Route::post('/requests/{flightRequest}/reject', [OperadorControlador::class, 'reject']);
    });

    Route::prefix('provider')->middleware(['role:provider,admin'])->group(function () {
        Route::post('/aircraft/{aircraft}/billing/create', [ProviderAircraftBillingControlador::class, 'create']);
        Route::get('/aircraft/{aircraft}/billing-status', [ProviderAircraftBillingControlador::class, 'status']);
        Route::get('/aircraft-billing/payments', [ProviderAircraftBillingControlador::class, 'payments']);
    });

    Route::prefix('sobrecargo')->middleware(['role:sobrecargo,admin'])->group(function () {
        Route::get('/dashboard', [SobrecargoControlador::class, 'dashboard']);
        Route::get('/profile', [SobrecargoControlador::class, 'profile']);
        Route::put('/profile', [SobrecargoControlador::class, 'updateProfile']);
        Route::get('/assignments', [SobrecargoControlador::class, 'assignments']);
        Route::post('/assignments/{operation}/respond', [SobrecargoControlador::class, 'respondAssignment']);
        Route::get('/operations/{operation}', [SobrecargoControlador::class, 'operation']);
        Route::post('/operations/{operation}/respond', [SobrecargoControlador::class, 'respondAssignment']);
        Route::post('/operations/{operation}/checkin', [SobrecargoControlador::class, 'checkinOperation']);
        Route::post('/operations/{operation}/cabin-ready', [SobrecargoControlador::class, 'markCabinReady']);
        Route::post('/operations/{operation}/passengers-ready', [SobrecargoControlador::class, 'markPassengersReady']);
        Route::post('/operations/{operation}/start-service', [SobrecargoControlador::class, 'startService']);
        Route::post('/operations/{operation}/complete-service', [SobrecargoControlador::class, 'completeService']);
        Route::post('/operations/{operation}/incident', [SobrecargoControlador::class, 'storeOperationIncident']);
        Route::post('/checklists/{checklist}/complete', [SobrecargoControlador::class, 'completeChecklist']);
        Route::get('/documents', [SobrecargoControlador::class, 'documents']);
        Route::post('/documents', [SobrecargoControlador::class, 'storeDocument']);
        Route::put('/documents/{documentId}', [SobrecargoControlador::class, 'updateDocument']);
        Route::get('/availability/statuses', [SobrecargoControlador::class, 'availabilityStatuses']);
        Route::get('/availability', [SobrecargoControlador::class, 'availability']);
        Route::get('/availability/{availabilityId}/bitacora', [SobrecargoControlador::class, 'availabilityLog']);
        Route::post('/availability', [SobrecargoControlador::class, 'storeAvailability']);
        Route::delete('/availability/{availabilityId}', [SobrecargoControlador::class, 'destroyAvailability']);
        Route::get('/incidents', [SobrecargoControlador::class, 'listIncidents']);
        Route::post('/incidents', [SobrecargoControlador::class, 'incidents']);
        Route::put('/incidents/{timeline}', [SobrecargoControlador::class, 'updateIncident']);
    });

    Route::prefix('admin')->middleware(['role:admin'])->group(function () {
        Route::put('/billing/plans/{plan}', [BillingPlanControlador::class, 'update']);
        Route::get('/dashboard', [AdminControlador::class, 'dashboard']);
        Route::get('/users', [AdminControlador::class, 'users']);
        Route::get('/users/{user}', [AdminControlador::class, 'showUser']);
        Route::post('/users', [AdminControlador::class, 'storeUser']);
        Route::get('/roles', [AdminControlador::class, 'roles']);
        Route::put('/users/{user}', [AdminControlador::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminControlador::class, 'destroyUser']);
        Route::post('/users/{user}/anonymize', [AdminControlador::class, 'anonymizeUser']);
        Route::post('/users/{user}/block', [AdminControlador::class, 'blockUser']);
        Route::post('/users/{user}/activate', [AdminControlador::class, 'activateUser']);
        Route::post('/users/{user}/grant-trial', [AdminControlador::class, 'grantUserTrial']);
        Route::post('/users/{user}/revoke-commercial-access', [AdminControlador::class, 'revokeCommercialAccess']);
        Route::post('/users/{user}/reset-password', [AdminControlador::class, 'resetUserPassword']);
        Route::post('/client-access-payments/reconcile-pending', [AdminControlador::class, 'reconcilePendingClientAccessPayments']);
        Route::get('/operators', [AdminControlador::class, 'operators']);
        Route::get('/sobrecargos', [AdminControlador::class, 'sobrecargos']);
        Route::get('/crew', [AdminControlador::class, 'sobrecargos']);
        Route::put('/sobrecargos/{user}', [AdminControlador::class, 'updateSobrecargo']);
        Route::put('/crew/{user}', [AdminControlador::class, 'updateSobrecargo']);
        Route::get('/sobrecargos/disponibilidad', [AdminControlador::class, 'crewAvailability']);
        Route::post('/sobrecargos/disponibilidad', [AdminControlador::class, 'storeCrewAvailability']);
        Route::get('/sobrecargos/disponibilidad/{availability}/bitacora', [AdminControlador::class, 'crewAvailabilityLog']);
        Route::post('/sobrecargos/disponibilidad/{availability}/aprobar-bloqueo', [AdminControlador::class, 'approveCrewAvailabilityBlock']);
        Route::post('/sobrecargos/disponibilidad/{availability}/rechazar-bloqueo', [AdminControlador::class, 'rejectCrewAvailabilityBlock']);
        Route::get('/requests', [AdminControlador::class, 'requests']);
        Route::get('/releases', [AdminControlador::class, 'releases']);
        Route::get('/contracts', [AdminControlador::class, 'contracts']);
        Route::post('/requests/{flightRequest}/assign', [AdminControlador::class, 'assign']);
        Route::put('/requests/{flightRequest}/workflow', [AdminControlador::class, 'updateRequestWorkflow']);
        Route::get('/subscriptions', [AdminControlador::class, 'subscriptions']);
        Route::get('/client-access-payments', [AdminControlador::class, 'clientAccessPayments']);
        Route::get('/subscription-payments', [AdminControlador::class, 'subscriptionPayments']);
        Route::get('/fleet/aircraft', [AdminControlador::class, 'aircraftFleet']);
        Route::get('/fleet/aircraft-subscriptions', [AdminControlador::class, 'aircraftSubscriptionsPerFleet']);
        Route::get('/kpis', [AdminControlador::class, 'kpis']);
        Route::get('/anti-broker-flags', [AdminControlador::class, 'antiBrokerFlags']);
        Route::get('/data-transfer/schema', [AdminControlador::class, 'dataTransferSchema']);
        Route::post('/data-transfer/import', [AdminControlador::class, 'importDataTransfer']);
        Route::get('/data-transfer/export', [AdminControlador::class, 'exportDataTransfer']);
    });

    Route::get('/chats/{chat}', [ChatControlador::class, 'show']);
    Route::post('/chats/{chat}/messages', [ChatControlador::class, 'storeMessage'])->middleware('anti_broker.filter');

    Route::get('/notifications', [NotificacionControlador::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificacionControlador::class, 'markAsRead']);
});
