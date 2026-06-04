<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\BanderaAntiBroker;
use App\Modelos\ContratoReserva;
use App\Modelos\Demo;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\Rol;
use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\SuscripcionAeronave;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\KpiSaasServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminControlador extends ControladorBase
{
    public function __construct(
        private readonly KpiSaasServicio $kpiSaasServicio,
        private readonly VisibilidadServicio $visibilidadServicio,
    )
    {
    }

    public function dashboard()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function users()
    {
        return $this->ok([
            'users' => Usuario::with(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function showUser(Usuario $user)
    {
        return $this->ok([
            'user' => $user->load([
                'roles',
                'profile',
                'demo',
                'activeSuscripcion.plan',
                'identityVerifications' => fn ($query) => $query->latest(),
                'provider.user',
                'provider.aircraft',
                'provider.companyDocuments',
            ]),
        ]);
    }

    public function roles()
    {
        return $this->ok([
            'roles' => Rol::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in([
                Usuario::ROLE_CLIENT,
                Usuario::ROLE_PROVIDER,
                Usuario::ROLE_ADMIN,
                Usuario::ROLE_SOBRECARGO,
            ])],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        $plainPassword = $data['password'] ?? Str::password(12);
        $user = Usuario::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($plainPassword),
            'phone' => $data['phone'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->syncUserRoles($user, $data['role']);
        $this->ensureProviderRecord($user, $data);
        $this->writeAudit($request, 'admin_user_created', 'admin_users', sprintf(
            'Admin creo al usuario %s con rol %s.',
            $user->email,
            $data['role']
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
            'temporary_password' => $plainPassword,
        ], 201);
    }

    public function updateUser(Request $request, Usuario $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', Rule::in([
                Usuario::ROLE_CLIENT,
                Usuario::ROLE_PROVIDER,
                Usuario::ROLE_ADMIN,
                Usuario::ROLE_SOBRECARGO,
            ])],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        $user->update(collect($data)->except('role')->all());

        if (isset($data['role'])) {
            $this->syncUserRoles($user, $data['role']);
            $this->ensureProviderRecord($user, $data);
        } elseif (array_key_exists('provider_id', $data)) {
            $user->forceFill(['provider_id' => $data['provider_id']])->save();
        }

        $this->writeAudit($request, 'admin_user_updated', 'admin_users', sprintf(
            'Admin actualizo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ]);
    }

    public function destroyUser(Request $request, Usuario $user)
    {
        if ($request->user()?->is($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar tu propio usuario administrador.',
            ], 422);
        }

        $email = $user->email;
        $user->delete();

        $this->writeAudit($request, 'admin_user_deleted', 'admin_users', sprintf(
            'Admin elimino al usuario %s.',
            $email
        ));

        return $this->ok([
            'message' => 'Usuario eliminado correctamente.',
        ]);
    }

    public function blockUser(Request $request, Usuario $user)
    {
        $user->update(['status' => 'blocked']);

        $this->writeAudit($request, 'admin_user_blocked', 'admin_users', sprintf(
            'Admin bloqueo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ]);
    }

    public function activateUser(Request $request, Usuario $user)
    {
        $user->update(['status' => 'active']);

        $this->writeAudit($request, 'admin_user_activated', 'admin_users', sprintf(
            'Admin activo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ]);
    }

    public function grantUserTrial(Request $request, Usuario $user)
    {
        if (! $user->hasRole(Usuario::ROLE_CLIENT)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo los usuarios cliente pueden recibir demo comercial.',
            ], 422);
        }

        $demo = Demo::updateOrCreate(
            ['user_id' => $user->id],
            [
                'started_at' => now(),
                'expires_at' => now()->addDays(15),
                'status' => 'active',
            ]
        );

        $this->writeAudit($request, 'admin_user_trial_granted', 'admin_users', sprintf(
            'Admin activo demo comercial para el usuario %s hasta %s.',
            $user->email,
            optional($demo->expires_at)?->toDateTimeString() ?? 'sin fecha'
        ));

        return $this->ok([
            'message' => 'Demo comercial activada correctamente.',
            'demo' => $demo,
            'access' => $user->fresh(['demo', 'activeSuscripcion.plan'])->accessStatus(),
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ], 201);
    }

    public function revokeCommercialAccess(Request $request, Usuario $user)
    {
        if (! $user->hasRole(Usuario::ROLE_CLIENT)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo los usuarios cliente pueden modificar acceso comercial.',
            ], 422);
        }

        $user->demo()->update([
            'status' => 'inactive',
            'expires_at' => now(),
        ]);

        $user->subscriptions()
            ->where('status', 'active')
            ->update([
                'status' => 'cancelled',
                'expires_at' => now(),
            ]);

        $this->writeAudit($request, 'admin_user_commercial_access_revoked', 'admin_users', sprintf(
            'Admin desactivo el acceso comercial para el usuario %s.',
            $user->email
        ));

        return $this->ok([
            'message' => 'Acceso comercial desactivado correctamente.',
            'access' => $user->fresh(['demo', 'activeSuscripcion.plan'])->accessStatus(),
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ]);
    }

    public function resetUserPassword(Request $request, Usuario $user)
    {
        $plainPassword = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();

        $this->writeAudit($request, 'admin_user_password_reset', 'admin_users', sprintf(
            'Admin reinicio la contrasena del usuario %s.',
            $user->email
        ));

        return $this->ok([
            'message' => 'Contrasena reiniciada correctamente.',
            'temporary_password' => $plainPassword,
            'user' => $user->fresh(['roles', 'profile', 'provider', 'demo', 'activeSuscripcion.plan']),
        ]);
    }

    public function operators()
    {
        return $this->ok(['operators' => Proveedor::with('user')->latest()->paginate(20)]);
    }

    public function sobrecargos()
    {
        return $this->ok([
            'sobrecargos' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
                ->with(['profile', 'provider', 'ownedProvider', 'roles'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function updateSobrecargo(Request $request, Usuario $user)
    {
        if (! $user->hasRole(Usuario::ROLE_SOBRECARGO) && $user->operational_role !== Usuario::ROLE_SOBRECARGO) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario seleccionado no corresponde a un sobrecargo.',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'base' => ['nullable', 'string', 'max:100'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:100'],
            'profile_state' => ['nullable', 'string', 'max:100'],
            'validation_status' => ['nullable', 'string', 'max:100'],
            'admin_notes' => ['nullable', 'string'],
        ]);

        $profile = $user->profile ?: $user->profile()->make(['user_id' => $user->id]);
        $taxData = $profile->tax_data ?? [];

        $nextProfileState = $data['profile_state'] ?? $data['validation_status'] ?? ($taxData['profile_state'] ?? 'Pendiente');
        $normalizedUserStatus = $this->normalizeSobrecargoUserStatus(
            $data['status'] ?? null,
            $nextProfileState,
            $user->status
        );

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
            'status' => $normalizedUserStatus,
            'operational_role' => $user->operational_role ?: Usuario::ROLE_SOBRECARGO,
        ])->save();

        $profile->fill([
            'city' => array_key_exists('base', $data) ? $data['base'] : $profile->city,
            'base_airport' => array_key_exists('base_airport', $data) || array_key_exists('base', $data)
                ? ($data['base_airport'] ?? $data['base'])
                : $profile->base_airport,
            'tax_data' => array_merge($taxData, [
                'profile_state' => $nextProfileState,
                'validation_status' => $data['validation_status'] ?? ($taxData['validation_status'] ?? $nextProfileState),
                'current_status' => match ($normalizedUserStatus) {
                    'active' => 'active',
                    'blocked' => 'suspended',
                    'inactive' => 'inactive',
                    default => $taxData['current_status'] ?? null,
                },
                'admin_notes' => array_key_exists('admin_notes', $data)
                    ? $data['admin_notes']
                    : ($taxData['admin_notes'] ?? null),
            ]),
        ]);
        $profile->save();

        $this->writeAudit($request, 'admin_crew_updated', 'admin_sobrecargos', sprintf(
            'Admin actualizo el expediente operativo del sobrecargo %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider']),
            'profile' => $profile->fresh(),
        ]);
    }

    public function requests(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $requestsPaginator = SolicitudVuelo::query()
            ->select([
                'id',
                'client_id',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'origin',
                'destination',
                'departure_datetime',
                'passengers',
                'trip_type',
                'aircraft_type',
                'requirements',
                'visibility_payload',
                'base_price',
                'operational_fee',
                'priority_price',
                'final_price',
                'currency',
                'pricing_context',
                'payment_status',
                'status',
                'workflow_status',
            ])
            ->with([
                'client:id,name',
                'matches' => fn ($query) => $query->select([
                    'id',
                    'flight_request_id',
                    'aircraft_id',
                    'provider_id',
                    'estimated_price',
                    'status',
                    'visibility_payload',
                ]),
                'matches.aircraft:id,model,category,capacity',
                'assignedAircraft:id,model,category,capacity',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.status',
                ]),
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
            ])
            ->latest()
            ->paginate($perPage);

        $requests = $requestsPaginator->getCollection()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaAdmin($solicitud))
            ->values();

        $requestsPaginator->setCollection($requests);

        return $this->ok([
            'requests' => $requests,
            'pagination' => [
                'current_page' => $requestsPaginator->currentPage(),
                'last_page' => $requestsPaginator->lastPage(),
                'per_page' => $requestsPaginator->perPage(),
                'total' => $requestsPaginator->total(),
                'has_more_pages' => $requestsPaginator->hasMorePages(),
            ],
        ]);
    }

    private function normalizeSobrecargoUserStatus(?string $requestedStatus, ?string $profileState, string $fallback): string
    {
        $normalizedStatus = Str::lower(trim((string) $requestedStatus));
        $normalizedProfileState = Str::lower(trim((string) $profileState));

        if (in_array($normalizedStatus, ['active', 'inactive', 'blocked'], true)) {
            return $normalizedStatus;
        }

        if (in_array($normalizedStatus, ['suspendido', 'suspended', 'blocked'], true)
            || in_array($normalizedProfileState, ['suspendido', 'suspended', 'blocked'], true)) {
            return 'blocked';
        }

        if (in_array($normalizedProfileState, ['aprobado', 'approved', 'activo', 'active'], true)) {
            return 'active';
        }

        return $fallback;
    }

    public function releases()
    {
        $releases = SolicitudVuelo::query()
            ->select([
                'id',
                'client_id',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'origin',
                'destination',
                'departure_datetime',
                'passengers',
                'trip_type',
                'aircraft_type',
                'visibility_payload',
                'requirements',
                'base_price',
                'operational_fee',
                'priority_price',
                'final_price',
                'currency',
                'pricing_context',
                'payment_status',
                'status',
                'workflow_status',
            ])
            ->with([
                'client:id,name',
                'assignedAircraft:id,model,category,capacity',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.status',
                ]),
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
            ])
            ->latest()
            ->limit(120)
            ->get()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaAdmin($solicitud))
            ->filter(function ($solicitud) {
                return ! empty($solicitud['provider_operational_release'])
                    || ! empty($solicitud['operational_status'])
                    || ! empty($solicitud['aircraft_confirmed'])
                    || ! empty($solicitud['crew_confirmed'])
                    || ! empty($solicitud['operational_ready']);
            })
            ->values();

        return $this->ok([
            'requests' => $releases,
            'releases' => $releases,
        ]);
    }

    public function contracts()
    {
        return $this->ok([
            'contracts' => ContratoReserva::query()
                ->with([
                    'signedBy:id,name,email',
                    'reservation:id,client_id,provider_id,aircraft_id,reservation_code,status,total_amount,currency,confirmed_at',
                    'reservation.client:id,name,email',
                    'reservation.provider:id,company_name,commercial_name',
                    'reservation.aircraft:id,model,registration',
                ])
                ->latest('generated_at')
                ->latest('id')
                ->paginate(20),
        ]);
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

        $aircraft = Aeronave::find($data['aircraft_id']);
        $visibilityPayload = $flightRequest->visibility_payload ?? [];

        $flightRequest->update([
            'workflow_status' => 'operador_asignado',
            'assigned_provider_id' => $data['provider_id'],
            'assigned_aircraft_id' => $data['aircraft_id'],
            'assigned_aircraft_model' => $aircraft?->model,
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => $data['provider_id'],
                'selected_aircraft_id' => $data['aircraft_id'],
                'aircraft_model' => $aircraft?->model,
                'aircraft_category' => $aircraft?->category,
                'aircraft_capacity' => $aircraft?->capacity,
            ],
        ]);

        return $this->ok(['operation' => $operacion->load('timeline')]);
    }

    public function updateRequestWorkflow(Request $request, SolicitudVuelo $flightRequest)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:120'],
            'workflow_status' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['nullable', 'string', 'max:120'],
            'contract_status' => ['nullable', 'string', 'max:120'],
            'admin_flow_state' => ['nullable', Rule::in(['active', 'delayed', 'blocked'])],
            'flow_control_state' => ['nullable', Rule::in(['active', 'delayed', 'blocked'])],
            'admin_delay_reason' => ['nullable', 'string', 'max:1000'],
            'delay_reason' => ['nullable', 'string', 'max:1000'],
            'hold_reason' => ['nullable', 'string', 'max:1000'],
            'admin_delay_eta' => ['nullable', 'string', 'max:120'],
            'delay_eta' => ['nullable', 'string', 'max:120'],
            'hold_eta' => ['nullable', 'string', 'max:120'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $result = DB::transaction(function () use ($request, $flightRequest, $data) {
            $visibilityPayload = is_array($flightRequest->visibility_payload) ? $flightRequest->visibility_payload : [];
            $adminFlowState = $data['admin_flow_state'] ?? $data['flow_control_state'] ?? 'active';
            $adminReason = $data['admin_delay_reason'] ?? $data['delay_reason'] ?? $data['hold_reason'] ?? '';
            $adminEta = $data['admin_delay_eta'] ?? $data['delay_eta'] ?? $data['hold_eta'] ?? '';
            $adminNote = $data['admin_note'] ?? '';
            $contractStatus = null;
            $paymentStatus = $data['payment_status'] ?? $flightRequest->payment_status;
            $normalizedWorkflowStatus = Str::lower(trim((string) ($data['workflow_status'] ?? $flightRequest->workflow_status ?? '')));
            $nextRequestStatus = $this->resolveFlightRequestStatusForWorkflow(
                $data['status'] ?? null,
                $normalizedWorkflowStatus,
                $flightRequest->status
            );

            $flightRequest->fill([
                'status' => $nextRequestStatus,
                'workflow_status' => $data['workflow_status'] ?? $flightRequest->workflow_status,
                'payment_status' => $data['payment_status'] ?? $flightRequest->payment_status,
                'notes' => $data['notes'] ?? $flightRequest->notes,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'admin_flow' => [
                        'state' => $adminFlowState,
                        'reason' => $adminReason,
                        'eta' => $adminEta,
                        'note' => $adminNote,
                        'updated_at' => now()->toIso8601String(),
                        'updated_by' => $request->user()?->id,
                    ],
                ],
            ]);
            $flightRequest->save();

            $reservation = \App\Modelos\Reserva::query()
                ->where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            if ($reservation) {
                $reservationUpdates = [];

                if (! empty($data['workflow_status'])) {
                    $reservationUpdates['status'] = match ($data['workflow_status']) {
                        'cancelada', 'cancelled' => 'cancelled',
                        'pago confirmado', 'payment_confirmed' => 'confirmed',
                        'tracking en vivo', 'tracking_live', 'vuelo confirmado', 'flight_confirmed' => 'confirmed',
                        'finalizada', 'completed' => 'completed',
                        default => $reservation->status,
                    };
                }

                if (! empty($reservationUpdates)) {
                    $reservation->update($reservationUpdates);
                }

                if (! empty($data['contract_status'])) {
                    $contract = $reservation->contract;
                    if ($contract) {
                        $normalizedContractStatus = Str::lower(trim((string) $data['contract_status']));
                        $contract->update([
                            'status' => $normalizedContractStatus === 'signed' ? 'signed' : 'generated',
                            'signed_at' => $normalizedContractStatus === 'signed'
                                ? ($contract->signed_at ?? now())
                                : null,
                        ]);
                        $contractStatus = $contract->status;
                    }
                }

                if (! empty($data['payment_status'])) {
                    $payment = $reservation->payments()->latest('id')->first();
                    if ($payment) {
                        $normalizedPaymentStatus = Str::lower(trim((string) $data['payment_status']));
                        $payment->update([
                            'status' => match ($normalizedPaymentStatus) {
                                'pagado', 'paid' => 'paid',
                                'pendiente', 'pending', 'pendiente de pago' => 'pending',
                                'retenido', 'held' => 'held',
                                'reembolsado', 'refunded' => 'refunded',
                                'fallido', 'failed' => 'failed',
                                default => $payment->status,
                            },
                            'paid_at' => in_array($normalizedPaymentStatus, ['pagado', 'paid'], true)
                                ? ($payment->paid_at ?? now())
                                : $payment->paid_at,
                        ]);
                        $paymentStatus = $payment->status;
                    }
                }

                $contractStatus ??= $reservation->contract?->status;
                $paymentStatus ??= $reservation->latestPayment?->status ?? $reservation->status;
            }

            $operation = Operacion::query()
                ->where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            if ($operation && ! empty($data['workflow_status'])) {
                $operation->timeline()->create([
                    'status' => $data['workflow_status'],
                    'title' => 'Actualizacion manual del flujo',
                    'description' => $adminNote ?: 'Admin actualizo el flujo de la solicitud.',
                    'created_by' => $request->user()->id,
                ]);
            }

            return [
                'request' => [
                    'id' => $flightRequest->id,
                    'request_id' => $flightRequest->id,
                    'flight_request_id' => $flightRequest->id,
                    'reservation_id' => $reservation?->id,
                    'status' => $flightRequest->status,
                    'workflow_status' => $flightRequest->workflow_status,
                    'payment_status' => $paymentStatus,
                    'contract_status' => $contractStatus,
                    'notes' => $flightRequest->notes,
                    'visibility_payload' => $flightRequest->visibility_payload,
                    'reservation' => $reservation ? [
                        'id' => $reservation->id,
                        'status' => $reservation->status,
                        'contract_status' => $contractStatus,
                        'payment_status' => $paymentStatus,
                    ] : null,
                    'operation' => $operation ? [
                        'id' => $operation->id,
                        'status' => $operation->status,
                    ] : null,
                ],
            ];
        });

        try {
            $this->writeAudit($request, 'admin_request_workflow_updated', 'admin_requests', sprintf(
                'Admin actualizo el flujo de la solicitud %s a %s.',
                $flightRequest->id,
                $data['workflow_status'] ?? $flightRequest->workflow_status
            ));
        } catch (\Throwable) {
            // Audit failures should not block the admin flow update response.
        }

        return $this->ok($result);
    }

    private function resolveFlightRequestStatusForWorkflow(?string $requestedStatus, string $workflowStatus, ?string $currentStatus): string
    {
        $allowedStatuses = ['pending', 'matched', 'quoted', 'reserved', 'cancelled', 'expired'];
        $normalizedRequestedStatus = Str::lower(trim((string) $requestedStatus));

        if (in_array($normalizedRequestedStatus, $allowedStatuses, true)) {
            return $normalizedRequestedStatus;
        }

        return match ($workflowStatus) {
            'cotizada', 'quoted' => 'quoted',
            'cancelada', 'cancelled' => 'cancelled',
            'expirada', 'expired' => 'expired',
            'proveedor aceptado', 'provider accepted', 'provider_accepted', 'aceptada', 'accepted', 'operador asignado', 'operador_asignado' => 'matched',
            'contrato pendiente', 'contract pending', 'contract_pending',
            'contrato firmado', 'contract signed', 'contract_signed',
            'pago pendiente', 'payment pending', 'payment_pending',
            'pago confirmado', 'payment confirmed', 'payment_confirmed',
            'vuelo confirmado', 'flight confirmed', 'flight_confirmed',
            'tracking en vivo', 'tracking live', 'tracking_live',
            'finalizada', 'completed' => 'reserved',
            default => $currentStatus ?: 'pending',
        };
    }

    public function subscriptions()
    {
        return $this->ok(['subscriptions' => Suscripcion::with(['user', 'plan'])->latest()->paginate(20)]);
    }

    public function aircraftFleet()
    {
        return $this->ok([
            'aircraft' => Aeronave::with([
                'provider',
                'documents',
                'images',
                'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
            ])->latest()->paginate(40),
        ]);
    }

    public function aircraftSubscriptionsPerFleet()
    {
        return $this->ok([
            'aircraft_subscriptions' => SuscripcionAeronave::with(['aircraft.provider', 'plan', 'user'])->latest()->paginate(40),
        ]);
    }

    public function kpis()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function antiBrokerFlags()
    {
        return $this->ok(['flags' => BanderaAntiBroker::latest()->paginate(20)]);
    }

    public function dataTransferSchema(Request $request)
    {
        $connection = $this->resolveConnection($request->query('connection'));
        $tables = collect(Schema::connection($connection)->getTableListing())
            ->map(fn (string $table) => [
                'name' => $table,
                'columns' => Schema::connection($connection)->getColumnListing($table),
            ])
            ->values();

        return $this->ok([
            'connection' => $connection,
            'tables' => $tables,
        ]);
    }

    public function importDataTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection' => ['required', 'string'],
            'resource' => ['required', 'string'],
            'mode' => ['required', 'in:append,replace'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $connection = $this->resolveConnection($data['connection']);
        $table = $this->resolveTable($connection, $data['resource']);
        $columns = Schema::connection($connection)->getColumnListing($table);
        $allowedColumns = array_values(array_diff($columns, ['id']));
        $rows = $this->parseSpreadsheetFile($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no contiene filas para importar.',
            ], 422);
        }

        $normalizedRows = collect($rows)
            ->map(fn (array $row) => $this->applyTableAliases($table, $row))
            ->map(function (array $row) use ($allowedColumns) {
                $filtered = [];

                foreach ($allowedColumns as $column) {
                    if (array_key_exists($column, $row)) {
                        $filtered[$column] = $row[$column] === '' ? null : $row[$column];
                    }
                }

                return $filtered;
            })
            ->filter(fn (array $row) => ! empty($row))
            ->values()
            ->all();

        if (empty($normalizedRows)) {
            return response()->json([
                'success' => false,
                'message' => 'Ninguna columna del archivo coincide con la tabla seleccionada.',
            ], 422);
        }

        $missingColumns = $this->detectMissingRequiredColumns($table, $normalizedRows);

        if ($missingColumns !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Faltan columnas obligatorias para importar en la tabla seleccionada.',
                'missing_columns' => $missingColumns,
            ], 422);
        }

        try {
            DB::connection($connection)->transaction(function () use ($connection, $table, $data, $normalizedRows) {
                $query = DB::connection($connection)->table($table);

                if ($data['mode'] === 'replace') {
                    $query->delete();
                }

                foreach (array_chunk($normalizedRows, 500) as $chunk) {
                    $query->insert($chunk);
                }
            });
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'La base de datos rechazo la importacion. Revisa columnas obligatorias, duplicados o tipos de dato.',
                'detail' => $exception->getMessage(),
            ], 422);
        }

        $this->writeAudit($request, 'data_transfer_import', 'admin_data_transfer', sprintf(
            'Importacion a %s.%s con %d filas',
            $connection,
            $table,
            count($normalizedRows)
        ));

        return $this->ok([
            'summary' => [
                'connection' => $connection,
                'table' => $table,
                'inserted_rows' => count($normalizedRows),
                'message' => sprintf('Se importaron %d filas en %s.%s.', count($normalizedRows), $connection, $table),
            ],
        ]);
    }

    public function exportDataTransfer(Request $request): StreamedResponse|JsonResponse|\Illuminate\Http\Response
    {
        $connection = $this->resolveConnection($request->query('connection'));
        $table = $this->resolveTable($connection, (string) $request->query('resource'));
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $columns = Schema::connection($connection)->getColumnListing($table);
        $rows = DB::connection($connection)->table($table)->limit(5000)->get($columns);

        if ($format === 'csv') {
            $filename = sprintf('%s-%s.csv', $table, now()->format('Y-m-d'));

            return response()->streamDownload(function () use ($columns, $rows) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $columns);

                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn (string $column) => $row->{$column}, $columns));
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if ($format === 'xlsx') {
            $sheetRows = [$columns];

            foreach ($rows as $row) {
                $sheetRows[] = array_map(fn (string $column) => $row->{$column}, $columns);
            }

            $binary = (string) SimpleXLSXGen::fromArray($sheetRows, $table);
            $filename = sprintf('%s-%s.xlsx', $table, now()->format('Y-m-d'));

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Formato de exportacion no soportado. Usa csv o xlsx.',
        ], 422);
    }

    private function resolveConnection(?string $connection): string
    {
        $requested = $connection ?: config('database.default');
        $allowed = ['pgsql', 'sqlite', 'sqlite-test'];

        if (! in_array($requested, $allowed, true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'La conexion solicitada no esta permitida para importaciones.',
            ], 422));
        }

        if ($requested === 'sqlite-test') {
            config(['database.connections.sqlite-test' => [
                'driver' => 'sqlite',
                'database' => database_path('test.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }

        return $requested;
    }

    private function resolveTable(string $connection, string $table): string
    {
        $availableTables = Schema::connection($connection)->getTableListing();

        if (! in_array($table, $availableTables, true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'La tabla seleccionada no existe en la conexion indicada.',
            ], 422));
        }

        return $table;
    }

    private function parseSpreadsheetFile(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'csv', 'txt' => $this->parseCsvFile($path),
            'xlsx' => $this->parseXlsxFile($path),
            'xls' => $this->parseXlsFile($path),
            default => [],
        };
    }

    private function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headers = $this->normalizeHeaders($headers);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($row, count($headers), null));
        }

        fclose($handle);

        return $rows;
    }

    private function parseXlsxFile(string $path): array
    {
        $xlsx = SimpleXLSX::parse($path);

        if (! $xlsx) {
            return [];
        }

        return $this->rowsToAssociative($xlsx->rows());
    }

    private function parseXlsFile(string $path): array
    {
        $xls = SimpleXLS::parse($path);

        if (! $xls) {
            return [];
        }

        return $this->rowsToAssociative($xls->rows());
    }

    private function rowsToAssociative(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $headers = $this->normalizeHeaders((array) array_shift($rows));
        $output = [];

        foreach ($rows as $row) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) $row);

            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $output[] = array_combine($headers, array_pad($row, count($headers), null));
        }

        return $output;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = (string) $header;
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $header = strtolower(trim($header));
            $header = preg_replace('/\s+/', '_', $header);

            return $header;
        }, $headers);
    }

    private function applyTableAliases(string $table, array $row): array
    {
        if ($table === 'airports') {
            if (! array_key_exists('icao', $row) && array_key_exists('icao_code', $row)) {
                $row['icao'] = $row['icao_code'];
            }

            if (! array_key_exists('iata', $row) && array_key_exists('iata_code', $row)) {
                $row['iata'] = $row['iata_code'];
            }

            if (! array_key_exists('status', $row) || $row['status'] === '' || $row['status'] === null) {
                $row['status'] = 'active';
            }
        }

        return $row;
    }

    private function detectMissingRequiredColumns(string $table, array $rows): array
    {
        $requiredColumns = match ($table) {
            'airports' => ['icao', 'name'],
            default => [],
        };

        if ($requiredColumns === []) {
            return [];
        }

        $missing = [];

        foreach ($requiredColumns as $column) {
            $hasValue = collect($rows)->contains(function (array $row) use ($column) {
                return array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '';
            });

            if (! $hasValue) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    private function syncUserRoles(Usuario $user, string $selectedRole): void
    {
        $roles = $selectedRole === Usuario::ROLE_SOBRECARGO
            ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
            : [$selectedRole];

        $user->syncRoles($roles, $selectedRole);
    }

    private function ensureProviderRecord(Usuario $user, array $data = []): void
    {
        if (! $user->hasRole(Usuario::ROLE_PROVIDER)) {
            $user->forceFill(['provider_id' => null])->saveQuietly();
            return;
        }

        if (! empty($data['provider_id'])) {
            $provider = Proveedor::findOrFail($data['provider_id']);
            $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

            if (! $provider->user_id) {
                $provider->forceFill(['user_id' => $user->id])->saveQuietly();
            }

            return;
        }

        $provider = $user->ownedProvider()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $user->name,
                'commercial_name' => $user->name,
                'approval_status' => 'pending',
            ]
        );

        if ($user->provider_id !== $provider->id) {
            $user->forceFill(['provider_id' => $provider->id])->saveQuietly();
        }
    }
}
