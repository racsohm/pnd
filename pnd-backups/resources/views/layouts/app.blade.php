<!doctype html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ config('app.name') }}</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-800 antialiased">
  <header class="border-b bg-white">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight">
        {{ config('app.name') }}
      </a>
      @auth
        <div class="flex items-center gap-3">
          <a href="{{ route('password.edit') }}" class="text-sm text-slate-700 hover:underline">
            {{ auth()->user()->email }}
          </a>
          <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button class="text-sm text-slate-700 hover:underline">Salir</button>
          </form>
        </div>
      @endauth
    </div>
  </header>

  <main class="flex-1 w-full max-w-6xl mx-auto px-4 py-6">
    @if (session('ok'))
      <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-emerald-800 text-sm">
        {{ session('ok') }}
      </div>
    @endif
    @if (session('error'))
      <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
        {{ session('error') }}
      </div>
    @endif
    @yield('content')
  </main>

  <footer class="border-t bg-white">
    <div class="max-w-6xl mx-auto px-4 py-4 text-center text-xs text-slate-500">
      Hecho con <span class="text-rose-500" aria-hidden="true">♥</span>
      <span class="sr-only">amor</span>
      por
      <a href="https://dataismo.mx" target="_blank" rel="noopener"
         class="text-slate-700 hover:underline">dataismo.mx</a>
    </div>
  </footer>
</body>
</html>
