<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'instance_slug'];
    protected $hidden   = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Alias para compatibilidad con código existente. */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin();
    }

    public function isInstanceAdmin(): bool
    {
        return $this->role === 'instance_admin';
    }

    public function isInstanceViewer(): bool
    {
        return $this->role === 'instance_viewer';
    }

    // ── Permisos por instancia ─────────────────────────────────────────────────

    public function instancePermissions(): HasMany
    {
        return $this->hasMany(InstancePermission::class);
    }

    public function permissionsFor(string $slug): ?InstancePermission
    {
        return $this->instancePermissions()
            ->where('instance_slug', $slug)
            ->first();
    }

    public function canSeeInstance(string $slug): bool
    {
        return $this->isSuperAdmin() || $this->instance_slug === $slug;
    }

    public function canManageUsersFor(string $slug): bool
    {
        if ($this->isSuperAdmin()) return true;
        return (bool) $this->permissionsFor($slug)?->can_manage_users;
    }

    public function canGenerateBackupFor(string $slug): bool
    {
        if ($this->isSuperAdmin()) return true;
        return (bool) $this->permissionsFor($slug)?->can_generate_backups;
    }

    public function canViewStatsFor(string $slug): bool
    {
        if ($this->isSuperAdmin()) return true;
        return (bool) $this->permissionsFor($slug)?->can_view_stats;
    }

    public function canDownloadReportsFor(string $slug): bool
    {
        if ($this->isSuperAdmin()) return true;
        return (bool) $this->permissionsFor($slug)?->can_download_reports;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin'     => 'Super administrador',
            'instance_admin'  => 'Admin de instancia',
            'instance_viewer' => 'Lector',
            default           => $role,
        };
    }
}
