<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\InstanceDiscovery;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __invoke(InstanceDiscovery $discovery)
    {
        $user = Auth::user();
        $instances = $discovery->all();

        // Si no es admin, restringe a su instancia
        if (! $user->isAdmin()) {
            $instances = array_values(array_filter(
                $instances,
                fn ($i) => $i['slug'] === $user->instance_slug
            ));
        }

        // Conteo y último respaldo por instancia
        $stats = [];
        foreach ($instances as $i) {
            $latest = Backup::where('instance_slug', $i['slug'])
                ->orderByDesc('created_at')
                ->first();
            $count = Backup::where('instance_slug', $i['slug'])->count();
            $stats[$i['slug']] = [
                'count'  => $count,
                'latest' => $latest,
            ];
        }

        return view('dashboard', compact('instances', 'stats'));
    }
}
