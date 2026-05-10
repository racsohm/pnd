<!doctype html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ config('app.name') }}</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
  <header class="border-b bg-white">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight">
        {{ config('app.name') }}
      </a>
      @auth
        <form action="{{ route('logout') }}" method="POST" class="flex items-center gap-3">
          @csrf
          <span class="text-sm text-slate-500">{{ auth()->user()->email }}</span>
          <button class="text-sm text-slate-700 hover:underline">Salir</button>
        </form>
      @endauth
    </div>
  </header>

  <main class="max-w-6xl mx-auto px-4 py-6">
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
</body>
</html>
