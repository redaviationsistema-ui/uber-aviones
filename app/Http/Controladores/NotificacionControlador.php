<?php

namespace App\Http\Controladores;

use App\Modelos\Notificacion;
use Illuminate\Http\Request;

class NotificacionControlador extends ControladorBase
{
    private function visibleNotifications(Request $request)
    {
        $data = $request->validate(['types' => ['sometimes', 'array'], 'types.*' => ['string', 'max:100']]);
        return Notificacion::visibleTo($request->user())
            ->when(! empty($data['types']), fn ($query) => $query->whereIn('type', $data['types']));
    }

    public function index(Request $request)
    {
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        return $this->ok([
            'notifications' => $this->visibleNotifications($request)
                ->latest()
                ->paginate($perPage),
            'unread_count' => $this->visibleNotifications($request)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return $this->ok([
            'unread_count' => $this->visibleNotifications($request)->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(Request $request, Notificacion $notification)
    {
        abort_unless(Notificacion::visibleTo($request->user())->whereKey($notification->id)->exists(), 403);
        $notification->update(['read_at' => $notification->read_at ?: now()]);

        return $this->ok(['notification' => $notification->fresh()]);
    }

    public function markAllAsRead(Request $request)
    {
        $updated = $this->visibleNotifications($request)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->ok(['updated' => $updated, 'unread_count' => 0]);
    }
}
