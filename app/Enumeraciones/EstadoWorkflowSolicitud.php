<?php

namespace App\Enumeraciones;

enum EstadoWorkflowSolicitud: string
{
    case Pendiente = 'pendiente';
    case EnValidacion = 'en_validacion';
    case BuscandoOperador = 'buscando_operador';
    case OperadorAsignado = 'operador_asignado';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case SinOpcionesDisponibles = 'sin_opciones_disponibles';
    case Cotizada = 'cotizada';
    case ProviderPending = 'provider_pending';
    case ProviderAccepted = 'provider_accepted';
    case ContractPending = 'contract_pending';
    case ContractSigned = 'contract_signed';
    case PaymentPending = 'payment_pending';
    case PaymentConfirmed = 'payment_confirmed';
    case FlightConfirmed = 'flight_confirmed';
    case TrackingLive = 'tracking_live';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case ReservaSolicitada = 'reserva solicitada';
    case ProveedorPorConfirmar = 'proveedor por confirmar';
    case ProveedorAceptado = 'proveedor aceptado';
    case ContratoPendiente = 'contrato pendiente';
    case ContratoFirmado = 'contrato firmado';
    case PagoPendiente = 'pago pendiente';
    case PagoConfirmado = 'pago confirmado';
    case VueloConfirmado = 'vuelo confirmado';
    case TrackingEnVivo = 'tracking en vivo';
    case Finalizada = 'finalizada';
    case Cancelada = 'cancelada';
    case Expirada = 'expirada';
}
