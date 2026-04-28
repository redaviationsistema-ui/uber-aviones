<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\ChatProtegido;
use App\Modelos\MensajeChat;
use App\Servicios\RedAviation\AntiBrokerServicio;
use Illuminate\Http\Request;

class ChatControlador extends ControladorBase
{
    public function __construct(private readonly AntiBrokerServicio $antiBrokerServicio)
    {
    }

    public function show(Request $request, ChatProtegido $chat)
    {
        abort_unless($this->puedeVerChat($request->user(), $chat), 403);

        return $this->ok([
            'chat' => $chat->load('mensajes'),
        ]);
    }

    public function storeMessage(Request $request, ChatProtegido $chat)
    {
        abort_unless($this->puedeVerChat($request->user(), $chat), 403);

        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $revision = $request->attributes->get('anti_broker_revision')
            ?? $this->antiBrokerServicio->inspeccionar($data['message']);

        $mensaje = MensajeChat::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'message' => $data['message'],
            'sanitized_message' => $revision['sanitized'],
            'has_blocked_content' => $revision['has_blocked_content'],
            'blocked_reason' => $revision['has_blocked_content'] ? 'contacto_externo' : null,
        ]);

        $this->antiBrokerServicio->registrarIncidencias(
            $request->user(),
            $chat->flight_request_id,
            $mensaje,
            $revision
        );

        return $this->ok(['message' => $mensaje], 201);
    }

    private function puedeVerChat($usuario, ChatProtegido $chat): bool
    {
        $providerUserId = $chat->provider?->user_id ?? null;
        $userId = $usuario->id;

        if ($usuario->isRole('admin')) {
            return true;
        }

        return in_array($userId, array_filter([
            $chat->client_id,
            $providerUserId,
            $chat->admin_id,
        ]), true);
    }
}
