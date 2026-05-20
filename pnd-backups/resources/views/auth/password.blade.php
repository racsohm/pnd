@extends('layouts.app')

@section('content')
<a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:underline">← Instancias</a>

<div class="max-w-md mt-4 space-y-6">
  <div>
    <h1 class="text-2xl font-semibold mb-1">Mi cuenta</h1>
    <p class="text-sm text-slate-500">
      Usuario activo: <strong>{{ auth()->user()->email }}</strong>
    </p>
  </div>

  {{-- Cambiar correo electrónico --}}
  <div class="rounded-lg border bg-white p-5">
    <h2 class="font-semibold mb-1">Cambiar correo electrónico</h2>
    <p class="text-xs text-slate-500 mb-4">
      El correo es tu usuario de acceso. Se requiere la contraseña actual para confirmar el cambio.
    </p>

    <form method="POST" action="{{ route('email.update') }}" class="space-y-4">
      @csrf
      @method('PUT')

      <div>
        <label class="block text-sm font-medium mb-1">Nuevo correo electrónico</label>
        <input type="email" name="email" required maxlength="254"
               value="{{ old('email', auth()->user()->email) }}"
               autocomplete="email"
               class="w-full rounded border-slate-300 text-sm" />
        @error('email')
          <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Contraseña actual (confirmación)</label>
        <input type="password" name="current_password" required autocomplete="current-password"
               class="w-full rounded border-slate-300 text-sm" />
        @error('current_password')
          <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div class="flex justify-end">
        <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
          Actualizar correo
        </button>
      </div>
    </form>
  </div>

  {{-- Cambiar contraseña --}}
  <div class="rounded-lg border bg-white p-5">
    <h2 class="font-semibold mb-1">Cambiar contraseña</h2>
    <p class="text-xs text-slate-500 mb-4">Mínimo 8 caracteres.</p>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
      @csrf
      @method('PUT')

      <div>
        <label class="block text-sm font-medium mb-1">Contraseña actual</label>
        <input type="password" name="current_password" required autocomplete="current-password"
               class="w-full rounded border-slate-300 text-sm" />
        @error('current_password')
          <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Nueva contraseña</label>
        <input type="password" name="password" required autocomplete="new-password"
               class="w-full rounded border-slate-300 text-sm" />
        @error('password')
          <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Confirmar nueva contraseña</label>
        <input type="password" name="password_confirmation" required autocomplete="new-password"
               class="w-full rounded border-slate-300 text-sm" />
      </div>

      <div class="flex justify-end">
        <button class="rounded bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800">
          Cambiar contraseña
        </button>
      </div>
    </form>
  </div>
</div>
@endsection
