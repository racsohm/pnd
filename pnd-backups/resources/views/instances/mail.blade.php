@extends('layouts.app')

@section('content')
<a href="{{ route('instances.show', $instance['slug']) }}" class="text-sm text-slate-500 hover:underline">← {{ $instance['name'] }}</a>

<h1 class="text-2xl font-semibold mt-2 mb-1">Servidor de correo</h1>
<p class="text-sm text-slate-500 mb-5">
  Modifica los parámetros SMTP de la instancia. Se escriben directamente en
  <code class="text-xs">SistemaDeclaraciones_backend/.env</code>.
  Para que los cambios tomen efecto es necesario reconstruir el backend.
</p>

@if (session('ok'))
  <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-emerald-800 text-sm">
    {{ session('ok') }}
  </div>
@endif

@if ($error)
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    {{ $error }}
  </div>
@endif

@if ($errors->any())
  <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
    <ul class="list-disc list-inside">
      @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
    </ul>
  </div>
@endif

<form method="POST" action="{{ route('instances.mail.update', $instance['slug']) }}"
      class="rounded-lg border bg-white p-5 mb-6 space-y-5">
  @csrf @method('PUT')

  {{-- Activar / desactivar SMTP --}}
  <label class="flex items-start gap-2 text-sm">
    <input type="checkbox" name="use_smtp" value="1"
           {{ old('use_smtp', $fields['use_smtp']) ? 'checked' : '' }}
           class="mt-0.5 rounded">
    <span><strong>Habilitar envío de correo vía SMTP</strong></span>
  </label>

  {{-- Servidor y puerto --}}
  <div class="grid gap-4 md:grid-cols-3">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Servidor SMTP <span class="text-slate-400 font-normal">(SMTP_HOST)</span></label>
      <input type="text" name="smtp_host" maxlength="255"
             value="{{ old('smtp_host', $fields['smtp_host']) }}"
             placeholder="mail.ejemplo.com"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Puerto <span class="text-slate-400 font-normal">(SMTP_PORT)</span></label>
      <input type="number" name="smtp_port" min="1" max="65535" required
             value="{{ old('smtp_port', $fields['smtp_port']) }}"
             class="w-full rounded border-slate-300 text-sm" />
    </div>
  </div>

  {{-- TLS/SSL --}}
  <label class="flex items-start gap-2 text-sm">
    <input type="checkbox" name="smtp_secure" value="1"
           {{ old('smtp_secure', $fields['smtp_secure']) ? 'checked' : '' }}
           class="mt-0.5 rounded">
    <span>
      <strong>Conexión segura (SMTP_SECURE)</strong>
      <br><span class="text-xs text-slate-500">Actívalo si usás puerto 465 (SSL/TLS directo). Para STARTTLS en puerto 587 déjalo desactivado.</span>
    </span>
  </label>

  {{-- Usuario y contraseña --}}
  <div class="pt-2 border-t">
    <p class="text-sm font-semibold mb-3 text-slate-700">Credenciales de autenticación</p>
    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium mb-1">Usuario SMTP <span class="text-slate-400 font-normal">(SMTP_USER)</span></label>
        <input type="text" name="smtp_user" maxlength="255"
               value="{{ old('smtp_user', $fields['smtp_user']) }}"
               placeholder="usuario@servidor.com"
               class="w-full rounded border-slate-300 text-sm" />
      </div>
      <div x-data="{ show: false }">
        <label class="block text-sm font-medium mb-1">Contraseña <span class="text-slate-400 font-normal">(SMTP_PASSWORD)</span></label>
        <div class="relative">
          <input :type="show ? 'text' : 'password'" name="smtp_password" maxlength="255"
                 value="{{ old('smtp_password', $fields['smtp_password']) }}"
                 class="w-full rounded border-slate-300 text-sm pr-16" />
          <button type="button" @click="show = !show"
                  class="absolute inset-y-0 right-0 px-3 text-xs text-slate-500 hover:text-slate-800"
                  x-text="show ? 'Ocultar' : 'Ver'"></button>
        </div>
      </div>
    </div>
  </div>

  {{-- Correo remitente --}}
  <div>
    <label class="block text-sm font-medium mb-1">Correo remitente <span class="text-slate-400 font-normal">(SMTP_FROM_EMAIL)</span></label>
    <input type="text" name="smtp_from_email" maxlength="255"
           value="{{ old('smtp_from_email', $fields['smtp_from_email']) }}"
           placeholder="noreply@institución.gob.mx"
           class="w-full rounded border-slate-300 text-sm" />
    <p class="text-xs text-slate-400 mt-1">Dirección que aparece en el campo "De:" de los correos enviados.</p>
  </div>

  {{-- SendGrid (opcional) --}}
  <details class="border rounded-md">
    <summary class="px-4 py-2 text-sm font-medium cursor-pointer select-none text-slate-600 hover:bg-slate-50">
      Configuración SendGrid (opcional)
    </summary>
    <div class="px-4 pb-4 pt-3 space-y-4">
      <p class="text-xs text-slate-500">
        Solo si la instancia usa SendGrid en lugar de SMTP directo.
        Si ambos están configurados, el backend usa SMTP cuando <code>USE_SMTP=true</code>.
      </p>
      <div>
        <label class="block text-sm font-medium mb-1">API Key <span class="text-slate-400 font-normal">(SENDGRID_API_KEY)</span></label>
        <input type="text" name="sendgrid_api_key" maxlength="255"
               value="{{ old('sendgrid_api_key', $fields['sendgrid_api_key']) }}"
               placeholder="SG.xxxxx"
               class="w-full rounded border-slate-300 text-sm font-mono" />
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Remitente SendGrid <span class="text-slate-400 font-normal">(SENDGRID_MAIL_SENDER)</span></label>
        <input type="text" name="sendgrid_mail_sender" maxlength="255"
               value="{{ old('sendgrid_mail_sender', $fields['sendgrid_mail_sender']) }}"
               placeholder="noreply@institución.gob.mx"
               class="w-full rounded border-slate-300 text-sm" />
      </div>
    </div>
  </details>

  {{-- Opción de rebuild --}}
  <label class="flex items-start gap-2 text-sm pt-2 border-t">
    <input type="checkbox" name="rebuild" value="1" checked class="mt-1 rounded">
    <span>
      <strong>Reconstruir y reiniciar el backend</strong> con estos cambios.
      <br><span class="text-xs text-slate-500">
        Equivale a <code>docker compose up --build -d app</code> en el directorio de la instancia.
        El frontend sigue sirviendo durante el rebuild (puede tardar varios minutos).
      </span>
    </span>
  </label>

  <div class="pt-2">
    <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
      Guardar cambios
    </button>
  </div>
