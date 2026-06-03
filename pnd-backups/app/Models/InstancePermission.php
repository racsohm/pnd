<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstancePermission extends Model
{
    protected $fillable = [
        'user_id',
        'instance_slug',
        'can_manage_users',
        'can_generate_backups',
        'can_view_stats',
        'can_download_reports',
    ];

    protected $casts = [
        'can_manage_users'      => 'boolean',
        'can_generate_backups'  => 'boolean',
        'can_view_stats'        => 'boolean',
        'can_download_reports'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
