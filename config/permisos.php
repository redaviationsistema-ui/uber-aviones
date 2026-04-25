<?php

return [
    'roles' => [
        'client' => ['search', 'quote.request', 'reservation.create', 'payment.create'],
        'provider' => ['aircraft.manage', 'availability.manage', 'quote.create'],
        'admin' => ['*'],
    ],
];
