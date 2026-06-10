<?php

namespace App\Http\Controladores;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrewOperationIncidentController extends ControladorBase
{
    private const STATUSES = ['open', 'in_review', 'resolved', 'closed'];
    private const CATEGORIES = ['catering', 'cabina', 'cliente', 'seguridad', 'horario', 'coordinacion', 'otro'];
    private const PRIORITIES = ['baja', 'media', 'alta', 'critica'];

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DB::table('crew_operation_incidents')
            ->when($request->filled('crew_operation_id'), fn ($builder) => $builder->where('crew_operation_id', $request->integer('crew_operation_id')))
            ->when($request->filled('crew_id'), fn ($builder) => $builder->where('crew_id', $request->integer('crew_id')))
            ->latest('reported_at')
            ->latest('id');

        if ($user && $user->role === 'sobrecargo') {
            $query->where('crew_id', $user->id);
        }

        $incidents = $query->get()->map(fn ($incident) => $this->withFiles($incident))->values();

        return $this->ok(['incidents' => $incidents]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'crew_operation_id' => ['required', 'integer', 'exists:operations,id'],
            'crew_id' => ['required', 'integer', 'exists:users,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'description' => ['required', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ]);

        $user = $request->user();
        if ($user && $user->role === 'sobrecargo') {
            abort_if((int) $data['crew_id'] !== (int) $user->id, 403);
        }

        $incidentId = DB::table('crew_operation_incidents')->insertGetId([
            'crew_operation_id' => $data['crew_operation_id'],
            'crew_id' => $data['crew_id'],
            'reported_by' => $user?->id,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'status' => 'open',
            'description' => $data['description'],
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($request->file('files', []) as $file) {
            DB::table('crew_operation_incident_files')->insert([
                'incident_id' => $incidentId,
                'file_path' => $file->store('crew-operation-incidents', 'public'),
                'file_type' => $file->getClientMimeType(),
                'original_name' => $file->getClientOriginalName(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $incident = DB::table('crew_operation_incidents')->where('id', $incidentId)->first();

        return $this->ok(['incident' => $this->withFiles($incident)], 201);
    }

    public function show(Request $request, int $id)
    {
        $incident = DB::table('crew_operation_incidents')->where('id', $id)->first();
        abort_if(! $incident, 404);

        $user = $request->user();
        if ($user && $user->role === 'sobrecargo') {
            abort_if((int) $incident->crew_id !== (int) $user->id, 403);
        }

        return $this->ok(['incident' => $this->withFiles($incident)]);
    }

    public function update(Request $request, int $id)
    {
        abort_if($request->user()?->role !== 'admin', 403);

        $incident = DB::table('crew_operation_incidents')->where('id', $id)->first();
        abort_if(! $incident, 404);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'admin_response' => ['nullable', 'string'],
        ]);

        $status = $data['status'] ?? $incident->status;
        $patch = [
            'status' => $status,
            'admin_response' => array_key_exists('admin_response', $data) ? $data['admin_response'] : $incident->admin_response,
            'updated_at' => now(),
        ];

        if ($status === 'resolved' && ! $incident->resolved_at) {
            $patch['resolved_at'] = now();
        }

        if ($status === 'closed' && ! $incident->closed_at) {
            $patch['closed_at'] = now();
            $patch['resolved_at'] = $incident->resolved_at ?: now();
        }

        DB::table('crew_operation_incidents')->where('id', $id)->update($patch);

        $updated = DB::table('crew_operation_incidents')->where('id', $id)->first();

        return $this->ok(['incident' => $this->withFiles($updated)]);
    }

    private function withFiles(object $incident): array
    {
        $operation = DB::table('operations')
            ->leftJoin('flight_requests', 'flight_requests.id', '=', 'operations.flight_request_id')
            ->where('operations.id', $incident->crew_operation_id)
            ->select([
                'operations.id',
                'flight_requests.origin',
                'flight_requests.destination',
                'flight_requests.departure_datetime',
            ])
            ->first();
        $crew = DB::table('users')
            ->where('id', $incident->crew_id)
            ->select(['id', 'name', 'email'])
            ->first();
        $route = trim(implode(' - ', array_filter([
            $operation?->origin,
            $operation?->destination,
        ])));
        $files = DB::table('crew_operation_incident_files')
            ->where('incident_id', $incident->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($file) => [
                'id' => $file->id,
                'file_path' => $file->file_path,
                'file_type' => $file->file_type,
                'original_name' => $file->original_name,
                'created_at' => $file->created_at,
                'updated_at' => $file->updated_at,
            ])
            ->values();

        return [
            'id' => $incident->id,
            'crew_operation_id' => $incident->crew_operation_id,
            'operation_route' => $route ?: 'Ruta por definir',
            'operation_departure_datetime' => $operation?->departure_datetime,
            'crew_id' => $incident->crew_id,
            'crew_name' => $crew?->name ?: 'Sobrecargo por definir',
            'crew_email' => $crew?->email,
            'reported_by' => $incident->reported_by,
            'category' => $incident->category,
            'priority' => $incident->priority,
            'status' => $incident->status,
            'description' => $incident->description,
            'admin_response' => $incident->admin_response,
            'reported_at' => $incident->reported_at,
            'resolved_at' => $incident->resolved_at,
            'closed_at' => $incident->closed_at,
            'created_at' => $incident->created_at,
            'updated_at' => $incident->updated_at,
            'files' => $files,
        ];
    }
}
