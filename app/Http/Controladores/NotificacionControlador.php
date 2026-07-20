<?php

namespace App\Http\Controladores;

use App\Modelos\Notificacion;
use Illuminate\Http\Request;

class NotificacionControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        return $this->ok([
            'notifications' => Notificacion::where('user_id', $request->user()->id)
                ->latest()
                ->paginate($perPage),
            'unread_count' => Notificacion::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return $this->ok([
            'unread_count' => Notificacion::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(Request $request, Notificacion $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return $this->ok(['notification' => $notification->fresh()]);
    }

    public function markAllAsRead(Request $request)
    {
        $updated = Notificacion::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->ok(['updated' => $updated, 'unread_count' => 0]);
    }
}
