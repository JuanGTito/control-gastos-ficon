<?php

return [
    'currency' => env('EXPENSE_CURRENCY', 'PEN'),
    'timezone' => env('EXPENSE_TIMEZONE', 'America/Bogota'),
    'categories' => ['Alimentación', 'Transporte', 'Servicios', 'Compras', 'Salud', 'Educación', 'Otros'],
    'export_limit' => 5000,
    'pdf_export_limit' => 500,
    'company' => env('REPORT_COMPANY', 'FICON SAC'),
    'template_path' => storage_path('app/templates/caja-chica.xlsx'),
];
