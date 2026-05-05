<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'providers';

    use HasFactory;

    protected $fillable = ['user_id', 'company_name', 'commercial_name', 'approval_status', 'notes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(Usuario::class, 'provider_id');
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aeronave::class, 'provider_id');
    }

    public function companyDocuments(): HasMany
    {
        return $this->hasMany(DocumentoEmpresa::class, 'provider_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'provider_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reserva::class, 'provider_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Comision::class, 'provider_id');
    }
}
