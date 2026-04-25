<?php

namespace App\Http\Controladores;

use App\Modelos\Notificacion;
use Illuminate\Http\Request;

class NotificacionControlador extends ControladorBase
{
    public function index(Request $request)
    {
        return $this->ok([
            'notifications' => Notificacion::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function markAsRead(Request $request, Notificacion $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return $this->ok(['notification' => $notification->fresh()]);
    }
}
