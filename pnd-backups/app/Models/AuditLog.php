<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'user_email',
        'action', 'instance_slug',
        'target_type', 'target_id', 'target_name',
        'details', 'ip_address',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'backup.create'            => 'Creó respaldo',
            'backup.upload'            => 'Subió respaldo',
            'backup.download'          => 'Descargó respaldo',
            'backup.restore'           => 'Restauró respaldo',
            'backup.delete'            => 'Eliminó respaldo',
            'user.update'              => 'Editó usuario',
            'user.password_reset'      => 'Cambió contraseña',
            'user.roles_update'        => 'Cambió roles',
            'declaracion.fecha_update' => 'Cambió fecha declaración',
            'declaracion.delete'       => 'Eliminó declaración',
            'report.excel'             => 'Descargó Excel',
            'report.zip'               => 'Descargó ZIP',
            'report.pdf'               => 'Descargó PDF',
            default                    => $action,
        };
    }

    public static function actionBadgeClass(string $action): string
    {
        if (str_ends_with($action, '.delete') || str_ends_with($action, '.restore')) {
            return 'bg-rose-100 text-rose-700';
        }
        if (str_ends_with($action, '.upload')) {
            return 'bg-amber-100 text-amber-700';
        }
        if (str_ends_with($action, '.download') || str_ends_with($action, '.excel') || str_ends_with($action, '.zip') || str_ends_with($action, '.pdf')) {
            return 'bg-blue-100 text-blue-700';
        }
        if (str_ends_with($action, '.create') || str_ends_with($action, '.update') || str_ends_with($action, '.roles_update') || str_ends_with($action, '.fecha_update') || str_ends_with($action, '.password_reset')) {
            return 'bg-emerald-100 text-emerald-700';
        }
        return 'bg-slate-100 text-slate-700';
    }
}
