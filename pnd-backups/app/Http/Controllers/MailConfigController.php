<?php

namespace App\Http\Controllers;

use App\Services\InstanceDiscovery;
use App\Services\InstanceRebuildService;
use App\Services\MailConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MailConfigController extends Controller
{
    public function __construct(
        private InstanceDiscovery $discovery,
        private MailConfigService $mailConfig,
        private InstanceRebuildService $rebuild,
    ) {}

    private function authorizeInstance(string $slug): array
    {
        $inst = $this->discovery->find($slug);
        if (! $inst) {
            throw new NotFoundHttpException("Instancia '$slug' no encontrada.");
        }
        if (! Auth::user()->canSeeInstance($slug)) {
            abort(403);
        }
        return $inst;
    }

    public function edit(string $slug)
    {
        $instance = $this->authorizeInstance($slug);

        try {
            $fields = $this->mailConfig->read($slug);
            $error  = null;
        } catch (\Throwable $e) {
            $fields = [
                'use_smtp'             => false,
                'smtp_host'            => '',
                'smtp_port'            => '587',
                'smtp_secure'          => false,
                'smtp_user'            => '',
                'smtp_password'        => '',
                'smtp_from_email'      => '',
                'sendgrid_api_key'     => '',
                'sendgrid_mail_sender' => '',
            ];
            $error = $e->getMessage();
        }

        $running = $this->rebuild->isRunning($slug);
        $log     = $this->rebuild->tailLog($slug, 400);

        return view('instances.mail', compact('instance', 'fields', 'error', 'running', 'log'));
    }

    public function update(Request $request, string $slug)
    {
        $this->authorizeInstance($slug);

        $data = $request->validate([
            'use_smtp'             => ['nullable'],
            'smtp_host'            => ['nullable', 'string', 'max:255'],
            'smtp_port'            => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_secure'          => ['nullable'],
            'smtp_user'            => ['nullable', 'string', 'max:255'],
            'smtp_password'        => ['nullable', 'string', 'max:255'],
            'smtp_from_email'      => ['nullable', 'string', 'max:255'],
            'sendgrid_api_key'     => ['nullable', 'string', 'max:255'],
            'sendgrid_mail_sender' => ['nullable', 'string', 'max:255'],
            'rebuild'              => ['nullable'],
        ]);

        $fields = [
            'use_smtp'             => $request->boolean('use_smtp'),
            'smtp_host'            => trim($data['smtp_host'] ?? ''),
            'smtp_port'            => (string) ($data['smtp_port'] ?? '587'),
            'smtp_secure'          => $request->boolean('smtp_secure'),
            'smtp_user'            => trim($data['smtp_user'] ?? ''),
            'smtp_password'        => $data['smtp_password'] ?? '',
            'smtp_from_email'      => trim($data['smtp_from_email'] ?? ''),
            'sendgrid_api_key'     => trim($data['sendgrid_api_key'] ?? ''),
            'sendgrid_mail_sender' => trim($data['sendgrid_mail_sender'] ?? ''),
        ];

        try {
            $this->mailConfig->write($slug, $fields);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'No se pudo guardar: '.$e->getMessage());
        }

        $okMsg = 'Configuración de correo guardada.';

        if ($request->boolean('rebuild')) {
            try {
                $this->rebuild->start($slug);
                $okMsg .= ' Rebuild lanzado — revisá el log abajo.';
            } catch (\Throwable $e) {
                return redirect()->route('instances.mail', $slug)
                    ->with('error', 'Guardado, pero el rebuild falló al iniciar: '.$e->getMessage());
            }
        }

        return redirect()->route('instances.mail', $slug)->with('ok', $okMsg);
    }
}
