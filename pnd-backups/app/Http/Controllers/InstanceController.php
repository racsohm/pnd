<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\InstanceDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstanceController extends Controller
{
    public function show(Request $request, InstanceDiscovery $discovery, string $slug)
    {
        $instance = $discovery->find($slug);
        if (! $instance) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }

        $user = Auth::user();
        if (! $user->canSeeInstance($slug)) {
            abort(403);
        }

        $backups = Backup::where('instance_slug', $slug)
            ->orderByDesc('created_at')
            ->get();

        return view('instances.show', compact('instance', 'backups'));
    }
}
