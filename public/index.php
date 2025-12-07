<?php
// public/index.php
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

$frontendUrl = $_ENV['FRONTEND_URL'];

// Ganti header CORS untuk mengizinkan kredensial (cookie)
header("Access-Control-Allow-Origin: ". $frontendUrl); // Ganti dengan alamat frontend Vue Anda
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

spl_autoload_register(function ($class) {
    $path = BASE_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});


// === INISIALISASI SESI KUSTOM ===
// 1. Ambil koneksi database
$dbConnection = app\core\Database::getInstance()->getConnection();

// 2. Buat instance session handler kita
$sessionHandler = new app\core\DatabaseSessionHandler($dbConnection);

// 3. Atur PHP untuk menggunakan handler kita
session_set_save_handler($sessionHandler, true);

// 4. Atur beberapa parameter cookie untuk keamanan
session_set_cookie_params([
    'lifetime' => 86400, // 1 jam
    'path' => '/',
    // 'domain' => 'sinergi-api.test', // Gunakan nama domain Anda
    'secure' => true,    // <-- BENAR (Wajib 'true' untuk HTTPS)
    'httponly' => true,  // Mencegah cookie diakses JS
    'samesite' => 'None' // <-- BENAR (Wajib 'None' untuk lintas-domain)
]);

// 5. Mulai sesi
session_start();
// ===============================

require_once BASE_PATH . '/routes/api.php';

$router = new app\core\Router();
register_routes($router);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$router->dispatch($uri, $method);