<?php

// Hanya boleh dijalankan via CLI
if (php_sapi_name() !== 'cli') {
    exit("Forbidden\n");
}

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use app\core\Database;

echo "[INFO] Orphan report cleanup started\n";

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo "[ERROR] Database connection failed: {$e->getMessage()}\n";
    exit(1);
}

/**
 * Mapping reportable_type ke tabel target
 * USER sengaja tidak disertakan
 */
$targets = [
    'COMMUNITY'     => 'communities',
    'POST'          => 'posts',
    'FORUM'         => 'forums',
    'COMMENT'       => 'comments',
    'FORUM_RESPOND' => 'forums_responds',
];

$totalDeleted = 0;

foreach ($targets as $type => $table) {
    echo "[INFO] Checking orphan reports for {$type}...\n";

    /**
     * Hapus report yang target-nya sudah tidak ada
     */
    $sql = "
        DELETE FROM reports r
        WHERE r.reportable_type = :type
        AND NOT EXISTS (
            SELECT 1 FROM {$table} t
            WHERE t.id = r.reportable_id
        )
    ";

    try {
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':type', $type);
        $stmt->execute();

        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;

        echo "[INFO] {$type}: {$deleted} orphan report(s) deleted\n";
    } catch (Throwable $e) {
        echo "[ERROR] Failed cleaning {$type}: {$e->getMessage()}\n";
    }
}

echo "[INFO] Orphan report cleanup finished\n";
echo "[INFO] Total reports deleted: {$totalDeleted}\n";

exit(0);
