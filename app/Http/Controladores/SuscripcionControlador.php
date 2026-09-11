<?php

namespace App\Http\Controladores;

use Illuminate\Http\Request;

class SuscripcionControlador extends ControladorBase
{
    public function status(Request $request)
    {
        return $this->ok(['access' => $request->user()->accessStatus()]);
    }

    public function subscribe(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'LEGACY_SUBSCRIPTION_ACTIVATION_DISABLED',
            'message' => 'La activación requiere un pago verificado. Utiliza el flujo de acceso comercial.',
        ], 410);
    }

    public function cancel(Request $request)
    {
        $request->user()->subscriptions()->where('status', 'active')->update(['status' => 'cancelled']);

        return $this->ok(['message' => 'Suscripcion cancelada.']);
    }
}
