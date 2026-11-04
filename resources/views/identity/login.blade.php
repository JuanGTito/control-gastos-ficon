@extends('layouts.app')
@section('title', 'Iniciar sesión')
@section('content')
<main class="login-shell">
    <section class="login-story">
        <div class="brand ficon-brand"><img src="{{ asset('images/ficon-logo.png') }}" alt="FICON" width="190" height="55"><span>Gastos Ficon</span></div>
        <div class="login-copy"><span class="eyebrow">GASTOS Y CAJA CHICA</span><h1>El control de gastos<br>de <em>Ficon.</em></h1><p>Administra tus fondos, registra comprobantes y consulta tus movimientos.</p><div class="login-art" aria-hidden="true"><span>↗</span><div></div><div></div><div></div><div></div><div></div></div></div>
        <small>Gastos Ficon</small>
    </section>
    <section class="login-panel">
        <div class="login-theme">@include('components.theme-toggle')</div>
        <div class="login-card"><span class="eyebrow">ACCESO A GASTOS FICON</span><h2>Qué bueno verte.</h2><p class="muted">Ingresa para poner tus cuentas al día.</p>
            @if($errors->any())<div class="alert error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login.store') }}" class="stack-form">@csrf
                <label>Usuario o correo electrónico<input name="login" value="{{ old('login') }}" autocomplete="username" required autofocus maxlength="255" placeholder="Tu usuario"></label>
                <label>Contraseña<input type="password" name="password" autocomplete="current-password" required maxlength="255" placeholder="Tu contraseña"></label>
                <button class="btn primary wide" type="submit">Iniciar sesión <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-help">¿Necesitas una cuenta o recuperar tu acceso?<br>Contacta al administrador.</p>
        </div>
    </section>
</main>
@endsection
