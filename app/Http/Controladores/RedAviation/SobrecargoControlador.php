<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Http\Request;

class SobrecargoControlador extends ControladorBase
{
    public function __construct(private readonly VisibilidadServicio $visibilidadServicio)
    {
    }

    public function dashboard(Request $request)
    {
        return $this->ok([
            'metrics' => [
                'asignaciones' => Operacion::where('sobrecargo_user_id', $request->user()->id)->count(),
                'servicios_activos' => Operacion::where('sobrecargo_user_id', $request->user()->id)
                    ->where('status', '!=', 'finalizada')
                    ->count(),
            ],
        ]);
    }

    public function assignments(Request $request)
    {
        return $this->ok([
            'assignments' => Operacion::where('sobrecargo_user_id', $request->user()->id)->latest()->get(),
        ]);
    }

    public function operation(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        return $this->ok([
            'operation' => $this->visibilidadServicio->operacionParaSobrecargo($operation),
        ]);
    }

    public function startService(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $operation->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => 'servicio_iniciado',
            'title' => 'Sobrecargo inicia servicio',
            'description' => 'La atencion al cliente comenzo en el dia del vuelo.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'operation' => $operation->fresh(),
            'timeline_item' => $timeline,
        ]);
    }

    public function completeService(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $operation->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => 'servicio_finalizado',
            'title' => 'Vuelo finalizado',
            'description' => 'El sobrecargo cerro la atencion y dejo el servicio completado.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'operation' => $operation->fresh(),
            'timeline_item' => $timeline,
        ]);
    }

    public function completeChecklist(Request $request, ChecklistOperacion $checklist)
    {
        abort_if($checklist->sobrecargo_user_id !== $request->user()->id, 403);

        ChecklistItem::where('checklist_id', $checklist->id)->update([
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        $checklist->update(['status' => 'completado']);

        return $this->ok(['checklist' => $checklist->load('items')]);
    }

    public function incidents(Request $request)
    {
        $data = $request->validate([
            'operation_id' => ['required', 'exists:operations,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
        ]);

        $operacion = Operacion::findOrFail($data['operation_id']);
        abort_if($operacion->sobrecargo_user_id !== $request->user()->id, 403);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operacion->id,
            'status' => 'incidencia',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $operacion->update(['status' => 'incidencia']);

        return $this->ok(['incident' => $timeline], 201);
    }
}
