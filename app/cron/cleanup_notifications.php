<?php

if (php_sapi_name() !== 'cli') {
    exit("Forbidden\n");
}

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use app\core\Database;

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo "[ERROR] Gagal koneksi database: {$e->getMessage()}\n";
    exit(1);
}

$days = 7;
$threshold = (new DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

$sql = "DELETE FROM notifications WHERE created_at < :threshold";

try {
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':threshold', $threshold);
    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "[INFO] Cleanup notifikasi selesai\n";
    echo "[INFO] Total dihapus: {$deleted}\n";
    echo "[INFO] Batas waktu: {$threshold}\n";
} catch (Throwable $e) {
    echo "[ERROR] Gagal cleanup notifikasi: {$e->getMessage()}\n";
    exit(1);
}

exit(0);
