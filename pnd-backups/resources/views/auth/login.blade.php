@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto mt-10 bg-white rounded-lg shadow-sm border p-6">
  <h1 class="text-xl font-semibold mb-1">Iniciar sesión</h1>
  <p class="text-sm text-slate-500 mb-5">Panel de respaldos PND</p>

  <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
    @csrf
    <div>
      <label class="block text-sm font-medium mb-1">Usuario</label>
      <input name="email" type="text" required autofocus autocomplete="username" value="{{ old('email') }}"
             class="w-full rounded border-slate-300 focus:border-slate-500 focus:ring-slate-500" />
      @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Contraseña</label>
      <input name="password" type="password" required
             class="w-full rounded border-slate-300 focus:border-slate-500 focus:ring-slate-500" />
      @error('password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <label class="flex items-center gap-2 text-sm">
      <input type="checkbox" name="remember" class="rounded border-slate-300" />
      Recordarme
    </label>
    <button class="w-full rounded bg-slate-900 text-white py-2 text-sm font-medium hover:bg-slate-800">
      Entrar
    </button>
  </form>
</div>
@endsection
