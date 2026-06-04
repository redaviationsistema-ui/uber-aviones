<?php

use App\Enumeraciones\EstadoAeronave;
use App\Enumeraciones\EstadoCotizacion;
use App\Enumeraciones\EstadoDisponibilidad;
use App\Enumeraciones\EstadoPago;
use App\Enumeraciones\EstadoPagoSolicitud;
use App\Enumeraciones\EstadoProveedor;
use App\Enumeraciones\EstadoReserva;
use App\Enumeraciones\EstadoSolicitudVuelo;
use App\Enumeraciones\EstadoWorkflowSolicitud;
use App\Enumeraciones\RolUsuario;

return [
    'roles' => array_map(static fn (RolUsuario $item) => $item->value, RolUsuario::cases()),
    'provider_statuses' => array_map(static fn (EstadoProveedor $item) => $item->value, EstadoProveedor::cases()),
    'aircraft_statuses' => array_map(static fn (EstadoAeronave $item) => $item->value, EstadoAeronave::cases()),
    'availability_statuses' => array_map(static fn (EstadoDisponibilidad $item) => $item->value, EstadoDisponibilidad::cases()),
    'flight_request_statuses' => array_map(static fn (EstadoSolicitudVuelo $item) => $item->value, EstadoSolicitudVuelo::cases()),
    'flight_request_workflow_statuses' => array_map(static fn (EstadoWorkflowSolicitud $item) => $item->value, EstadoWorkflowSolicitud::cases()),
    'flight_request_payment_statuses' => array_map(static fn (EstadoPagoSolicitud $item) => $item->value, EstadoPagoSolicitud::cases()),
    'quote_statuses' => array_map(static fn (EstadoCotizacion $item) => $item->value, EstadoCotizacion::cases()),
    'reservation_statuses' => array_map(static fn (EstadoReserva $item) => $item->value, EstadoReserva::cases()),
    'payment_statuses' => array_map(static fn (EstadoPago $item) => $item->value, EstadoPago::cases()),
];
