<?php

return [
    'aircraft_hold_minutes' => (int) env('AIRCRAFT_HOLD_MINUTES', 15),
    'contract_hold_minutes' => (int) env('CONTRACT_HOLD_MINUTES', 720),
    'aircraft_preparation_minutes' => (int) env('AIRCRAFT_PREPARATION_MINUTES', 30),
    'aircraft_operational_margin_minutes' => (int) env('AIRCRAFT_OPERATIONAL_MARGIN_MINUTES', 30),
    'aircraft_reposition_padding_minutes' => (int) env('AIRCRAFT_REPOSITION_PADDING_MINUTES', 30),
    'aircraft_default_flight_duration_hours' => (int) env('AIRCRAFT_DEFAULT_FLIGHT_DURATION_HOURS', 4),
];
