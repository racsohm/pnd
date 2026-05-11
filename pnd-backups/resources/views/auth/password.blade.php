@extends('layouts.app')

@section('content')
<a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:underline">← Instancias</a>

<div class="max-w-md mt-4">
  <h1 class="text-2xl font-semibold mb-1">Cambiar contraseña</h1>
  <p class="text-sm text-slate-500 mb-5">
    Cambiás la contraseña de tu propio usuario (<strong>{{ auth()->user()->email }}</strong>).
  </p>

  <form method="POST" action="{{ route('password.update') }}"
        class="rounded-lg border bg-white p-5 space-y-4">
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
      <p class="text-xs text-slate-500 mt-1">Mínimo 8 caracteres.</p>
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
        Guardar
      </button>
    </div>
  </form>
</div>
@endsection
