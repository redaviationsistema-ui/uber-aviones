<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TokenApi extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = ['user_id', 'name', 'token', 'last_used_at', 'expires_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function issue(Usuario $user, string $name = 'api-token'): string
    {
        $plain = Str::random(80);

        static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDays(30),
        ]);

        return $plain;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
