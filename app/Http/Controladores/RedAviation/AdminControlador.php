<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AccessPayment;
use App\Modelos\BanderaAntiBroker;
use App\Modelos\ContratoReserva;
use App\Modelos\Demo;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\Rol;
use App\Modelos\Aeronave;
use App\Modelos\Pago;
use App\Modelos\CatalogoDisponibilidadEstatus;
use App\Modelos\SolicitudVuelo;
use App\Modelos\SobrecargoDisponibilidad;
use App\Modelos\Suscripcion;
use App\Modelos\SuscripcionAeronave;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\KpiSaasServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Stripe;
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
        $users = Usuario::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'created_at',
                'role',
                'operational_role',
                'provider_id',
                'status',
                'access_status',
                'trial_started_at',
                'trial_ends_at',
                'free_quote_limit',
                'free_quotes_used',
                'has_paid_access',
                'paid_access_at',
                'access_payment_id',
                'access_expires_at',
                'updated_at',
            ])
            ->with([
                'roles:id,code,name',
                'profile:id,user_id,company_name,city,base_airport,tax_data',
                'provider:id,user_id,company_name,commercial_name,approval_status',
                'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                'demo:id,user_id,status,started_at,expires_at',
                'activeSuscripcion' => fn ($query) => $query->select([
                    'subscriptions.id',
                    'subscriptions.user_id',
                    'subscriptions.plan_id',
                    'subscriptions.status',
                    'subscriptions.started_at',
                    'subscriptions.expires_at',
                ]),
                'activeSuscripcion.plan:id,name,code,billing_cycle',
            ])
            ->latest('id')
            ->paginate(20);

        return $this->ok([
            'users' => $users->through(fn (Usuario $user) => $this->serializeAdminUserSummary($user)),
        ]);
    }

    public function showUser(Usuario $user)
    {
        $user->load([
            'roles:id,code,name',
            'profile:id,user_id,company_name,business_type,country,city,base_airport,base_airport_id,address,avatar,avatar_url,tax_data,birth_date,nationality,document_type,document_number,document_expiration,identity_validation_required,ine_curp,ine_cic,ine_ocr,ine_scan_raw,ine_scan_status,ine_front_path,ine_back_path',
            'demo:id,user_id,status,started_at,expires_at',
            'activeSuscripcion' => fn ($query) => $query->select([
                'subscriptions.id',
                'subscriptions.user_id',
                'subscriptions.plan_id',
                'subscriptions.status',
                'subscriptions.started_at',
                'subscriptions.expires_at',
            ]),
            'activeSuscripcion.plan:id,name,code,billing_cycle,price_monthly,price_yearly,max_aircraft,max_users,has_priority,has_concierge,has_reports,is_enterprise',
            'identityVerifications' => fn ($query) => $query->latest()->select([
                'id',
                'user_id',
                'provider',
                'template_type',
                'identity_verified',
                'status',
                'face_confidence',
                'face_match_score',
                'liveness_score',
                'brightness',
                'sharpness',
                'yaw',
                'pitch',
                'roll',
                'face_occluded',
                'image_path',
                'created_at',
            ]),
            'provider:id,user_id,company_name,commercial_name,approval_status,notes',
            'provider.user:id,name,email,phone,status',
            'provider.aircraft:id,provider_id,model,registration,status,category,capacity',
            'provider.companyDocuments:id,provider_id,name,status,expires_at',
            'ownedProvider:id,user_id,company_name,commercial_name,approval_status,notes',
        ]);

        return $this->ok([
            'user' => $this->serializeAdminUserDetail($user),
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
            'user' => $this->serializeAdminUserSummary(
                $user->fresh([
                    'roles:id,code,name',
                    'profile:id,user_id,company_name,city,base_airport,tax_data',
                    'provider:id,user_id,company_name,commercial_name,approval_status',
                    'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                    'demo:id,user_id,status,started_at,expires_at',
                    'activeSuscripcion' => fn ($query) => $query->select([
                        'subscriptions.id',
                        'subscriptions.user_id',
                        'subscriptions.plan_id',
                        'subscriptions.status',
                        'subscriptions.started_at',
                        'subscriptions.expires_at',
                    ]),
                    'activeSuscripcion.plan:id,name,code,billing_cycle',
                ])
            ),
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
            'user' => $this->serializeAdminUserSummary(
                $user->fresh([
                    'roles:id,code,name',
                    'profile:id,user_id,company_name,city,base_airport,tax_data',
                    'provider:id,user_id,company_name,commercial_name,approval_status',
                    'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                    'demo:id,user_id,status,started_at,expires_at',
                    'activeSuscripcion' => fn ($query) => $query->select([
                        'subscriptions.id',
                        'subscriptions.user_id',
                        'subscriptions.plan_id',
                        'subscriptions.status',
                        'subscriptions.started_at',
                        'subscriptions.expires_at',
                    ]),
                    'activeSuscripcion.plan:id,name,code,billing_cycle',
                ])
            ),
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

    public function anonymizeUser(Request $request, Usuario $user)
    {
        if ($request->user()?->is($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes anonimizar tu propio usuario administrador.',
            ], 422);
        }

        if (! $user->hasRole(Usuario::ROLE_CLIENT)) {
            return response()->json([
                'success' => false,
                'message' => 'Por ahora solo los usuarios cliente pueden anonimizarse desde este modulo.',
            ], 422);
        }

        $anonymizedEmail = sprintf('anon+client-%d-%s@redaviation.invalid', $user->id, now()->format('YmdHis'));
        $anonymizedName = sprintf('Cliente anonimizado #%d', $user->id);

        DB::transaction(function () use ($user, $anonymizedEmail, $anonymizedName) {
            $user->demo()->update([
                'status' => 'inactive',
                'expires_at' => now(),
            ]);

            $user->subscriptions()->whereIn('status', ['active', 'pending', 'past_due'])->update([
                'status' => 'cancelled',
                'payment_status' => DB::raw("CASE WHEN payment_status = 'paid' THEN payment_status ELSE 'cancelled' END"),
                'cancelled_at' => now(),
                'renews_at' => null,
            ]);

            $user->paymentMethods()->delete();
            $user->apiTokens()->delete();

            $user->identityVerifications()->update([
                'status' => 'anonymized',
                'identity_verified' => false,
                'face_confidence' => null,
                'face_match_score' => null,
                'liveness_score' => null,
                'brightness' => null,
                'sharpness' => null,
                'yaw' => null,
                'pitch' => null,
                'roll' => null,
                'face_occluded' => null,
                'image_path' => null,
                'aws_request_id' => null,
            ]);

            $user->profile()?->update([
                'company_name' => 'Cliente anonimizado',
                'business_type' => null,
                'birth_date' => null,
                'nationality' => null,
                'document_type' => null,
                'document_number' => null,
                'document_expiration' => null,
                'identity_validation_required' => false,
                'ine_curp' => null,
                'ine_cic' => null,
                'ine_ocr' => null,
                'ine_scan_raw' => null,
                'ine_scan_status' => 'anonymized',
                'ine_front_path' => null,
                'ine_back_path' => null,
                'tax_data' => null,
                'country' => null,
                'city' => null,
                'base_airport' => null,
                'base_airport_id' => null,
                'address' => null,
                'avatar' => null,
                'avatar_url' => null,
            ]);

            $user->forceFill([
                'name' => $anonymizedName,
                'email' => $anonymizedEmail,
                'password' => Hash::make(Str::password(32)),
                'phone' => null,
                'status' => 'inactive',
                'remember_token' => null,
                'contact_strikes' => 0,
                'contact_blocked_until' => null,
                'identity_verification_status' => 'anonymized',
                'identity_verification_message' => 'Datos personales anonimizados por administracion.',
                'identity_verified' => false,
                'face_detected' => false,
                'face_match_score' => null,
                'liveness_score' => null,
                'image_storage_score' => null,
                'biometric_image_saved' => false,
                'biometric_captured_at' => null,
                'biometric_provider' => null,
                'biometric_template_type' => null,
                'biometric_selfie_path' => null,
                'access_status' => 'anonymized',
                'trial_started_at' => null,
                'trial_ends_at' => null,
                'free_quote_limit' => 0,
                'free_quotes_used' => 0,
                'has_paid_access' => false,
                'paid_access_at' => null,
                'access_payment_id' => null,
                'access_expires_at' => null,
            ])->save();
        });

        $this->writeAudit($request, 'admin_user_anonymized', 'admin_users', sprintf(
            'Admin anonimizo al usuario cliente %s.',
            $user->id
        ));

        return $this->ok([
            'message' => 'Cliente anonimizado correctamente. Se conservaron historiales operativos y de pago sin datos personales.',
            'user' => $this->serializeAdminUserSummary(
                $user->fresh([
                    'roles:id,code,name',
                    'profile:id,user_id,company_name,city,base_airport,tax_data',
                    'provider:id,user_id,company_name,commercial_name,approval_status',
                    'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                    'demo:id,user_id,status,started_at,expires_at',
                    'activeSuscripcion' => fn ($query) => $query->select([
                        'subscriptions.id',
                        'subscriptions.user_id',
                        'subscriptions.plan_id',
                        'subscriptions.status',
                        'subscriptions.started_at',
                        'subscriptions.expires_at',
                    ]),
                    'activeSuscripcion.plan:id,name,code,billing_cycle',
                ])
            ),
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
        $operators = Proveedor::query()
            ->select([
                'id',
                'user_id',
                'company_name',
                'commercial_name',
                'approval_status',
                'notes',
                'created_at',
            ])
            ->with([
                'user:id,name,email,phone',
                'user.profile:id,user_id,company_name,base_airport',
            ])
            ->withCount([
                'aircraft as aircraft_count',
                'aircraft as active_aircraft_count' => fn ($query) => $query->where('status', 'active'),
                'aircraft as trial_aircraft_count' => fn ($query) => $query->where('status', 'trial_active'),
                'aircraft as pending_aircraft_count' => fn ($query) => $query->where('status', '!=', 'active'),
            ])
            ->latest('id')
            ->paginate(20);

        $operators->setCollection(
            $operators->getCollection()->map(function (Proveedor $provider) {
                return [
                    ...$provider->toArray(),
                    'base_airport' => $provider->user?->profile?->base_airport,
                    'contact_name' => $provider->user?->name,
                    'aircraft_metrics' => [
                        'aircraft' => (int) $provider->aircraft_count,
                        'active' => (int) $provider->active_aircraft_count,
                        'trial' => (int) $provider->trial_aircraft_count,
                        'pending' => (int) $provider->pending_aircraft_count,
                    ],
                ];
            })
        );

        return $this->ok(['operators' => $operators]);
    }

    public function sobrecargos()
    {
        return $this->ok([
            'sobrecargos' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
                ->with([
                    'profile',
                    'provider',
                    'ownedProvider',
                    'roles',
                    'demo',
                    'activeSuscripcion.plan',
                ])
                ->latest('id')
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

    public function crewAvailability(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_statuses' => ['nullable', 'boolean'],
        ]);

        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->addDays(6)->toDateString();
        $includeStatuses = array_key_exists('include_statuses', $data)
            ? filter_var($data['include_statuses'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $statusCatalog = $includeStatuses
            ? CatalogoDisponibilidadEstatus::query()
                ->select([
                    'id',
                    'clave',
                    'nombre',
                    'descripcion',
                    'color',
                    'icono',
                    'orden',
                    'seleccionable_sobrecargo',
                    'seleccionable_admin',
                    'permite_asignacion',
                ])
                ->where('activo', true)
                ->orderBy('orden')
                ->get()
            : collect();

        $defaultStatus = $includeStatuses ? $statusCatalog->firstWhere('clave', 'POR_CONFIRMAR') : null;
        abort_if($includeStatuses && ! $defaultStatus, 500, 'No existe el estatus POR_CONFIRMAR.');

        $crewMembers = Usuario::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.provider_id',
                'users.role',
                'users.operational_role',
                'users.status',
                'users.updated_at',
                'profiles.tax_data as profile_tax_data',
                'profiles.city as profile_city',
                'profiles.base_airport as profile_base_airport',
                'profiles.birth_date as profile_birth_date',
                'profiles.nationality as profile_nationality',
                'profiles.document_type as profile_document_type',
                'profiles.document_number as profile_document_number',
                'profiles.document_expiration as profile_document_expiration',
                'profiles.identity_validation_required as profile_identity_validation_required',
                'profiles.ine_scan_status as profile_ine_scan_status',
                'profiles.updated_at as profile_updated_at',
                'providers.id as related_provider_id',
                'providers.commercial_name as related_provider_commercial_name',
                'providers.company_name as related_provider_company_name',
                'providers.approval_status as related_provider_approval_status',
            ])
            ->whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->leftJoin('providers', 'providers.id', '=', 'users.provider_id')
            ->with([
                'disponibilidadesSobrecargo' => fn ($query) => $query
                    ->select([
                        'id',
                        'sobrecargo_id',
                        'fecha',
                        'estatus_id',
                        'motivo',
                        'comentario',
                        'origen',
                        'operacion_id',
                        'created_by',
                        'aprobado_por',
                        'aprobado_at',
                        'created_at',
                        'updated_at',
                    ])
                    ->with(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name'])
                    ->whereDate('fecha', '>=', $from)
                    ->whereDate('fecha', '<=', $to)
                    ->orderBy('fecha'),
            ])
            ->latest('users.id')
            ->get()
            ->map(function (Usuario $crew) use ($defaultStatus, $from, $to) {
                return $this->buildAdminCrewAvailabilityMemberPayload(
                    $crew,
                    $this->buildCrewAvailabilityRangePayload(
                    $crew->disponibilidadesSobrecargo->keyBy(fn (SobrecargoDisponibilidad $item) => $item->fecha?->toDateString()),
                        $from,
                        $to,
                        $defaultStatus
                    )->all()
                );
            })
            ->values();

        return $this->ok([
            'crew_members' => $crewMembers,
            'statuses' => $includeStatuses ? $this->formatAvailabilityStatusesCollection($statusCatalog, true) : [],
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function storeCrewAvailability(Request $request)
    {
        $data = $request->validate([
            'sobrecargo_id' => ['required', 'exists:users,id'],
            'dias' => ['nullable', 'array'],
            'dias.*.fecha' => ['required_with:dias', 'date'],
            'dias.*.estatus_id' => ['nullable', 'integer'],
            'dias.*.status_id' => ['nullable', 'integer'],
            'dias.*.status_key' => ['nullable', 'string', 'max:50'],
            'dias.*.clave' => ['nullable', 'string', 'max:50'],
            'dias.*.status' => ['nullable', 'string', 'max:100'],
            'dias.*.state' => ['nullable', 'string', 'max:100'],
            'dias.*.motivo' => ['nullable', 'string', 'max:255'],
            'dias.*.comentario' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'estatus_id' => ['nullable', 'integer'],
            'status_id' => ['nullable', 'integer'],
            'status_key' => ['nullable', 'string', 'max:50'],
            'clave' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'comentario' => ['nullable', 'string'],
            'operacion_id' => ['nullable', 'exists:operations,id'],
        ]);

        $crew = Usuario::query()->findOrFail($data['sobrecargo_id']);
        abort_if(! $crew->hasRole(Usuario::ROLE_SOBRECARGO) && $crew->operational_role !== Usuario::ROLE_SOBRECARGO, 422, 'El usuario seleccionado no corresponde a un sobrecargo.');

        $saved = collect();

        if (! empty($data['dias'])) {
            foreach ($data['dias'] as $day) {
                $status = $this->resolveAdminAvailabilityStatus($day);
                $saved->push($this->upsertAdminCrewAvailability($request, $crew, $status, $day['fecha'], $day));
            }

            return $this->ok([
                'availability' => $saved->map(fn (SobrecargoDisponibilidad $item) => $this->formatAdminAvailabilityPayload($item))->values(),
            ], 201);
        }

        $status = $this->resolveAdminAvailabilityStatus($data);
        $startDate = $data['fecha'] ?? $data['from'] ?? null;
        $endDate = $data['to'] ?? $startDate;

        abort_if(! $startDate || ! $endDate, 422, 'Debes indicar una fecha o rango de fechas.');

        foreach (CarbonPeriod::create(Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->startOfDay()) as $date) {
            $saved->push($this->upsertAdminCrewAvailability($request, $crew, $status, $date->toDateString(), $data));
        }

        return $this->ok([
            'availability' => $saved->map(fn (SobrecargoDisponibilidad $item) => $this->formatAdminAvailabilityPayload($item))->values(),
        ], 201);
    }

    public function approveCrewAvailabilityBlock(Request $request, SobrecargoDisponibilidad $availability)
    {
        $status = CatalogoDisponibilidadEstatus::query()->where('clave', 'BLOQUEO_APROBADO')->firstOrFail();

        $availability->update([
            'estatus_id' => $status->id,
            'aprobado_por' => $request->user()->id,
            'aprobado_at' => now(),
            'updated_by' => $request->user()->id,
            'bitacora' => $this->appendAvailabilityLog($availability, $status, $request->user()->id, [
                'motivo' => 'Bloqueo aprobado',
                'comentario' => (string) $request->input('comentario', 'Aprobado por administracion.'),
            ]),
        ]);

        return $this->ok([
            'availability' => $this->formatAdminAvailabilityPayload($availability->fresh(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name'])),
        ]);
    }

    public function rejectCrewAvailabilityBlock(Request $request, SobrecargoDisponibilidad $availability)
    {
        $status = CatalogoDisponibilidadEstatus::query()->where('clave', 'BLOQUEO_RECHAZADO')->firstOrFail();

        $availability->update([
            'estatus_id' => $status->id,
            'aprobado_por' => $request->user()->id,
            'aprobado_at' => now(),
            'updated_by' => $request->user()->id,
            'bitacora' => $this->appendAvailabilityLog($availability, $status, $request->user()->id, [
                'motivo' => 'Bloqueo rechazado',
                'comentario' => (string) $request->input('comentario', 'Rechazado por administracion.'),
            ]),
        ]);

        return $this->ok([
            'availability' => $this->formatAdminAvailabilityPayload($availability->fresh(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name'])),
        ]);
    }

    public function crewAvailabilityLog(SobrecargoDisponibilidad $availability)
    {
        return $this->ok([
            'availability' => $this->formatAdminAvailabilityPayload($availability->loadMissing(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name'])),
            'bitacora' => collect($availability->bitacora ?? [])->values(),
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
                    'operations.sobrecargo_user_id',
                    'operations.crew_status',
                ]),
                'latestOperation.sobrecargo:id,name',
                'latestOperation.timeline' => fn ($query) => $query
                    ->select([
                        'operation_timeline.id',
                        'operation_timeline.operation_id',
                        'operation_timeline.status',
                        'operation_timeline.title',
                        'operation_timeline.description',
                        'operation_timeline.created_at',
                    ])
                    ->latest('id')
                    ->limit(20),
            ])
            ->latest('id')
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

    private function availabilityStatusesPayload(bool $forAdmin = false)
    {
        return $this->formatAvailabilityStatusesCollection(
            CatalogoDisponibilidadEstatus::query()
                ->where('activo', true)
                ->orderBy('orden')
                ->get(),
            $forAdmin
        );
    }

    private function formatAvailabilityStatusesCollection($statuses, bool $forAdmin = false)
    {
        return collect($statuses)
            ->filter(fn (CatalogoDisponibilidadEstatus $status) => $forAdmin ? $status->seleccionable_admin : $status->seleccionable_sobrecargo)
            ->map(fn (CatalogoDisponibilidadEstatus $status) => [
                'id' => $status->id,
                'clave' => $status->clave,
                'nombre' => $status->nombre,
                'descripcion' => $status->descripcion,
                'color' => $status->color,
                'icono' => $status->icono,
                'orden' => $status->orden,
                'seleccionable_sobrecargo' => $status->seleccionable_sobrecargo,
                'seleccionable_admin' => $status->seleccionable_admin,
                'permite_asignacion' => $status->permite_asignacion,
            ])
            ->values();
    }

    private function resolveAdminAvailabilityStatus(array $data): CatalogoDisponibilidadEstatus
    {
        $statusId = $data['estatus_id'] ?? $data['status_id'] ?? null;
        $statusKey = $data['status_key'] ?? $data['clave'] ?? null;
        $statusLabel = $data['state'] ?? $data['status'] ?? null;

        $query = CatalogoDisponibilidadEstatus::query()->where('activo', true)->where('seleccionable_admin', true);

        if ($statusId) {
            $query->whereKey($statusId);
        } elseif ($statusKey) {
            $query->where('clave', strtoupper(trim($statusKey)));
        } elseif ($statusLabel) {
            $query->where('clave', $this->normalizeAvailabilityStatusKey($statusLabel));
        } else {
            abort(422, 'Debes indicar un estatus de disponibilidad.');
        }

        $status = $query->first();
        abort_if(! $status, 422, 'El estatus de disponibilidad no existe o no esta disponible para admin.');

        return $status;
    }

    private function normalizeAvailabilityStatusKey(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'DISPONIBLE' => 'DISPONIBLE',
            'NO DISPONIBLE', 'NO_DISPONIBLE' => 'NO_DISPONIBLE',
            'DESCANSO' => 'DESCANSO',
            'EN OPERACION', 'EN_OPERACION' => 'EN_OPERACION',
            'BLOQUEO SOLICITADO', 'BLOQUEO_SOLICITADO' => 'BLOQUEO_SOLICITADO',
            'BLOQUEO APROBADO', 'BLOQUEO_APROBADO' => 'BLOQUEO_APROBADO',
            'BLOQUEO RECHAZADO', 'BLOQUEO_RECHAZADO' => 'BLOQUEO_RECHAZADO',
            'POR CONFIRMAR', 'POR_CONFIRMAR' => 'POR_CONFIRMAR',
            default => strtoupper(str_replace(' ', '_', trim($value))),
        };
    }

    private function formatAdminAvailabilityPayload(SobrecargoDisponibilidad $availability): array
    {
        $status = $availability->estatus;

        return [
            'id' => $availability->id,
            'sobrecargo_id' => $availability->sobrecargo_id,
            'fecha' => $availability->fecha?->toDateString(),
            'from' => $availability->fecha?->toDateString(),
            'to' => $availability->fecha?->toDateString(),
            'estatus_id' => $availability->estatus_id,
            'status_id' => $availability->estatus_id,
            'clave' => $status?->clave,
            'status' => $status?->clave,
            'nombre' => $status?->nombre,
            'state' => $status?->nombre,
            'descripcion' => $status?->descripcion,
            'color' => $status?->color,
            'icono' => $status?->icono,
            'permite_asignacion' => (bool) $status?->permite_asignacion,
            'motivo' => $availability->motivo,
            'comentario' => $availability->comentario,
            'reason' => $availability->comentario,
            'origen' => $availability->origen,
            'created_by' => $availability->created_by,
            'created_by_nombre' => $availability->createdBy?->name,
            'operacion_id' => $availability->operacion_id,
            'aprobado_por' => $availability->aprobado_por,
            'aprobado_por_nombre' => $availability->aprobadoPor?->name,
            'aprobado_at' => optional($availability->aprobado_at)?->toISOString(),
            'created_at' => optional($availability->created_at)?->toISOString(),
            'updated_at' => optional($availability->updated_at)?->toISOString(),
        ];
    }

    private function formatSyntheticAdminAvailabilityPayload(string $date, CatalogoDisponibilidadEstatus $status): array
    {
        return [
            'id' => null,
            'sobrecargo_id' => null,
            'fecha' => $date,
            'from' => $date,
            'to' => $date,
            'estatus_id' => $status->id,
            'status_id' => $status->id,
            'clave' => $status->clave,
            'status' => $status->clave,
            'nombre' => $status->nombre,
            'state' => $status->nombre,
            'descripcion' => $status->descripcion,
            'color' => $status->color,
            'icono' => $status->icono,
            'permite_asignacion' => (bool) $status->permite_asignacion,
            'motivo' => null,
            'comentario' => null,
            'reason' => null,
            'origen' => 'SISTEMA',
            'created_by' => null,
            'created_by_nombre' => null,
            'operacion_id' => null,
            'aprobado_por' => null,
            'aprobado_por_nombre' => null,
            'aprobado_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function buildCrewAvailabilityRangePayload($storedAvailability, string $from, string $to, ?CatalogoDisponibilidadEstatus $defaultStatus = null)
    {
        $days = collect();

        foreach (CarbonPeriod::create(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay()) as $date) {
            $dateKey = $date->toDateString();
            $stored = $storedAvailability->get($dateKey);
            $days->push(
                $stored
                    ? $this->formatAdminAvailabilityPayload($stored)
                    : ($defaultStatus
                        ? $this->formatSyntheticAdminAvailabilityPayload($dateKey, $defaultStatus)
                        : $this->formatFallbackSyntheticAdminAvailabilityPayload($dateKey))
            );
        }

        return $days->values();
    }

    private function formatFallbackSyntheticAdminAvailabilityPayload(string $date): array
    {
        return [
            'id' => null,
            'sobrecargo_id' => null,
            'fecha' => $date,
            'from' => $date,
            'to' => $date,
            'estatus_id' => null,
            'status_id' => null,
            'clave' => 'POR_CONFIRMAR',
            'status' => 'POR_CONFIRMAR',
            'nombre' => 'Por confirmar',
            'state' => 'Por confirmar',
            'descripcion' => 'Disponibilidad pendiente de confirmar.',
            'color' => '#d6b98c',
            'icono' => null,
            'permite_asignacion' => false,
            'motivo' => null,
            'comentario' => null,
            'reason' => null,
            'origen' => 'SISTEMA',
            'created_by' => null,
            'created_by_nombre' => null,
            'operacion_id' => null,
            'aprobado_por' => null,
            'aprobado_por_nombre' => null,
            'aprobado_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function buildAdminCrewAvailabilityMemberPayload(Usuario $crew, array $availability): array
    {
        $taxData = $this->normalizeJoinedProfileTaxData($crew->profile_tax_data ?? null);
        $hasProfileData =
            $crew->profile_base_airport ||
            $crew->profile_city ||
            $taxData !== [] ||
            $crew->profile_birth_date ||
            $crew->profile_document_type ||
            $crew->profile_document_number;

        return [
            'id' => $crew->id,
            'name' => $crew->name,
            'email' => $crew->email,
            'phone' => $crew->phone,
            'provider_id' => $crew->provider_id ?: $crew->related_provider_id,
            'role' => $crew->role,
            'operational_role' => $crew->operational_role ?: Usuario::ROLE_SOBRECARGO,
            'status' => $crew->status,
            'current_status' => $taxData['current_status'] ?? $crew->status,
            'profile_state' => $taxData['profile_state'] ?? $taxData['validation_status'] ?? null,
            'validation_status' => $taxData['validation_status'] ?? $taxData['profile_state'] ?? null,
            'admin_notes' => $taxData['admin_notes'] ?? null,
            'updated_at' => optional($crew->updated_at)?->toISOString(),
            'base_airport' => $crew->profile_base_airport,
            'city' => $crew->profile_city,
            'roles' => [
                [
                    'code' => Usuario::ROLE_SOBRECARGO,
                    'name' => 'Sobrecargo',
                ],
            ],
            'profile' => $hasProfileData ? [
                'user_id' => $crew->id,
                'tax_data' => $taxData,
                'city' => $crew->profile_city,
                'base_airport' => $crew->profile_base_airport,
                'birth_date' => $crew->profile_birth_date,
                'nationality' => $crew->profile_nationality,
                'document_type' => $crew->profile_document_type,
                'document_number' => $crew->profile_document_number,
                'document_expiration' => $crew->profile_document_expiration,
                'ine_scan_status' => $crew->profile_ine_scan_status,
                'identity_validation_required' => $crew->profile_identity_validation_required,
                'updated_at' => $crew->profile_updated_at ? Carbon::parse($crew->profile_updated_at)->toISOString() : null,
            ] : null,
            'provider' => $crew->related_provider_id ? [
                'id' => $crew->related_provider_id,
                'commercial_name' => $crew->related_provider_commercial_name,
                'company_name' => $crew->related_provider_company_name,
                'approval_status' => $crew->related_provider_approval_status,
            ] : null,
            'availability' => $availability,
        ];
    }

    private function normalizeJoinedProfileTaxData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function appendAvailabilityLog(
        SobrecargoDisponibilidad $availability,
        CatalogoDisponibilidadEstatus $status,
        int $userId,
        array $data
    ): array {
        $log = collect($availability->bitacora ?? []);
        $log->push([
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'previous_estatus_id' => $availability->estatus_id,
            'new_estatus_id' => $status->id,
            'motivo' => $data['motivo'] ?? null,
            'comentario' => $data['comentario'] ?? null,
        ]);

        return $log->values()->all();
    }

    private function upsertAdminCrewAvailability(
        Request $request,
        Usuario $crew,
        CatalogoDisponibilidadEstatus $status,
        string $date,
        array $data
    ): SobrecargoDisponibilidad {
        $existing = SobrecargoDisponibilidad::query()
            ->where('sobrecargo_id', $crew->id)
            ->whereDate('fecha', $date)
            ->first();

        return SobrecargoDisponibilidad::query()->updateOrCreate(
            [
                'sobrecargo_id' => $crew->id,
                'fecha' => $date,
            ],
            [
                'estatus_id' => $status->id,
                'motivo' => $data['motivo'] ?? null,
                'comentario' => $data['comentario'] ?? $data['reason'] ?? null,
                'origen' => 'ADMIN',
                'operacion_id' => $data['operacion_id'] ?? null,
                'created_by' => $existing?->created_by ?? $request->user()->id,
                'updated_by' => $request->user()->id,
                'bitacora' => $this->appendAvailabilityLog(
                    $existing ?: new SobrecargoDisponibilidad(['estatus_id' => null, 'bitacora' => []]),
                    $status,
                    $request->user()->id,
                    $data
                ),
                'aprobado_por' => in_array($status->clave, ['BLOQUEO_APROBADO', 'BLOQUEO_RECHAZADO'], true) ? $request->user()->id : ($existing?->aprobado_por),
                'aprobado_at' => in_array($status->clave, ['BLOQUEO_APROBADO', 'BLOQUEO_RECHAZADO'], true) ? now() : ($existing?->aprobado_at),
            ],
        )->loadMissing(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name']);
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
                    'operations.sobrecargo_user_id',
                    'operations.crew_status',
                ]),
                'latestOperation.sobrecargo:id,name',
                'latestOperation.timeline' => fn ($query) => $query
                    ->select([
                        'operation_timeline.id',
                        'operation_timeline.operation_id',
                        'operation_timeline.status',
                        'operation_timeline.title',
                        'operation_timeline.description',
                        'operation_timeline.created_at',
                    ])
                    ->latest('id')
                    ->limit(20),
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

        $hasAssignedCrew = ! empty($data['sobrecargo_user_id']);
        $currentWorkflowStatus = Str::lower(trim((string) ($flightRequest->workflow_status ?? '')));

        if (
            $hasAssignedCrew
            && ! in_array($currentWorkflowStatus, ['flight_confirmed', 'vuelo confirmado', 'tracking_live', 'tracking en vivo'], true)
        ) {
            throw ValidationException::withMessages([
                'sobrecargo_user_id' => 'La sobrecargo solo puede asignarse cuando el vuelo ya esta confirmado para despacho operativo.',
            ]);
        }

        $nextWorkflowStatus = $hasAssignedCrew
            ? 'tracking_live'
            : ($flightRequest->workflow_status ?: 'operador_asignado');
        $nextOperationStatus = $hasAssignedCrew ? 'tracking_live' : 'operador_asignado';

        $operacion = Operacion::updateOrCreate(
            ['flight_request_id' => $flightRequest->id],
            [
                'provider_id' => $data['provider_id'],
                'aircraft_id' => $data['aircraft_id'],
                'sobrecargo_user_id' => $data['sobrecargo_user_id'] ?? null,
                'status' => $nextOperationStatus,
                'crew_status' => $hasAssignedCrew ? 'pending_crew_response' : null,
                'crew_confirmed_at' => null,
                'crew_decline_reason' => null,
            ]
        );

        $operacion->timeline()->create([
            'status' => $nextOperationStatus,
            'title' => 'Asignacion manual',
            'description' => $hasAssignedCrew
                ? 'Admin Red Aviation asigno proveedor, aeronave y sobrecargo. La operacion paso a tracking en vivo.'
                : 'Admin Red Aviation realizo el matching manual.',
            'created_by' => $request->user()->id,
        ]);

        $aircraft = Aeronave::find($data['aircraft_id']);
        $visibilityPayload = $flightRequest->visibility_payload ?? [];

        $flightRequest->update([
            'workflow_status' => $nextWorkflowStatus,
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
                'operational_status' => $nextWorkflowStatus,
                'operational_ready' => (bool) ($visibilityPayload['operational_ready'] ?? false),
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
            $operation = Operacion::query()
                ->where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            $requestedWorkflowStatus = $data['workflow_status'] ?? $flightRequest->workflow_status;
            $normalizedWorkflowStatus = Str::lower(trim((string) ($requestedWorkflowStatus ?? '')));
            $operationHasAssignedCrew = (bool) $operation?->sobrecargo_user_id;

            if (
                in_array($normalizedWorkflowStatus, ['tracking_live', 'tracking en vivo'], true)
                && ! $operationHasAssignedCrew
            ) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'No puedes mover el vuelo a tracking en vivo sin una sobrecargo asignada.',
                ]);
            }

            if (
                $operationHasAssignedCrew
                && in_array($normalizedWorkflowStatus, ['flight_confirmed', 'vuelo confirmado', 'tracking_live', 'tracking en vivo'], true)
            ) {
                $requestedWorkflowStatus = 'tracking_live';
                $normalizedWorkflowStatus = 'tracking_live';
            }

            $nextRequestStatus = $this->resolveFlightRequestStatusForWorkflow(
                $data['status'] ?? null,
                $normalizedWorkflowStatus,
                $flightRequest->status
            );

            $flightRequest->fill([
                'status' => $nextRequestStatus,
                'workflow_status' => $requestedWorkflowStatus ?? $flightRequest->workflow_status,
                'payment_status' => $data['payment_status'] ?? $flightRequest->payment_status,
                'notes' => $data['notes'] ?? $flightRequest->notes,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'operational_status' => $requestedWorkflowStatus ?? ($visibilityPayload['operational_status'] ?? null),
                    'operational_ready' => (bool) ($visibilityPayload['operational_ready'] ?? false),
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

                if ($requestedWorkflowStatus) {
                    $reservationUpdates['status'] = match ($requestedWorkflowStatus) {
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

            if ($operation && $requestedWorkflowStatus) {
                if (in_array($normalizedWorkflowStatus, ['tracking_live', 'tracking en vivo'], true)) {
                    $operation->update(['status' => 'tracking_live']);
                }

                $operation->timeline()->create([
                    'status' => $requestedWorkflowStatus,
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

    public function clientAccessPayments()
    {
        $payments = AccessPayment::query()
            ->with([
                'user:id,name,email,phone,access_status,has_paid_access,paid_access_at,access_expires_at,access_payment_id',
                'user.profile:id,user_id,company_name',
                'billingPlan:id,name,code,billing_cycle',
            ])
            ->latest('id')
            ->paginate(50);

        return $this->ok([
            'access_payments' => $payments->through(function (AccessPayment $payment) {
                $user = $payment->user;

                $storedPricing = is_array(data_get($payment->gateway_response, 'pricing')) ? data_get($payment->gateway_response, 'pricing') : [];
                $baseAmount = (float) ($storedPricing['base_amount'] ?? ($payment->billingPlan?->amount ?? $payment->amount ?? 0));
                $stripeFee = (float) ($storedPricing['stripe_fee'] ?? max(round(((float) $payment->amount) - $baseAmount, 2), 0));
                $totalAmount = (float) ($storedPricing['total_amount'] ?? $payment->amount);

                return [
                    'id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'amount' => $payment->amount,
                    'base_amount' => round($baseAmount, 2),
                    'stripe_fee' => round($stripeFee, 2),
                    'administrative_fee' => 0.0,
                    'total_amount' => round($totalAmount, 2),
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'provider' => $payment->provider,
                    'provider_payment_id' => $payment->provider_payment_id,
                    'provider_checkout_id' => $payment->provider_checkout_id,
                    'billing_period_start' => $payment->billing_period_start,
                    'billing_period_end' => $payment->billing_period_end,
                    'paid_at' => $payment->paid_at,
                    'card_brand' => $payment->card_brand,
                    'card_last4' => $payment->card_last4,
                    'is_current' => (int) ($user?->access_payment_id ?? 0) === (int) $payment->id,
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'access_status' => $user->access_status,
                        'has_paid_access' => (bool) $user->has_paid_access,
                        'paid_access_at' => $user->paid_access_at,
                        'access_expires_at' => $user->access_expires_at,
                        'company_name' => $user->profile?->company_name,
                    ] : null,
                    'plan' => $payment->billingPlan ? [
                        'id' => $payment->billingPlan->id,
                        'name' => $payment->billingPlan->name,
                        'code' => $payment->billingPlan->code,
                        'billing_cycle' => $payment->billingPlan->billing_cycle,
                    ] : null,
                ];
            }),
        ]);
    }

    public function reconcilePendingClientAccessPayments(): JsonResponse
    {
        if ($response = $this->ensureStripeConfigured()) {
            return $response;
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $payments = AccessPayment::query()
            ->with('user:id,access_status,has_paid_access,access_payment_id,access_expires_at')
            ->whereIn('status', ['pending', 'processing', 'requires_payment_method', 'requires_confirmation', 'requires_action', 'payment_pending'])
            ->latest('id')
            ->limit(100)
            ->get();

        $reconciled = 0;
        $checked = 0;
        $failures = [];

        foreach ($payments as $payment) {
            $checked++;

            try {
                $sessionId = trim((string) $payment->provider_checkout_id);
                $paymentIntentId = trim((string) $payment->provider_payment_id);

                if ($sessionId !== '') {
                    $session = Session::retrieve($sessionId);
                    $sessionPaymentStatus = strtolower((string) ($session->payment_status ?? ''));
                    $sessionStatus = strtolower((string) ($session->status ?? ''));

                    if ($sessionPaymentStatus === 'paid' || $sessionStatus === 'complete') {
                        $resolvedPaymentIntentId = is_string($session->payment_intent ?? null) ? (string) $session->payment_intent : $paymentIntentId;
                        $paymentIntent = $resolvedPaymentIntentId !== '' ? PaymentIntent::retrieve($resolvedPaymentIntentId) : null;
                        $this->markAdminAccessPaymentPaid($payment, $resolvedPaymentIntentId, $sessionId, $paymentIntent, $session);
                        $reconciled++;
                    }

                    continue;
                }

                if ($paymentIntentId !== '') {
                    $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
                    if (strtolower((string) ($paymentIntent->status ?? '')) === 'succeeded') {
                        $this->markAdminAccessPaymentPaid($payment, $paymentIntentId, '', $paymentIntent, null);
                        $reconciled++;
                    }
                }
            } catch (\Throwable $error) {
                $failures[] = [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'message' => $error->getMessage(),
                ];
            }
        }

        return $this->ok([
            'checked' => $checked,
            'reconciled' => $reconciled,
            'failures' => $failures,
        ]);
    }

    public function subscriptionPayments()
    {
        $payments = Pago::query()
            ->with([
                'user:id,name,email,phone',
                'subscription:id,user_id,plan_id,status,payment_status,started_at,expires_at,provider_subscription_id',
                'subscription.plan:id,name,code,billing_cycle',
            ])
            ->where('payment_type', 'subscription')
            ->latest('id')
            ->paginate(50);

        return $this->ok([
            'subscription_payments' => $payments->through(function (Pago $payment) {
                return [
                    'id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'subscription_id' => $payment->subscription_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'provider' => $payment->provider,
                    'transaction_reference' => $payment->transaction_reference,
                    'stripe_checkout_session_id' => $payment->stripe_checkout_session_id,
                    'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                    'status' => $payment->status,
                    'paid_at' => $payment->paid_at,
                    'failure_reason' => $payment->failure_reason,
                    'gateway_response' => $payment->gateway_response,
                    'user' => $payment->user ? [
                        'id' => $payment->user->id,
                        'name' => $payment->user->name,
                        'email' => $payment->user->email,
                        'phone' => $payment->user->phone,
                    ] : null,
                    'subscription' => $payment->subscription ? [
                        'id' => $payment->subscription->id,
                        'status' => $payment->subscription->status,
                        'payment_status' => $payment->subscription->payment_status,
                        'started_at' => $payment->subscription->started_at,
                        'expires_at' => $payment->subscription->expires_at,
                        'provider_subscription_id' => $payment->subscription->provider_subscription_id,
                        'plan' => $payment->subscription->plan ? [
                            'id' => $payment->subscription->plan->id,
                            'name' => $payment->subscription->plan->name,
                            'code' => $payment->subscription->plan->code,
                            'billing_cycle' => $payment->subscription->plan->billing_cycle,
                        ] : null,
                    ] : null,
                ];
            }),
        ]);
    }

    private function ensureStripeConfigured(): ?JsonResponse
    {
        if (! config('services.stripe.secret') || ! config('services.stripe.publishable')) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe no esta configurado en el servidor.',
            ], 503);
        }

        return null;
    }

    private function markAdminAccessPaymentPaid(
        AccessPayment $payment,
        string $providerPaymentId = '',
        string $checkoutSessionId = '',
        mixed $paymentIntent = null,
        mixed $checkoutSession = null,
    ): void {
        DB::transaction(function () use ($payment, $providerPaymentId, $checkoutSessionId, $paymentIntent, $checkoutSession) {
            $gatewayPayload = [
                'payment_intent' => $paymentIntent ? json_decode(json_encode($paymentIntent), true) : null,
                'checkout_session' => $checkoutSession ? json_decode(json_encode($checkoutSession), true) : null,
            ];
            $cardBrand = (string) (
                data_get($gatewayPayload, 'payment_intent.payment_method_details.card.brand')
                ?? data_get($gatewayPayload, 'payment_intent.charges.data.0.payment_method_details.card.brand')
                ?? ''
            );
            $cardLast4 = (string) (
                data_get($gatewayPayload, 'payment_intent.payment_method_details.card.last4')
                ?? data_get($gatewayPayload, 'payment_intent.charges.data.0.payment_method_details.card.last4')
                ?? ''
            );
            $subscriptionId = (string) (
                data_get($gatewayPayload, 'checkout_session.subscription')
                ?? $payment->provider_subscription_id
                ?? ''
            );
            $customerId = (string) (
                data_get($gatewayPayload, 'checkout_session.customer')
                ?? $payment->provider_customer_id
                ?? ''
            );

            $periodStart = $payment->billing_period_start ?: now()->toDateString();
            $periodEnd = $payment->billing_period_end ?: now()->addMonthNoOverflow()->toDateString();

            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId !== '' ? $providerPaymentId : $payment->provider_payment_id,
                'provider_checkout_id' => $checkoutSessionId !== '' ? $checkoutSessionId : $payment->provider_checkout_id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $payment->provider_subscription_id,
                'provider_customer_id' => $customerId !== '' ? $customerId : $payment->provider_customer_id,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'card_brand' => $cardBrand !== '' ? $cardBrand : $payment->card_brand,
                'card_last4' => $cardLast4 !== '' ? $cardLast4 : $payment->card_last4,
                'paid_at' => $payment->paid_at ?: now(),
                'gateway_response' => $gatewayPayload,
            ]);

            DB::table('users')
                ->where('id', $payment->user_id)
                ->where(function ($query) use ($payment) {
                    $query->whereNull('access_payment_id')
                        ->orWhere('access_payment_id', '<=', $payment->id);
                })
                ->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => DB::raw('coalesce(paid_access_at, now())'),
                'access_expires_at' => Carbon::parse($periodEnd)->endOfDay(),
                'access_payment_id' => $payment->id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : DB::raw('provider_subscription_id'),
                'provider_customer_id' => $customerId !== '' ? $customerId : DB::raw('provider_customer_id'),
                'updated_at' => now(),
            ]);
        });
    }

    public function aircraftFleet()
    {
        $aircraft = Aeronave::with([
            'provider:id,user_id,company_name,commercial_name',
            'provider.user:id,name',
            'provider.user.profile:id,user_id,company_name',
            'documents',
            'images',
            'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
        ])->latest()->paginate(40);

        $aircraft->setCollection(
            $aircraft->getCollection()->map(function (Aeronave $item) {
                $provider = $item->provider;
                $providerDisplayName =
                    $provider?->commercial_name ?:
                    $provider?->user?->profile?->company_name ?:
                    $provider?->company_name ?:
                    $provider?->user?->name;

                return [
                    ...$item->attributesToArray(),
                    'documents' => $item->documents->map(fn ($document) => $document->attributesToArray())->values(),
                    'images' => $item->images->map(fn ($image) => $image->attributesToArray())->values(),
                    'suscripcionesAeronave' => $item->suscripcionesAeronave
                        ->map(function ($subscription) {
                            return [
                                ...$subscription->attributesToArray(),
                                'plan' => $subscription->plan?->attributesToArray(),
                            ];
                        })
                        ->values(),
                    'provider_display_name' => $providerDisplayName,
                    'provider_name' => $providerDisplayName,
                    'provider' => $provider ? [
                        'id' => $provider->id,
                        'user_id' => $provider->user_id,
                        'company_name' => $provider->company_name,
                        'commercial_name' => $provider->commercial_name,
                        'display_name' => $providerDisplayName,
                    ] : null,
                ];
            })
        );

        return $this->ok([
            'aircraft' => $aircraft,
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

    private function serializeAdminUserSummary(Usuario $user): array
    {
        $commercialAccess = $this->serializeAdminCommercialAccess($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'created_at' => $user->created_at,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'effective_role' => $user->effectiveRole(),
            'provider_id' => $user->provider_id,
            'proveedor_id' => $user->provider_id,
            'status' => $user->status,
            'access_status' => $user->access_status,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'free_quote_limit' => (int) ($user->free_quote_limit ?? 1),
            'free_quotes_used' => (int) ($user->free_quotes_used ?? 0),
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_payment_id' => $user->access_payment_id,
            'access_expires_at' => $user->access_expires_at,
            'updated_at' => $user->updated_at,
            'access' => $user->accessStatus(),
            'commercial_access' => $commercialAccess,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
            ])->values(),
            'profile' => $user->profile ? [
                'company_name' => $user->profile->company_name,
                'city' => $user->profile->city,
                'base_airport' => $user->profile->base_airport,
                'tax_data' => $user->profile->tax_data,
            ] : null,
            'provider' => $user->provider ? [
                'id' => $user->provider->id,
                'company_name' => $user->provider->company_name,
                'commercial_name' => $user->provider->commercial_name,
                'approval_status' => $user->provider->approval_status,
            ] : null,
            'owned_provider' => $user->ownedProvider ? [
                'id' => $user->ownedProvider->id,
                'company_name' => $user->ownedProvider->company_name,
                'commercial_name' => $user->ownedProvider->commercial_name,
                'approval_status' => $user->ownedProvider->approval_status,
            ] : null,
            'ownedProvider' => $user->ownedProvider ? [
                'id' => $user->ownedProvider->id,
                'company_name' => $user->ownedProvider->company_name,
                'commercial_name' => $user->ownedProvider->commercial_name,
                'approval_status' => $user->ownedProvider->approval_status,
            ] : null,
            'demo' => $user->demo ? [
                'status' => $user->demo->status,
                'started_at' => $user->demo->started_at,
                'expires_at' => $user->demo->expires_at,
            ] : null,
            'active_suscripcion' => $user->activeSuscripcion ? [
                'id' => $user->activeSuscripcion->id,
                'plan_id' => $user->activeSuscripcion->plan_id,
                'status' => $user->activeSuscripcion->status,
                'started_at' => $user->activeSuscripcion->started_at,
                'expires_at' => $user->activeSuscripcion->expires_at,
                'plan' => $user->activeSuscripcion->plan ? [
                    'id' => $user->activeSuscripcion->plan->id,
                    'name' => $user->activeSuscripcion->plan->name,
                    'code' => $user->activeSuscripcion->plan->code,
                    'billing_cycle' => $user->activeSuscripcion->plan->billing_cycle,
                ] : null,
            ] : null,
            'activeSuscripcion' => $user->activeSuscripcion ? [
                'id' => $user->activeSuscripcion->id,
                'plan_id' => $user->activeSuscripcion->plan_id,
                'status' => $user->activeSuscripcion->status,
                'started_at' => $user->activeSuscripcion->started_at,
                'expires_at' => $user->activeSuscripcion->expires_at,
                'plan' => $user->activeSuscripcion->plan ? [
                    'id' => $user->activeSuscripcion->plan->id,
                    'name' => $user->activeSuscripcion->plan->name,
                    'code' => $user->activeSuscripcion->plan->code,
                    'billing_cycle' => $user->activeSuscripcion->plan->billing_cycle,
                ] : null,
            ] : null,
        ];
    }

    private function serializeAdminCommercialAccess(Usuario $user): array
    {
        $status = (string) ($user->access_status ?: 'trial_active');
        $freeQuoteLimit = (int) ($user->free_quote_limit ?? 1);
        $freeQuotesUsed = (int) ($user->free_quotes_used ?? 0);
        $remainingQuotes = max(0, $freeQuoteLimit - $freeQuotesUsed);

        $stage = match (true) {
            (bool) $user->has_paid_access && $user->paid_access_at !== null => 'paid',
            $freeQuotesUsed >= $freeQuoteLimit => 'trial_used',
            $freeQuotesUsed > 0 => 'trial_in_progress',
            in_array($status, ['registered', 'trial_active', 'payment_failed', 'payment_pending'], true) => 'new',
            default => 'blocked',
        };

        $label = match ($stage) {
            'paid' => 'Pago activo',
            'trial_used' => 'Prueba consumida',
            'trial_in_progress' => 'Prueba iniciada',
            'new' => 'Registro nuevo',
            default => 'Acceso bloqueado',
        };

        return [
            'status' => $status,
            'stage' => $stage,
            'label' => $label,
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_payment_id' => $user->access_payment_id,
            'access_expires_at' => $user->access_expires_at,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'free_quote_limit' => $freeQuoteLimit,
            'free_quotes_used' => $freeQuotesUsed,
            'remaining_free_quotes' => $remainingQuotes,
            'trial_consumed' => $freeQuotesUsed >= $freeQuoteLimit,
            'is_new_registration' => $freeQuotesUsed === 0 && ! $user->has_paid_access,
        ];
    }

    private function serializeAdminUserDetail(Usuario $user): array
    {
        $summary = $this->serializeAdminUserSummary($user);
        $summary['profile'] = $user->profile ? [
            'company_name' => $user->profile->company_name,
            'business_type' => $user->profile->business_type,
            'country' => $user->profile->country,
            'city' => $user->profile->city,
            'base_airport' => $user->profile->base_airport,
            'base_airport_id' => $user->profile->base_airport_id,
            'address' => $user->profile->address,
            'avatar' => $user->profile->avatar,
            'avatar_url' => $user->profile->avatar_url,
            'tax_data' => $user->profile->tax_data,
            'birth_date' => $user->profile->birth_date,
            'nationality' => $user->profile->nationality,
            'document_type' => $user->profile->document_type,
            'document_number' => $user->profile->document_number,
            'document_expiration' => $user->profile->document_expiration,
            'identity_validation_required' => $user->profile->identity_validation_required,
            'ine_curp' => $user->profile->ine_curp,
            'ine_cic' => $user->profile->ine_cic,
            'ine_ocr' => $user->profile->ine_ocr,
            'ine_scan_raw' => $user->profile->ine_scan_raw,
            'ine_scan_status' => $user->profile->ine_scan_status,
            'ine_front_path' => $user->profile->ine_front_path,
            'ine_back_path' => $user->profile->ine_back_path,
        ] : null;
        $summary['identity_verification_status'] = $user->identity_verification_status;
        $summary['identity_verification_message'] = $user->identity_verification_message;
        $summary['identity_verified'] = $user->identity_verified;
        $summary['face_detected'] = $user->face_detected;
        $summary['face_match_score'] = $user->face_match_score;
        $summary['liveness_score'] = $user->liveness_score;
        $summary['biometric_selfie_url'] = $user->biometric_selfie_url;
        $summary['identityVerifications'] = $user->identityVerifications->map(fn ($verification) => [
            'id' => $verification->id,
            'provider' => $verification->provider,
            'template_type' => $verification->template_type,
            'identity_verified' => $verification->identity_verified,
            'status' => $verification->status,
            'face_confidence' => $verification->face_confidence,
            'face_match_score' => $verification->face_match_score,
            'liveness_score' => $verification->liveness_score,
            'brightness' => $verification->brightness,
            'sharpness' => $verification->sharpness,
            'yaw' => $verification->yaw,
            'pitch' => $verification->pitch,
            'roll' => $verification->roll,
            'face_occluded' => $verification->face_occluded,
            'image_path' => $verification->image_path,
            'created_at' => $verification->created_at,
        ])->values();
        $summary['identity_verifications'] = $summary['identityVerifications'];
        $summary['provider'] = $user->provider ? [
            'id' => $user->provider->id,
            'company_name' => $user->provider->company_name,
            'commercial_name' => $user->provider->commercial_name,
            'approval_status' => $user->provider->approval_status,
            'notes' => $user->provider->notes,
            'user' => $user->provider->user ? [
                'id' => $user->provider->user->id,
                'name' => $user->provider->user->name,
                'email' => $user->provider->user->email,
                'phone' => $user->provider->user->phone,
                'status' => $user->provider->user->status,
            ] : null,
            'aircraft' => $user->provider->aircraft->map(fn ($aircraft) => [
                'id' => $aircraft->id,
                'model' => $aircraft->model,
                'registration' => $aircraft->registration,
                'status' => $aircraft->status,
                'category' => $aircraft->category,
                'capacity' => $aircraft->capacity,
            ])->values(),
            'company_documents' => $user->provider->companyDocuments->map(fn ($document) => [
                'id' => $document->id,
                'name' => $document->name,
                'status' => $document->status,
                'expires_at' => $document->expires_at,
            ])->values(),
        ] : null;

        return $summary;
    }
}
