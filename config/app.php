<?php

// 1. Deteksi skema (http atau https)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// 2. Deteksi host (domain)
// Ini akan mengambil 'sinergi-api.test' atau 'api.sinergi.id' secara otomatis
$host = $_SERVER['HTTP_HOST'];

// 3. Bangun Base URL lengkap
$baseUrl = $scheme . '://' . $host;

return [
    'app_url' => $baseUrl,
    'storage_url' => $baseUrl . '/storage/',
    'allowed_email_domains' => [
        'stu.pnj.ac.id',
        'tik.pnj.ac.id'
    ]
];