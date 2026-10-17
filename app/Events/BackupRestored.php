<?php

namespace App\Events;

use App\Models\User;

/** Se acaba de restaurar un respaldo; $user es quien lo restauro (ya con su usuario dentro de los datos nuevos). */
class BackupRestored
{
    public function __construct(public User $user, public string $password, public array $manifest) {}
}
