<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function __construct(private Request $request) {}

    /**
     * @param  string  $action   e.g. "backup.create"
     * @param  array   $context {
     *   instance_slug?: string,
     *   target_type?:   string,
     *   target_id?:     string,
     *   target_name?:   string,
     *   details?:       array,
     * }
     */
    public function log(string $action, array $context = []): void
    {
        try {
            $user = Auth::user();

            AuditLog::create([
                'user_id'       => $user?->id,
                'user_name'     => $user?->name,
                'user_email'    => $user?->email,
                'action'        => $action,
                'instance_slug' => $context['instance_slug'] ?? null,
                'target_type'   => $context['target_type']   ?? null,
                'target_id'     => $context['target_id']     ?? null,
                'target_name'   => $context['target_name']   ?? null,
                'details'       => $context['details'] ?? null,
                'ip_address'    => $this->request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditService failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
