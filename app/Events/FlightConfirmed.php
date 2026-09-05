<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class FlightConfirmed implements ShouldBroadcastNow
{
    public function __construct(public int $providerId, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('provider.'.$this->providerId)];
    }

    public function broadcastAs(): string
    {
        return 'flight.confirmed';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
