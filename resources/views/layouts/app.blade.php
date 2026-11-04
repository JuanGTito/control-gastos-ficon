<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2046b5">
    <title>@yield('title', 'Control de gastos') · Gastos Ficon</title>
    <script src="{{ asset('js/theme.js') }}?v=1"></script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ficon.css') }}?v=1">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>
@auth
<div class="app-shell">
    <aside class="sidebar">
        <a href="{{ route('expenses.index') }}" class="brand ficon-brand" aria-label="Gastos Ficon"><img src="{{ asset('images/ficon-logo.png') }}" alt="FICON" width="190" height="55"><span>Gastos Ficon</span></a>
        <div class="nav-label">PRINCIPAL</div>
        <nav aria-label="Navegación principal">
            <a @class(['nav-item', 'is-active' => request()->routeIs('cash.*')]) href="{{ route('cash.index') }}"><span aria-hidden="true">▣</span> Caja chica</a>
            @if(auth()->user()->managesExpenses())
            <a @class(['nav-item', 'is-active' => request()->routeIs('catalogs.*')]) href="{{ route('catalogs.index') }}"><span aria-hidden="true">⚙</span> Configuración</a>
            @endif
            <a @class(['nav-item', 'is-active' => request()->routeIs('expenses.*')]) href="{{ route('expenses.index') }}"><span aria-hidden="true">▤</span> Mis gastos</a>
            @if(auth()->user()->canManageUsers())
            <a @class(['nav-item', 'is-active' => request()->routeIs('users.*')]) href="{{ route('users.index') }}"><span aria-hidden="true">♧</span> Usuarios</a>
            @endif
            @if(auth()->user()->isSuperadmin() || auth()->user()->role === 'accountant')
            <a @class(['nav-item', 'is-active' => request()->routeIs('audit.*')]) href="{{ route('audit.index') }}"><span aria-hidden="true">◷</span> Auditoría</a>
            @endif
        </nav>
        <div class="profile"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->username }}</small></div><form action="{{ route('logout') }}" method="post">@csrf<button class="logout" title="Cerrar sesión" aria-label="Cerrar sesión">↪</button></form></div>
    </aside>
    <div class="main-wrap">
        <header class="topbar"><strong>@yield('title', 'Mis gastos')</strong>@include('components.theme-toggle')</header>
        <main id="main" class="main-content">
            @if(session('status'))<div class="alert success" role="status">✓ {{ session('status') }}</div>@endif
            @if($errors->any())<div class="alert error" role="alert"><strong>Revisa los datos ingresados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
            <footer class="page-footer"><span>Gastos Ficon</span><span>{{ config('expenses.currency') }} · {{ now(config('expenses.timezone'))->format('Y') }}</span></footer>
        </main>
    </div>
</div>
@else
    @yield('content')
@endauth
@stack('dialogs')
</body>
</html>
