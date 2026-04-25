<?php

namespace App\Http\Controladores;

use App\Modelos\Demo;
use Illuminate\Http\Request;

class DemoControlador extends ControladorBase
{
    public function activate(Request $request)
    {
        $user = $request->user();

        if ($user->demo()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'La demo solo puede activarse una vez por usuario.',
                'access' => $user->accessStatus(),
            ], 409);
        }

        $demo = Demo::create([
            'user_id' => $user->id,
            'started_at' => now(),
            'expires_at' => now()->addDays(15),
            'status' => 'active',
        ]);

        $this->writeAudit($request, 'activate', 'demo', 'Demo gratuita de 15 dias activada.');

        return $this->ok(['demo' => $demo, 'access' => $user->fresh(['demo'])->accessStatus()], 201);
    }
}