</form>

<form method="POST" action="{{ route('instances.rebuild', $instance['slug']) }}"
      class="mb-6 flex items-center gap-3"
      onsubmit="return confirm('¿Reconstruir el backend sin cambios en la configuración?')">
  @csrf
  <button class="rounded border px-3 py-2 text-sm hover:bg-slate-50" type="submit">
    Solo reconstruir
  </button>
  <span class="text-xs text-slate-500">
    Útil si ya editaste el .env a mano o cambiaste algo en el backend.
  </span>
</form>

<div class="rounded-lg border bg-white">
  <div class="px-4 py-3 border-b flex items-center justify-between">
    <span class="font-semibold">Log de rebuild</span>
    <span id="rebuild-status" class="text-xs">
      @if ($running)
        <span class="inline-flex items-center gap-1 text-amber-700">
          <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> en curso…
        </span>
      @elseif ($log !== '')
        <span class="text-slate-500">última corrida</span>
      @else
        <span class="text-slate-400">sin actividad</span>
      @endif
    </span>
  </div>
  <pre id="rebuild-log"
       class="m-0 p-4 text-xs leading-relaxed text-slate-700 bg-slate-50 max-h-96 overflow-auto whitespace-pre-wrap">{{ $log !== '' ? $log : 'No hay rebuilds previos.' }}</pre>
</div>

<script>
(function () {
  const url = @json(route('instances.rebuild.log', $instance['slug']));
  const initiallyRunning = @json($running);
  const pre = document.getElementById('rebuild-log');
  const status = document.getElementById('rebuild-status');
  let timer = null;

  function setRunning(running) {
    if (running) {
      status.innerHTML = '<span class="inline-flex items-center gap-1 text-amber-700"><span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> en curso…</span>';
    } else {
      status.innerHTML = '<span class="text-emerald-700">terminado</span>';
    }
  }

  async function tick() {
    try {
      const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (! r.ok) return;
      const data = await r.json();
      if (data.log && data.log !== pre.textContent) {
        const atBottom = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
        pre.textContent = data.log;
        if (atBottom) pre.scrollTop = pre.scrollHeight;
      }
      if (! data.running) {
        setRunning(false);
        clearInterval(timer);
      }
    } catch (e) { /* ignorar errores de red intermitentes */ }
  }

  if (initiallyRunning) {
    pre.scrollTop = pre.scrollHeight;
    timer = setInterval(tick, 3000);
  }
})();
</script>
@endsection
