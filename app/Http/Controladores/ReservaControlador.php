<?php

namespace App\Http\Controladores;

use App\Modelos\CalificacionServicio;
use App\Modelos\ContratoReserva;
use App\Modelos\Comision;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Contratos\ContratoPdfServicio;
use App\Servicios\Contratos\ContratoReservaServicio;
use App\Servicios\Contratos\DocuSignServicio;
use Barryvdh\DomPDF\Facade\Pdf;
use JsonException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReservaControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Reserva::with([
            'quote',
            'aircraft',
            'provider.user',
            'flightRequest',
            'payments' => fn ($payments) => $payments->latest('id'),
        ])->latest();

        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            $query->where('client_id', $request->user()->id);
        }

        $reservations = $query->paginate(20);
        $reservations->setCollection(
            $reservations->getCollection()->map(
                fn (Reserva $reservation) => $this->appendReservationStripeState(
                    $this->normalizeStripePendingReservationState($reservation)
                )
            )
        );

        return $this->ok(['reservations' => $reservations]);
    }

    public function providerIndex(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok([
            'reservations' => Reserva::with(['quote', 'aircraft', 'client'])
                ->where('provider_id', $provider->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);

        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            abort_if($reservation->client_id !== $request->user()->id, 403);
        }

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            abort_if($reservation->provider_id !== $request->user()->provider?->id, 403);
        }

        return $this->ok([
            'reservation' => $this->appendReservationStripeState(
                $this->normalizeStripePendingReservationState(
                    $reservation->load(['quote', 'aircraft', 'provider', 'flightRequest', 'legs', 'contract', 'review', 'payments'])
                )
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'quote_id' => ['nullable', 'exists:quotes,id'],
            'flight_request_id' => ['nullable', 'exists:flight_requests,id'],
        ]);

        abort_if(
            ! ($data['quote_id'] ?? null) && ! ($data['flight_request_id'] ?? null),
            422,
            'Debes enviar quote_id o flight_request_id para crear la reserva.'
        );

        if ($data['quote_id'] ?? null) {
            $quote = Cotizacion::with('flightRequest')->findOrFail($data['quote_id']);

            abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta cotizacion.');
            abort_if($quote->status !== 'accepted', 409, 'Primero debes aceptar la cotizacion.');

            $reservation = Reserva::firstOrCreate(
                ['quote_id' => $quote->id],
                [
                    'client_id' => $request->user()->id,
                    'provider_id' => $quote->provider_id,
                    'aircraft_id' => $quote->aircraft_id,
                    'flight_request_id' => $quote->flight_request_id,
                    'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'status' => 'pending_payment',
                    'total_amount' => $quote->total,
                    'currency' => $quote->currency ?? 'USD',
                ]
            );

            $quote->flightRequest->update(['status' => 'reserved']);
        } else {
            $flightRequest = SolicitudVuelo::with(['matches', 'quotes'])
                ->findOrFail($data['flight_request_id']);

            abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta solicitud.');

            $reservation = Reserva::where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            if (! $reservation) {
                abort_if(
                    ! $flightRequest->assigned_provider_id || ! $flightRequest->assigned_aircraft_id,
                    409,
                    'La solicitud aun no tiene proveedor y aeronave confirmados.'
                );

                $acceptedQuote = $flightRequest->quotes()
                    ->where('status', 'accepted')
                    ->latest('id')
                    ->first();

                $amount = (float) (
                    data_get($flightRequest->pricing_context, 'total_amount')
                    ?? $acceptedQuote?->total
                    ?? $flightRequest->final_price
                    ?? data_get($flightRequest->pricing_context, 'final_price')
                    ?? data_get($flightRequest->pricing_context, 'total')
                    ?? 0
                );

                abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para crear la reserva.');

                $reservation = Reserva::create([
                    'client_id' => $request->user()->id,
                    'provider_id' => $acceptedQuote?->provider_id ?? $flightRequest->assigned_provider_id,
                    'aircraft_id' => $acceptedQuote?->aircraft_id ?? $flightRequest->assigned_aircraft_id,
                    'flight_request_id' => $flightRequest->id,
                    'quote_id' => $acceptedQuote?->id,
                    'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'status' => 'pending_payment',
                    'total_amount' => $amount,
                    'currency' => $acceptedQuote?->currency ?? $flightRequest->currency ?? 'USD',
                ]);
            }

            $flightRequest->update([
                'status' => 'reserved',
                'workflow_status' => $flightRequest->workflow_status ?: 'contrato pendiente',
            ]);
        }

        $commissionBaseAmount = (float) (data_get($flightRequest->pricing_context, 'flight_cost') ?? $reservation->total_amount);

        Comision::firstOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'provider_id' => $reservation->provider_id,
                'platform_fee' => round($commissionBaseAmount * 0.10, 2),
                'provider_amount' => round($commissionBaseAmount * 0.90, 2),
                'status' => 'held',
            ]
        );
        $this->buildReservationContract($reservation);
        $this->writeAudit($request, 'create', 'reservations', 'Reserva creada o recuperada para el flujo del cliente.');

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft', 'contract'])], 201);
    }

    private function normalizeStripePendingReservationState(Reserva $reservation): Reserva
    {
        $latestPayment = $reservation->payments->sortByDesc('id')->first();
        $flightRequest = $reservation->flightRequest;

        if (! $latestPayment || ! $flightRequest) {
            return $reservation;
        }

        $paymentStatus = strtolower(trim((string) $latestPayment->status));
        $provider = strtolower(trim((string) $latestPayment->provider));
        $hasPaidSignal = ! empty($latestPayment->paid_at)
            && $provider === 'stripe'
            && in_array($paymentStatus, ['pending', 'processing', ''], true);

        if (! $hasPaidSignal) {
            return $reservation;
        }

        DB::transaction(function () use ($reservation, $flightRequest, $latestPayment) {
            $flightRequest->forceFill([
                'payment_status' => 'paid',
                'payment_method' => trim((string) $flightRequest->payment_method) !== '' ? $flightRequest->payment_method : 'stripe_checkout',
                'workflow_status' => 'vuelo confirmado',
                'status' => 'reserved',
                'updated_at' => now(),
            ])->save();

            $reservation->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $reservation->confirmed_at ?: $latestPayment->paid_at ?: now(),
                'updated_at' => now(),
            ])->save();

            $latestPayment->forceFill([
                'status' => 'paid',
                'failure_reason' => null,
                'updated_at' => now(),
            ])->save();
        });

        return $reservation->fresh(['quote', 'aircraft', 'provider.user', 'flightRequest', 'payments']);
    }

    private function appendReservationStripeState(Reserva $reservation): Reserva
    {
        $flightRequest = $reservation->flightRequest;
        $latestPayment = $reservation->payments->sortByDesc('id')->first();
        $paymentStatus = $flightRequest?->payment_status
            ?: ($latestPayment?->status ?: null);

        $reservation->setAttribute('payment_status', $paymentStatus);
        $reservation->setAttribute('booking_status', $reservation->status === 'confirmed' ? 'confirmed' : $reservation->status);
        $reservation->setAttribute('stripe_checkout_session_id', $flightRequest?->stripe_checkout_session_id ?: $latestPayment?->stripe_checkout_session_id);
        $reservation->setAttribute('stripe_payment_intent_id', $flightRequest?->stripe_payment_intent_id ?: $latestPayment?->stripe_payment_intent_id);

        return $reservation;
    }

    public function showContract(Request $request, mixed $reservation, DocuSignServicio $docuSignServicio)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        return $this->ok([
            'contract' => $this->buildReservationContract($reservation)->fresh(),
            'reservation' => $reservation->load(['quote', 'aircraft', 'provider']),
            'docusign_enabled' => $docuSignServicio->estaConfigurado(),
        ]);
    }

    public function downloadContractPdf(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation)->fresh();
        $fileName = ($contract->contract_code ?: 'contrato-'.$reservation->id).'.pdf';
        $pdf = Pdf::loadView('pdf.contract', $this->buildContractPdfPayload($reservation, $contract))
            ->setPaper('a4');

        return $pdf->download($fileName);
    }

    public function generateContract(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation, true);
        $this->writeAudit($request, 'generate', 'reservation_contracts', 'Contrato de reserva generado.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'reservation' => $reservation->fresh(),
        ]);
    }

    public function showContractStatusById(
        Request $request,
        ContratoReserva $contract,
        DocuSignServicio $docuSignServicio,
        ContratoPdfServicio $contratoPdfServicio,
        ContratoReservaServicio $contratoReservaServicio,
    )
    {
        $contract->loadMissing(['reservation.client', 'reservation.aircraft', 'reservation.provider', 'reservation.payments']);
        abort_if(! $contract->reservation, 404, 'Contrato sin reserva asociada.');
        $this->authorizeReservationClient($request, $contract->reservation);

        $this->syncContractWithDocuSign(
            $contract,
            $docuSignServicio,
            $contratoPdfServicio,
            $contratoReservaServicio,
        );

        $contract = $contract->fresh();
        $latestPayment = $contract->reservation->payments->sortByDesc('id')->first();
        $normalizedUiStatus = $this->normalizeContractUiStatus($contract);
        $isSigned = $normalizedUiStatus === 'completed';
        $signedPdfUrl = filled($contract->signed_pdf_path)
            ? route('cliente.contratos.pdf-firmado', ['contract' => $contract->id])
            : null;
        $nextAction = match (true) {
            $normalizedUiStatus === 'completed' && $latestPayment?->status !== 'paid' => 'pay',
            $normalizedUiStatus === 'sent' => 'wait_signature',
            $normalizedUiStatus === 'generated' => 'sign',
            in_array($normalizedUiStatus, ['declined', 'voided', 'error'], true) => 'contact_support',
            default => 'none',
        };

        return $this->ok([
            'contract' => $contract,
            'reservation' => $contract->reservation->fresh(['aircraft', 'provider', 'payments', 'contract']),
            'payment_order' => $latestPayment,
            'frontend_state' => [
                'contract_id' => $contract->id,
                'ui_status' => $normalizedUiStatus,
                'ready_for_payment' => $isSigned,
                'signed_pdf_url' => $signedPdfUrl,
                'signing_url' => null,
                'next_action' => $nextAction,
                'status_message' => $this->buildContractStatusMessage($normalizedUiStatus, $latestPayment?->status),
            ],
            'status_summary' => [
                'contract_status' => $contract->status,
                'docusign_status' => $contract->docusign_status,
                'is_signed' => $isSigned,
                'payment_enabled' => $isSigned,
                'has_embedded_envelope' => filled($contract->docusign_envelope_id),
                'has_signed_pdf' => filled($contract->signed_pdf_path),
                'payment_status' => $latestPayment?->status,
            ],
        ]);
    }

    private function syncContractWithDocuSign(
        ContratoReserva $contract,
        DocuSignServicio $docuSignServicio,
        ContratoPdfServicio $contratoPdfServicio,
        ContratoReservaServicio $contratoReservaServicio,
    ): void {
        $contract->loadMissing(['reservation.client', 'reservation.flightRequest', 'reservation.payments']);

        if (! $contract->reservation || blank($contract->docusign_envelope_id) || ! $docuSignServicio->estaConfigurado()) {
            return;
        }

        $currentDocuSignStatus = strtolower(trim((string) ($contract->docusign_status ?? '')));
        $currentContractStatus = strtolower(trim((string) ($contract->status ?? '')));

        if (in_array($currentDocuSignStatus, ['completed', 'declined', 'voided', 'error'], true) &&
            in_array($currentContractStatus, ['completed', 'signed'], true)) {
            return;
        }

        try {
            $remoteStatus = $docuSignServicio->obtenerEstadoEnvelope((string) $contract->docusign_envelope_id);

            if ($remoteStatus !== '' && $remoteStatus !== $currentDocuSignStatus) {
                $contract->update(['docusign_status' => $remoteStatus]);
            }

            if ($remoteStatus !== 'completed') {
                return;
            }

            if (filled($contract->signed_pdf_path) && $currentContractStatus === 'completed') {
                return;
            }

            $signedPdf = $docuSignServicio->descargarPdfCombinado((string) $contract->docusign_envelope_id);
            $signedPdfPath = $contratoPdfServicio->guardarPdfFirmado(
                (int) $contract->reservation_id,
                (string) $contract->docusign_envelope_id,
                $signedPdf
            );

            $termsSnapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];
            $termsSnapshot['docusign'] = [
                'completed_via' => 'status_poll',
                'status' => $remoteStatus,
                'completed_at' => now()->toIso8601String(),
            ];

            $contratoReservaServicio->registrarFirma(
                $contract->reservation,
                $contract,
                $contract->reservation->client,
                $termsSnapshot,
                $signedPdfPath,
                $remoteStatus
            );
        } catch (Throwable $exception) {
            Log::warning('No fue posible reconciliar el contrato con DocuSign al consultar estado.', [
                'contract_id' => $contract->id,
                'envelope_id' => $contract->docusign_envelope_id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function downloadSignedContractPdf(Request $request, ContratoReserva $contract)
    {
        $contract->loadMissing(['reservation']);
        abort_if(! $contract->reservation, 404, 'Contrato sin reserva asociada.');
        $this->authorizeReservationClient($request, $contract->reservation);
        abort_if(blank($contract->signed_pdf_path), 404, 'El contrato aun no tiene un PDF firmado disponible.');

        return Storage::disk('local')->download(
            $contract->signed_pdf_path,
            ($contract->contract_code ?: 'contrato-firmado-'.$contract->id).'.pdf'
        );
    }

    private function normalizeContractUiStatus(ContratoReserva $contract): string
    {
        $docusignStatus = strtolower(trim((string) ($contract->docusign_status ?? '')));
        $contractStatus = strtolower(trim((string) ($contract->status ?? '')));

        if (in_array($docusignStatus, ['completed', 'declined', 'voided', 'error', 'sent'], true)) {
            return $docusignStatus;
        }

        if (in_array($contractStatus, ['completed', 'sent', 'generated'], true)) {
            return $contractStatus;
        }

        return 'generated';
    }

    private function buildContractStatusMessage(string $uiStatus, ?string $paymentStatus): string
    {
        return match ($uiStatus) {
            'generated' => 'El contrato ya existe y esta listo para firma.',
            'sent' => 'El contrato fue enviado a DocuSign y esta pendiente de firma.',
            'completed' => $paymentStatus === 'paid'
                ? 'El contrato ya fue firmado y el pago ya fue confirmado.'
                : 'El contrato ya fue completado. Ya puedes continuar con el pago.',
            'declined' => 'La firma del contrato fue rechazada por el cliente.',
            'voided' => 'El sobre de DocuSign fue cancelado o invalidado.',
            'error' => 'Ocurrio un error con la firma del contrato. Se requiere revision.',
            default => 'Estado de contrato disponible.',
        };
    }

    public function startEmbeddedSigning(
        Request $request,
        mixed $reservation,
        DocuSignServicio $docuSignServicio,
        ContratoPdfServicio $contratoPdfServicio,
    ) {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $requestStartedAt = microtime(true);
        Log::info('DOCUSIGN 1 - Inicio endpoint', [
            'reservation_id' => $reservation->id,
            'user_id' => $request->user()?->id,
            'has_full_contract_html' => $request->filled('full_contract_html'),
            'has_document_html' => $request->filled('document_html'),
            'has_contract_html' => $request->filled('contract_html'),
            'has_source_contract_path' => $request->filled('source_contract_path'),
        ]);

        if (! $docuSignServicio->estaConfigurado()) {
            return response()->json([
                'success' => false,
                'message' => $docuSignServicio->buildConfigurationErrorMessage(),
                'docusign_debug' => $docuSignServicio->configurationDiagnostics(),
            ], 422);
        }

        $data = $request->validate([
            'contract_snapshot' => ['nullable', 'array'],
            'contract_html' => ['nullable', 'string'],
            'contract_markup' => ['nullable', 'string'],
            'document_html' => ['nullable', 'string'],
            'full_contract_html' => ['nullable', 'string'],
            'contract_plain_text' => ['nullable', 'string'],
            'full_contract_text' => ['nullable', 'string'],
            'source_contract_path' => ['nullable', 'string', 'max:500'],
            'document_source' => ['nullable', 'string', 'max:120'],
            'return_url' => ['nullable', 'string', 'max:1000'],
            'callback_url' => ['nullable', 'string', 'max:1000'],
            'return_path' => ['nullable', 'string', 'max:255'],
            'regenerate' => ['nullable', 'boolean'],
        ]);

        $shouldRegenerate = (bool) ($data['regenerate'] ?? false);
        $contract = $this->buildReservationContract($reservation, $shouldRegenerate);
        $termsSnapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];

        if (is_array($data['contract_snapshot'] ?? null) && ! empty($data['contract_snapshot'])) {
            $termsSnapshot['client_contract_snapshot'] = $data['contract_snapshot'];
        }

        $fullContractHtml = $this->resolvePreferredContractHtml($data, $termsSnapshot);
        $htmlContainsSignatureAnchor = $fullContractHtml !== ''
            ? Str::contains($fullContractHtml, '/sig_cliente/')
            : null;
        if ($fullContractHtml !== '') {
            $termsSnapshot['full_contract_html_checksum'] = sha1($fullContractHtml);
            $termsSnapshot['full_contract_html_length'] = strlen($fullContractHtml);
        }

        Log::info('DOCUSIGN 2 - HTML resuelto', [
            'reservation_id' => $reservation->id,
            'regenerate_requested' => $shouldRegenerate,
            'html_length' => strlen($fullContractHtml),
            'html_contains_signature_anchor' => $htmlContainsSignatureAnchor,
            'using_source_contract_path' => false,
            'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
        ]);

        if ($fullContractHtml !== '' && $htmlContainsSignatureAnchor === false) {
            Log::warning('DOCUSIGN 2.1 - HTML sin anchor de firma', [
                'reservation_id' => $reservation->id,
                'regenerate_requested' => $shouldRegenerate,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El HTML final del contrato no contiene el anchor /sig_cliente/ requerido para DocuSign.',
                'docusign_debug' => [
                    'reservation_id' => $reservation->id,
                    'regenerate_requested' => $shouldRegenerate,
                    'html_contains_signature_anchor' => false,
                    'reused_existing_pdf' => false,
                ],
            ], 422);
        }

        $fullContractText = $this->resolvePreferredContractText($data, $termsSnapshot);
        if ($fullContractText !== '') {
            $termsSnapshot['full_contract_text'] = $fullContractText;
        }

        if (filled($data['source_contract_path'] ?? null)) {
            $termsSnapshot['source_contract_path'] = (string) $data['source_contract_path'];
        }

        if (filled($data['document_source'] ?? null)) {
            $termsSnapshot['document_source'] = (string) $data['document_source'];
        }

        $contract->update([
            'terms_snapshot' => $termsSnapshot,
            'signer_name' => $reservation->client?->name,
            'signer_email' => $reservation->client?->email,
            'client_user_id' => $contract->client_user_id ?: 'client_'.$reservation->id.'_'.Str::lower(Str::random(8)),
        ]);
        $contract = $contract->fresh();

        abort_if(
            blank($contract->signer_name) || blank($contract->signer_email),
            422,
            'La reserva no tiene un nombre y correo de cliente validos para firmar.'
        );

        $existingPdfPath = (string) ($contract->contract_pdf_path ?? '');
        $canReuseExistingPdf = ! $shouldRegenerate
            && $existingPdfPath !== ''
            && Storage::disk('local')->exists($existingPdfPath);

        if ($shouldRegenerate && $canReuseExistingPdf) {
            Log::warning('DOCUSIGN 2.4 - Regenerate solicito reutilizacion inesperada de PDF', [
                'reservation_id' => $reservation->id,
                'regenerate_requested' => true,
                'reused_existing_pdf' => true,
                'previous_contract_pdf_path' => $existingPdfPath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'DocuSign detecto una reutilizacion inesperada del PDF aun cuando regenerate=true.',
                'docusign_debug' => [
                    'reservation_id' => $reservation->id,
                    'regenerate_requested' => true,
                    'reused_existing_pdf' => true,
                    'previous_contract_pdf_path' => $existingPdfPath,
                ],
            ], 422);
        }

        $pdfRelativePath = $canReuseExistingPdf
            ? $existingPdfPath
            : (
                $fullContractHtml !== ''
                    ? $contratoPdfServicio->guardarContratoReservaDesdeHtml(
                        (string) $contract->contract_code,
                        (int) $reservation->id,
                        $fullContractHtml
                    )
                    : $contratoPdfServicio->guardarContratoReserva(
                        (string) $contract->contract_code,
                        (int) $reservation->id,
                        $this->buildContractPdfPayload($reservation, $contract)
                    )
            );

        $pdfAbsolutePath = $contratoPdfServicio->rutaAbsoluta($pdfRelativePath);
        $pdfSizeBytes = is_file($pdfAbsolutePath) ? filesize($pdfAbsolutePath) : null;
        $pdfContainsSignatureAnchor = is_file($pdfAbsolutePath)
            ? Str::contains((string) file_get_contents($pdfAbsolutePath), '/sig_cliente/')
            : null;
        $docusignDebug = [
            'reservation_id' => $reservation->id,
            'regenerate_requested' => $shouldRegenerate,
            'reused_existing_pdf' => $canReuseExistingPdf,
            'previous_contract_pdf_path' => $existingPdfPath !== '' ? $existingPdfPath : null,
            'new_contract_pdf_path' => $pdfRelativePath,
            'pdf_size_bytes' => $pdfSizeBytes,
            'html_contains_signature_anchor' => $htmlContainsSignatureAnchor,
            'pdf_contains_signature_anchor' => $pdfContainsSignatureAnchor,
        ];

        Log::info('DOCUSIGN 2.5 - PDF listo', [
            'reservation_id' => $reservation->id,
            'regenerate_requested' => $shouldRegenerate,
            'reused_existing_pdf' => $canReuseExistingPdf,
            'previous_contract_pdf_path' => $existingPdfPath !== '' ? $existingPdfPath : null,
            'new_contract_pdf_path' => $pdfRelativePath,
            'pdf_size_bytes' => $pdfSizeBytes,
            'html_contains_signature_anchor' => $htmlContainsSignatureAnchor,
            'pdf_contains_signature_anchor' => $pdfContainsSignatureAnchor,
            'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
        ]);

        if ($canReuseExistingPdf && $pdfContainsSignatureAnchor === false) {
            Log::warning('DOCUSIGN 2.6 - PDF reutilizado sin anchor visible', [
                'reservation_id' => $reservation->id,
                'regenerate_requested' => $shouldRegenerate,
                'reused_existing_pdf' => true,
                'previous_contract_pdf_path' => $existingPdfPath,
                'new_contract_pdf_path' => $pdfRelativePath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El PDF reutilizado no contiene un anchor /sig_cliente/ verificable para DocuSign.',
                'docusign_debug' => $docusignDebug,
            ], 422);
        }

        if (! $canReuseExistingPdf && $fullContractHtml !== '' && $pdfContainsSignatureAnchor === false) {
            Log::warning('DOCUSIGN 2.7 - PDF regenerado sin anchor visible en binario', [
                'reservation_id' => $reservation->id,
                'regenerate_requested' => $shouldRegenerate,
                'new_contract_pdf_path' => $pdfRelativePath,
            ]);
        }

        $returnUrl = $this->resolveDocuSignReturnUrl(
            $docuSignServicio,
            (int) $contract->id,
            (int) $reservation->id,
            $data
        );

        try {
            $envelopeId = $docuSignServicio->crearEnvelopeParaFirmaEmbebida(
                $contratoPdfServicio->rutaAbsoluta($pdfRelativePath),
                (string) $contract->signer_name,
                (string) $contract->signer_email,
                (string) $contract->client_user_id
            );

            $signingUrl = $docuSignServicio->crearRecipientView(
                $envelopeId,
                (string) $contract->signer_name,
                (string) $contract->signer_email,
                (string) $contract->client_user_id,
                $returnUrl
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'docusign_debug' => array_merge(
                    $docuSignServicio->configurationDiagnostics(),
                    $docusignDebug,
                    [
                        'runtime_error' => $docuSignServicio->runtimeDiagnosticsFromException(
                            $exception->getPrevious() instanceof Throwable ? $exception->getPrevious() : $exception
                        ),
                    ]
                ),
            ], 422);
        }

        $contract->update([
            'status' => 'sent',
            'docusign_envelope_id' => $envelopeId,
            'docusign_status' => 'sent',
            'contract_pdf_path' => $pdfRelativePath,
            'document_url' => $pdfRelativePath,
            'generated_at' => $contract->generated_at ?: now(),
            'sent_at' => now(),
        ]);

        $this->writeAudit($request, 'send', 'reservation_contracts', 'Contrato enviado a DocuSign para firma embebida.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'reservation' => $reservation->fresh(['contract']),
            'signing_url' => $signingUrl,
            'recipient_view_url' => $signingUrl,
            'embedded_signing_url' => $signingUrl,
            'envelope_id' => $envelopeId,
            'docusign_debug' => $docusignDebug,
        ]);
    }

    private function resolveDocuSignReturnUrl(
        DocuSignServicio $docuSignServicio,
        int $contractId,
        int $reservationId,
        array $data = []
    ): string {
        foreach (['return_url', 'callback_url'] as $key) {
            $candidate = trim((string) ($data[$key] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return $docuSignServicio->construirReturnUrl(
            $contractId,
            $data['return_path'] ?? null,
            [
                'reservation_id' => $reservationId,
                'refresh' => 'contract_status',
            ],
        );
    }

    public function signContract(
        Request $request,
        mixed $reservation,
        ContratoReservaServicio $contratoReservaServicio,
        ContratoPdfServicio $contratoPdfServicio,
    )
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation);
        $signaturePayload = $request->input('signature');
        $contractSnapshotPayload = $request->input('contract_snapshot');
        $termsSnapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];

        if (is_array($contractSnapshotPayload) && ! empty($contractSnapshotPayload)) {
            $termsSnapshot['client_contract_snapshot'] = $contractSnapshotPayload;
        }

        if (is_array($signaturePayload) && filled($signaturePayload['data_url'] ?? null)) {
            $termsSnapshot['client_signature'] = [
                'name' => $signaturePayload['name'] ?? 'firma.png',
                'mime_type' => $signaturePayload['mime_type'] ?? 'image/png',
                'size' => (int) ($signaturePayload['size'] ?? 0),
                'data_url' => $signaturePayload['data_url'],
            ];
        }

        $contract->terms_snapshot = $termsSnapshot;
        $contract->signer_name ??= $request->user()->name;
        $contract->signer_email ??= $request->user()->email;

        $signedPdfPath = $contratoPdfServicio->guardarContratoFirmadoManual(
            (string) $contract->contract_code,
            (int) $reservation->id,
            $this->buildContractPdfPayload($reservation, $contract)
        );

        $contract->update([
            'signer_name' => $contract->signer_name,
            'signer_email' => $contract->signer_email,
            'contract_pdf_path' => $contract->contract_pdf_path ?: $signedPdfPath,
            'document_url' => $signedPdfPath,
        ]);

        $paymentOrder = $contratoReservaServicio->registrarFirma(
            $reservation,
            $contract,
            $request->user(),
            $termsSnapshot,
            $signedPdfPath
        );

        $this->writeAudit($request, 'sign', 'reservation_contracts', 'Contrato firmado por cliente.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'payment_order' => $paymentOrder->fresh(),
            'reservation' => $reservation->fresh(['contract', 'payments']),
        ]);
    }

    public function rateService(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);
        abort_if($reservation->status !== 'completed', 409, 'Solo puedes calificar servicios finalizados.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = CalificacionServicio::updateOrCreate(
            [
                'reservation_id' => $reservation->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'submitted_at' => now(),
            ]
        );

        $this->writeAudit($request, 'create', 'service_reviews', 'Cliente califico el servicio.');

        return $this->ok([
            'review' => $review,
            'reservation' => $reservation->fresh(['review']),
        ]);
    }

    private function authorizeReservationClient(Request $request, Reserva $reservation): void
    {
        abort_if($reservation->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);
    }

    private function resolveReservation(mixed $identifier): Reserva
    {
        if ($identifier instanceof Reserva) {
            return $identifier->load(['quote', 'aircraft', 'provider', 'legs', 'contract', 'review', 'payments']);
        }

        $normalizedIdentifier = $this->normalizeReservationIdentifier($identifier);

        return Reserva::with(['quote', 'aircraft', 'provider', 'legs', 'contract', 'review', 'payments'])
            ->where('id', $normalizedIdentifier)
            ->orWhere('flight_request_id', $normalizedIdentifier)
            ->latest('id')
            ->firstOrFail();
    }

    private function normalizeReservationIdentifier(mixed $value): string
    {
        if ($value instanceof Reserva) {
            return (string) $value->getKey();
        }

        if (is_array($value)) {
            return $this->normalizeReservationIdentifier(
                $value['id'] ?? $value['reservation_id'] ?? $value['flight_request_id'] ?? ''
            );
        }

        if (is_object($value)) {
            return $this->normalizeReservationIdentifier(
                $value->id ?? $value->reservation_id ?? $value->flight_request_id ?? ''
            );
        }

        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return '';
        }

        if (str_starts_with($normalizedValue, '{') || str_starts_with($normalizedValue, '[')) {
            try {
                $decoded = json_decode($normalizedValue, true, 512, JSON_THROW_ON_ERROR);

                return $this->normalizeReservationIdentifier($decoded);
            } catch (JsonException) {
                return $normalizedValue;
            }
        }

        return $normalizedValue;
    }

    private function buildReservationContract(Reserva $reservation, bool $regenerate = false): ContratoReserva
    {
        $existing = $reservation->contract;

        if ($existing && ! $regenerate) {
            return $existing;
        }

        $payload = [
            'contract_code' => $existing?->contract_code ?? 'CTR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'generated',
            'generated_at' => now(),
            'signed_by_user_id' => null,
            'signed_at' => null,
            'terms_snapshot' => [
                'reservation_code' => $reservation->reservation_code,
                'amount' => $reservation->total_amount,
                'currency' => $reservation->currency,
                'aircraft_id' => $reservation->aircraft_id,
                'provider_id' => $reservation->provider_id,
                'conditions' => [
                    'Pago requerido antes de confirmacion final.',
                    'Operacion sujeta a condiciones de seguridad y slot.',
                    'Cualquier cambio relevante queda registrado en historial operativo.',
                ],
            ],
        ];

        if ($existing) {
            $existing->update($payload);
            return $existing;
        }

        return $reservation->contract()->create($payload);
    }

    private function buildContractPdfPayload(Reserva $reservation, ContratoReserva $contract): array
    {
        $snapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];
        $clientSnapshot = is_array($snapshot['client_contract_snapshot'] ?? null)
            ? $snapshot['client_contract_snapshot']
            : [];
        $conditions = array_values(array_filter(
            $snapshot['conditions'] ?? [],
            fn ($value) => is_string($value) && trim($value) !== ''
        ));
        $segments = $this->buildPdfItinerarySegments($reservation, $clientSnapshot);
        $route = $this->buildPdfRouteLabel($segments, $clientSnapshot['route'] ?? '');
        $finalPriceValue = $this->parsePdfMoney($clientSnapshot['final_price'] ?? $snapshot['amount'] ?? $reservation->total_amount);
        $depositValue = $this->parsePdfMoney($clientSnapshot['deposit_amount'] ?? null);
        if ($depositValue <= 0 && $finalPriceValue > 0) {
            $depositValue = $finalPriceValue * 0.5;
        }

        $pricingRows = [
            ['label' => 'Costo total del servicio', 'amount' => $finalPriceValue],
            ['label' => 'Depósito requerido', 'amount' => $depositValue],
            ['label' => 'Saldo estimado', 'amount' => max($finalPriceValue - $depositValue, 0)],
        ];

        return [
            'contract' => $contract,
            'reservation' => $reservation->loadMissing(['client', 'provider', 'aircraft', 'legs', 'flightRequest']),
            'snapshot' => $snapshot,
            'clientSnapshot' => $clientSnapshot,
            'conditions' => $conditions,
            'segments' => $segments,
            'route' => $route,
            'departureDate' => $clientSnapshot['departure_date'] ?? optional($reservation->flightRequest)->departure_datetime?->format('d/m/Y H:i') ?? 'Pendiente',
            'aircraft' => $clientSnapshot['aircraft'] ?? optional($reservation->aircraft)->model ?? 'Por definir',
            'aircraftCategory' => $clientSnapshot['aircraft_category'] ?? optional($reservation->aircraft)->category ?? 'Por definir',
            'passengers' => $clientSnapshot['passengers'] ?? ($reservation->flightRequest?->passengers ? $reservation->flightRequest->passengers.' pasajeros' : 'Por definir'),
            'customerName' => $clientSnapshot['customer_name'] ?? optional($reservation->client)->name ?? 'Cliente',
            'customerRepresentative' => $clientSnapshot['customer_representative'] ?? optional($reservation->client)->name ?? 'Cliente',
            'customerAddress' => $clientSnapshot['customer_address'] ?? 'Domicilio por confirmar',
            'serviceTier' => $clientSnapshot['service_tier'] ?? 'Servicio ejecutivo privado',
            'operator' => $clientSnapshot['operator'] ?? optional($reservation->provider)->commercial_name ?? optional($reservation->provider)->company_name ?? 'Operador por confirmar',
            'contractDate' => $clientSnapshot['contract_date'] ?? optional($contract->signed_at ?: $contract->generated_at)->format('d/m/Y') ?? now()->format('d/m/Y'),
            'finalPrice' => $this->formatPdfMoney($finalPriceValue),
            'depositText' => $depositValue > 0 ? $this->formatPdfMoney($depositValue).' (50% del costo total)' : 'Depósito por definir',
            'balanceText' => $this->formatPdfMoney(max($finalPriceValue - $depositValue, 0)),
            'pricingRows' => $pricingRows,
            'logoPath' => public_path('logo.png'),
            'providerSignaturePath' => public_path('AUTOGRAFO/AUTOGRAFO JEFE.png'),
            'clientSignatureDataUrl' => data_get($snapshot, 'client_signature.data_url', ''),
            'bankAccounts' => [
                [
                    'bank' => 'BANBAJÍO',
                    'account' => '046 76313 20201',
                    'clabe' => '0304 209000 4337 2636',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BANREGIO',
                    'account' => '247 96234 0011',
                    'clabe' => '05842 0000 150761410',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BBVA',
                    'account' => '0122 912627',
                    'clabe' => '01243 800122 9126272',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
            ],
            'includesItems' => [
                'Aeronave y tripulación asignada para la ruta contratada.',
                'Coordinación operativa y seguimiento comercial de SKY Group / Red Aviation.',
                'Combustible y operación contemplados en la cotización validada.',
                'Uso de aeronave conforme al itinerario confirmado en este Anexo A.',
            ],
            'excludesItems' => [
                'Catering especial no contemplado expresamente.',
                'Transporte terrestre, hospedaje o concierge fuera del alcance contratado.',
                'Cambios de itinerario solicitados por el Cliente después de la firma.',
                'Tiempos de espera extraordinarios, permisos especiales o costos por reprogramación.',
            ],
        ];
    }

    private function resolvePreferredContractHtml(array $data, array $termsSnapshot): string
    {
        $candidates = [
            $data['full_contract_html'] ?? null,
            $data['document_html'] ?? null,
            $data['contract_html'] ?? null,
            $data['contract_markup'] ?? null,
            $termsSnapshot['full_contract_html'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) ($candidate ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function resolvePreferredContractText(array $data, array $termsSnapshot): string
    {
        $candidates = [
            $data['full_contract_text'] ?? null,
            $data['contract_plain_text'] ?? null,
            $termsSnapshot['full_contract_text'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) ($candidate ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function buildPdfItinerarySegments(Reserva $reservation, array $clientSnapshot): array
    {
        $segments = [];
        $snapshotSegments = $clientSnapshot['itinerary_segments'] ?? [];
        if (is_array($snapshotSegments) && ! empty($snapshotSegments)) {
            foreach ($snapshotSegments as $index => $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                $segments[] = [
                    'order' => $segment['order'] ?? $index + 1,
                    'origin' => $segment['origin'] ?? 'Origen por confirmar',
                    'destination' => $segment['destination'] ?? 'Destino por confirmar',
                    'departure' => $segment['departure'] ?? '',
                ];
            }

            return $segments;
        }

        foreach ($reservation->legs as $index => $leg) {
            $segments[] = [
                'order' => $leg->leg_order ?? $index + 1,
                'origin' => $leg->origin ?? 'Origen por confirmar',
                'destination' => $leg->destination ?? 'Destino por confirmar',
                'departure' => (string) ($leg->departure_datetime ?? ''),
            ];
        }

        if ($segments) {
            return $segments;
        }

        return [[
            'order' => 1,
            'origin' => $reservation->flightRequest?->origin ?? 'Origen por confirmar',
            'destination' => $reservation->flightRequest?->destination ?? 'Destino por confirmar',
            'departure' => (string) ($reservation->flightRequest?->departure_datetime ?? ''),
        ]];
    }

    private function buildPdfRouteLabel(array $segments, string $fallback = ''): string
    {
        $path = [];
        foreach ($segments as $index => $segment) {
            $origin = trim((string) ($segment['origin'] ?? ''));
            $destination = trim((string) ($segment['destination'] ?? ''));
            if ($index === 0 && $origin !== '') {
                $path[] = $origin;
            }
            if ($destination !== '' && end($path) !== $destination) {
                $path[] = $destination;
            }
        }

        if (count($path) >= 2) {
            return implode(' → ', $path);
        }

        return trim($fallback) !== '' ? $fallback : 'Ruta por confirmar';
    }

    private function parsePdfMoney(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', trim((string) $value)) ?? '';
        if ($normalized === '') {
            return 0.0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace('.', '', $normalized)
                : str_replace(',', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function formatPdfMoney(float $amount): string
    {
        return '$'.number_format($amount, 2, '.', ',').' USD';
    }
}
