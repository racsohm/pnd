<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\InstanceDiscovery;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private InstanceDiscovery $discovery) {}

    public function index(Request $request)
    {
        if (! auth()->user()->isSuperAdmin()) abort(403);

        $query = AuditLog::with('user')->latest();

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('instance')) {
            $query->where('instance_slug', $request->input('instance'));
        }
        if ($request->filled('user')) {
            $query->where('user_email', 'like', '%'.$request->input('user').'%');
        }

        $logs      = $query->paginate(25)->withQueryString();
        $instances = $this->discovery->all();
        $actions   = AuditLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('audit.index', compact('logs', 'instances', 'actions'));
    }
}
