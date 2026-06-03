<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    /** Crear un nuevo dump (mongodump). */
    public function create(User $user, string $slug): bool
    {
        return $user->canGenerateBackupFor($slug);
    }

    /** Descargar un archivo de respaldo. */
    public function download(User $user, Backup $backup): bool
    {
        return $user->canSeeInstance($backup->instance_slug);
    }

    /** Restaurar un respaldo — solo super_admin. */
    public function restore(User $user, Backup $backup): bool
    {
        return $user->isSuperAdmin();
    }

    /** Eliminar un respaldo — solo super_admin. */
    public function destroy(User $user, Backup $backup): bool
    {
        return $user->isSuperAdmin();
    }
}
