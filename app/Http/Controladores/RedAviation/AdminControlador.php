<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\BanderaAntiBroker;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\KpiSaasServicio;
use Illuminate\Http\Request;

class AdminControlador extends ControladorBase
{
    public function __construct(private readonly KpiSaasServicio $kpiSaasServicio)
    {
    }

    public function dashboard()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function users()
    {
        return $this->ok(['users' => Usuario::latest()->paginate(20)]);
    }

    public function operators()
    {
        return $this->ok(['operators' => Proveedor::with('user')->latest()->paginate(20)]);
    }

    public function sobrecargos()
    {
        return $this->ok(['sobrecargos' => Usuario::where('operational_role', 'sobrecargo')->latest()->paginate(20)]);
    }

    public function requests()
    {
        return $this->ok(['requests' => SolicitudVuelo::with(['client', 'matches.aircraft'])->latest()->paginate(20)]);
    }

    public function assign(Request $request, SolicitudVuelo $flightRequest)
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'sobrecargo_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $operacion = Operacion::updateOrCreate(
            ['flight_request_id' => $flightRequest->id],
            [
                'provider_id' => $data['provider_id'],
                'aircraft_id' => $data['aircraft_id'],
                'sobrecargo_user_id' => $data['sobrecargo_user_id'] ?? null,
                'status' => 'operador_asignado',
            ]
        );

        $operacion->timeline()->create([
            'status' => 'operador_asignado',
            'title' => 'Asignacion manual',
            'description' => 'Admin Red Aviation realizo el matching manual.',
            'created_by' => $request->user()->id,
        ]);

        $flightRequest->update(['workflow_status' => 'operador_asignado']);

        return $this->ok(['operation' => $operacion->load('timeline')]);
    }

    public function subscriptions()
    {
        return $this->ok(['subscriptions' => Suscripcion::with(['user', 'plan'])->latest()->paginate(20)]);
    }

    public function kpis()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function antiBrokerFlags()
    {
        return $this->ok(['flags' => BanderaAntiBroker::latest()->paginate(20)]);
    }
}
