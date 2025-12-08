<?php
namespace app\controllers;

class CaptchaController
{
    private function acakCaptcha() {
        $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSUVWXYZ0123456789";
        $pass = array();
        $panjangAlpha = strlen($alphabet) - 1;
        for ($i = 0; $i < 5; $i++) {
            $n = rand(0, $panjangAlpha);
            $pass[] = $alphabet[$n];
        }
        return implode($pass);
    }

    public function generate() {
        $code = $this->acakCaptcha();
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION["code"] = $code; // Simpan kode di sesi

        $wh = imagecreatetruecolor(173, 50);
        $bgc = imagecolorallocate($wh, 22, 86, 165); // Biru
        $fc = imagecolorallocate($wh, 223, 230, 233); // Putih
        imagefill($wh, 0, 0, $bgc);
        imagestring($wh, 10, 50, 15, $code, $fc);

        // Atur header dan kirim gambar
        header("Access-Control-Allow-Origin: " . $_ENV['FRONTEND_URL']);
        header("Access-Control-Allow-Credentials: true");
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('content-type: image/jpg');
        imagejpeg($wh);
        imagedestroy($wh);

        exit;
    }
}