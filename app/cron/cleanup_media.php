<?php

// ======================================================
// Cleanup Orphan Media Files
// Aman untuk Windows & Linux
// ======================================================

// Hanya boleh dijalankan via CLI
if (php_sapi_name() !== 'cli') {
    exit("Forbidden\n");
}

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use app\core\Database;
use app\helpers\FileHelper;

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

echo "[INFO] Media cleanup started\n";

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo "[ERROR] DB connection failed: {$e->getMessage()}\n";
    exit(1);
}

// ======================================================
// 1️⃣ Ambil SEMUA media path yang masih dipakai DB
// ======================================================

$sqls = [
    "SELECT path_to_media AS path FROM posts WHERE path_to_media IS NOT NULL",
    "SELECT path_to_media AS path FROM forums WHERE path_to_media IS NOT NULL",
    "SELECT path_to_media AS path FROM forums_responds WHERE path_to_media IS NOT NULL",
    "SELECT path_to_thumbnail AS path FROM communities WHERE path_to_thumbnail IS NOT NULL",
    "SELECT path_to_profile_picture AS path FROM users WHERE path_to_profile_picture IS NOT NULL"
];

$usedPaths = [];

foreach ($sqls as $sql) {
    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $path) {
        // Simpan sebagai set untuk lookup cepat
        $usedPaths[$path] = true;
    }
}

echo "[INFO] Total used media references: " . count($usedPaths) . "\n";

// ======================================================
// 2️⃣ Scan storage/app/public
// ======================================================

$storageRoot = BASE_PATH . '/storage/app/public';

// Normalisasi path root (Windows-safe)
$storageRootNormalized = str_replace('\\', '/', $storageRoot);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($storageRoot, RecursiveDirectoryIterator::SKIP_DOTS)
);

$checked = 0;
$deleted = 0;

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $checked++;

    // Path absolut → normalisasi
    $fullPath = str_replace('\\', '/', $file->getPathname());

    // Ambil path relatif (HARUS sama dengan DB)
    $relativePath = str_replace($storageRootNormalized . '/', '', $fullPath);

    // ==================================================
    // GUARD KEAMANAN
    // ==================================================

    // 1. Jangan hapus file di luar uploads/
    if (!str_starts_with($relativePath, 'uploads/')) {
        continue;
    }

    // 2. Skip file default penting
    if ($relativePath === 'uploads/profile_picture/unknown.png') {
        continue;
    }

    // ==================================================
    // 3️⃣ Hapus orphan media
    // ==================================================
    if (!isset($usedPaths[$relativePath])) {
        if (FileHelper::delete($relativePath)) {
            echo "[DELETED] {$relativePath}\n";
            $deleted++;
        } else {
            echo "[FAILED] {$relativePath}\n";
        }
    }
}

echo "[INFO] Media cleanup finished\n";
echo "[INFO] Files checked: {$checked}\n";
echo "[INFO] Files deleted: {$deleted}\n";

exit(0);
