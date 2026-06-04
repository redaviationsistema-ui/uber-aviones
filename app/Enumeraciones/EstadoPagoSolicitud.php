<?php

namespace App\Enumeraciones;

enum EstadoPagoSolicitud: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case BankConfirmed = 'bank_confirmed';
    case PendingBankConfirmation = 'pending_bank_confirmation';
}
