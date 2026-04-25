<?php

namespace App\Enumeraciones;

enum EstadoProveedor: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
