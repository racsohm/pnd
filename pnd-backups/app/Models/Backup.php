<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $fillable = [
        'instance_slug', 'filename', 'size_bytes', 'source', 'created_by', 'notes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fullPath(): string
    {
        return rtrim((string) config('backups.path'), '/').'/'.$this->instance_slug.'/'.$this->filename;
    }

    public function exists(): bool
    {
        return is_file($this->fullPath());
    }

    public function humanSize(): string
    {
        $bytes = max(0, (int) $this->size_bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 2).' '.$units[$i];
    }
}
