<?php

namespace App\Http\Controladores;

use App\Modelos\DispositivoUsuario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DispositivoUsuarioControlador extends ControladorBase
{
    public function store(Request $request)
    {
        $data = $this->validatePayload($request, true);
        $device = DispositivoUsuario::query()->updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            $data + ['user_id' => $request->user()->id, 'last_seen_at' => now()],
        );

        return $this->ok(['device' => $device], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $deviceUuid)
    {
        $device = DispositivoUsuario::query()
            ->where('user_id', $request->user()->id)
            ->where('device_uuid', $deviceUuid)
            ->firstOrFail();
        $device->update($this->validatePayload($request, false) + ['last_seen_at' => now()]);

        return $this->ok(['device' => $device->fresh()]);
    }

    public function destroy(Request $request, string $deviceUuid)
    {
        DispositivoUsuario::query()
            ->where('user_id', $request->user()->id)
            ->where('device_uuid', $deviceUuid)
            ->delete();

        return $this->ok(['message' => 'Dispositivo revocado.']);
    }

    private function validatePayload(Request $request, bool $requireUuid): array
    {
        return $request->validate([
            'device_uuid' => [$requireUuid ? 'required' : 'prohibited', 'string', 'max:128'],
            'push_token' => ['nullable', 'string', 'max:4096'],
            'platform' => [$requireUuid ? 'required' : 'sometimes', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
