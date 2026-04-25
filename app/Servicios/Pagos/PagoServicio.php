<?php

namespace App\Servicios\Pagos;

use App\Modelos\Pago;

class PagoServicio
{
    public function markPaid(Pago $payment): Pago
    {
        $payment->update(['status' => 'paid']);

        return $payment;
    }
}
