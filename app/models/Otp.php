<?php
namespace app\models;

use app\core\Database;

class Otp {
    private $conn;
    private $table = 'otps';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($email, $otp): bool
    {
        // OTP berlaku selama 5 menit
        $expires_at = time() + (60 * 5);

        // Hapus OTP lama untuk email yang sama terlebih dahulu
        $this->deleteByEmail($email);

        $query = "INSERT INTO {$this->table} (email, otp_code, expires_at) VALUES (:email, :otp, :expires_at)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':otp', $otp);
        $stmt->bindParam(':expires_at', $expires_at);

        return $stmt->execute();
    }

    public function findByEmail($email) {
        $query = "SELECT * FROM {$this->table} WHERE email = :email";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        return $stmt->fetch();
    }

    public function deleteByEmail($email): bool
    {
        $query = "DELETE FROM {$this->table} WHERE email = :email";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        return $stmt->execute();
    }
}