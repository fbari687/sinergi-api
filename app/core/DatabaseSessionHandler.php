<?php
namespace app\core;

use SessionHandlerInterface;

class DatabaseSessionHandler implements SessionHandlerInterface {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $query = "SELECT data FROM sessions WHERE session_id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $stmt->fetchColumn() ?: "";
    }

    public function write($id, $data): bool {
        $userId = $_SESSION['user_id'] ?? null;
        $currentTime = time();

        $query = "INSERT INTO sessions (session_id, user_id, last_activity, data) 
                    VALUES (:id, :user_id, :time, :data)
                    ON CONFLICT (session_id)
                    DO UPDATE SET user_id = :user_id, last_activity = :time, data = :data";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':time', $currentTime);
        $stmt->bindParam(':data', $data);

        return $stmt->execute();
    }

    public function destroy($id): bool {
        $query = "DELETE FROM sessions WHERE session_id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function gc($max_lifetime): int {
        $oldTime = time() - $max_lifetime;
        $query = "DELETE FROM sessions WHERE last_activity < :old_time";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':old_time', $oldTime);
        $stmt->execute();
        return $stmt->rowCount();
    }
}