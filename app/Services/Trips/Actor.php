<?php

namespace App\Services\Trips;

use App\Models\ClientUser;
use App\Models\Driver;
use App\Models\User;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Actor (who is doing this?)
//  Location: app/Services/Trips/Actor.php
//
//  Every money movement and trip moment records WHO did it. The same
//  rules are used by the office (a User), the Driver App (a Driver,
//  Step 4) and the demo seeder (the system), so the services take an
//  Actor instead of reading "the signed-in person" themselves:
//      Actor::user($request->user('web'))
//      Actor::driver($driver)
//      Actor::system()
// ══════════════════════════════════════════════════════════════════

final class Actor
{
    private function __construct(
        public readonly string $type,      // user | driver | client | system
        public readonly ?int $id,
        public readonly string $name,
        public readonly ?User $user = null,
        public readonly ?Driver $driver = null,
    ) {}

    public static function user(User $user): self
    {
        return new self('user', $user->id, $user->name, user: $user);
    }

    public static function driver(Driver $driver): self
    {
        return new self('driver', $driver->id, $driver->name, driver: $driver);
    }

    /** A client-portal user (two-sided cash, Scope §9). */
    public static function client(ClientUser $client): self
    {
        return new self('client', $client->id, $client->name);
    }

    public static function system(): self
    {
        return new self('system', null, 'System');
    }

    public function isDriver(): bool
    {
        return $this->type === 'driver';
    }

    /** The office user id, for created_by / decided_by columns. */
    public function userId(): ?int
    {
        return $this->user?->id;
    }

    /** Columns for tables that record who acted (ledger, events). */
    public function columns(): array
    {
        return ['actor_type' => $this->type, 'actor_id' => $this->id, 'actor_name' => mb_substr($this->name, 0, 120)];
    }
}
