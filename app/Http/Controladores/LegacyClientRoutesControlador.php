<?php
namespace App\Http\Controladores;
class LegacyClientRoutesControlador extends ControladorBase
{
    public function retired()
    {
        return response()->json(['success' => false, 'code' => 'LEGACY_CLIENT_ROUTE_RETIRED',
            'message' => 'Ruta retirada. Usa /api/v1/client/flight-requests para solicitudes.'], 410);
    }
}
