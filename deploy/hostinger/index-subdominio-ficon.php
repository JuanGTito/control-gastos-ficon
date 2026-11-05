<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// gastos.ficon.pe apunta a public_html/gastos. En este alojamiento,
// public_html es un enlace a ficon-app/public. PHP puede resolver __DIR__
// por cualquiera de las dos rutas; ambas llevan a gastos-ficon.
$candidates = [
    __DIR__.'/../../../gastos-ficon',
    __DIR__.'/../../gastos-ficon',
];

$project = false;

foreach ($candidates as $candidate) {
    $resolved = realpath($candidate);

    if ($resolved !== false && is_file($resolved.'/bootstrap/app.php')) {
        $project = $resolved;
        break;
    }
}

if ($project === false) {
    http_response_code(503);
    exit('Aplicación no disponible.');
}

if (file_exists($maintenance = $project.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $project.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $project.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
