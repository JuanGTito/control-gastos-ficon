<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Copiar este archivo a public_html/index.php. El código queda en una carpeta
// hermana llamada gastos-ficon, fuera de public_html.
$project = realpath(__DIR__.'/../gastos-ficon');

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
