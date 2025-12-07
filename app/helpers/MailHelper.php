<?php

namespace app\helpers;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailHelper {
    private static $smtpHost, $smtpUser, $smtpPass, $senderName;

    /**
     * Mengirim OTP dengan Template
     */

    private function __construct() {
        self::$smtpHost = $_ENV['SMTP_HOST'];
        self::$smtpUser = $_ENV['SMTP_USER'];
        self::$smtpPass = $_ENV['SMTP_PASS'];
        self::$senderName = $_ENV['SMTP_SENDER_NAME'];
    }
    public static function sendOtp($recipientEmail, $name, $otpCode) {
        $subject = 'Kode Verifikasi Masuk (OTP)';

        // Isi konten khusus OTP
        $bodyContent = "
            <p>Halo <b>{$name}</b>,</p>
            <p>Kami menerima permintaan untuk masuk atau mendaftar ke akun Sinergi Anda. Gunakan kode verifikasi (OTP) di bawah ini untuk melanjutkan:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <span style='display: inline-block; background-color: #eff6ff; color: #2563eb; font-size: 32px; font-weight: bold; letter-spacing: 5px; padding: 15px 30px; border-radius: 8px; border: 1px dashed #2563eb;'>
                    {$otpCode}
                </span>
            </div>

            <p>Kode ini hanya berlaku selama 5 menit. Mohon jangan bagikan kode ini kepada siapa pun, termasuk pihak Sinergi.</p>
        ";

        // Bungkus dengan Layout Utama
        $finalHtml = self::getEmailTemplate($subject, $bodyContent);

        return self::send($recipientEmail, $name, $subject, $finalHtml);
    }

    /**
     * Mengirim Link Aktivasi dengan Template
     */
    public static function sendActivationEmail($recipientEmail, $activationLink) {
        // Ambil nama dari email (sebelum @) karena belum tentu ada nama lengkap
        $nameParts = explode('@', $recipientEmail);
        $name = ucfirst($nameParts[0]);

        $subject = 'Undangan Bergabung ke Sinergi';

        // Isi konten khusus Aktivasi
        $bodyContent = "
            <p>Halo <b>{$name}</b>,</p>
            <p>Selamat! Anda telah diundang untuk bergabung ke dalam komunitas Sinergi.</p>
            <p>Untuk mengaktifkan akun Anda dan membuat kata sandi, silakan klik tombol di bawah ini:</p>

            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$activationLink}' style='background-color: #2563eb; color: #ffffff; font-size: 16px; font-weight: bold; text-decoration: none; padding: 12px 24px; border-radius: 6px; display: inline-block; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2);'>
                    Aktifkan Akun Saya
                </a>
            </div>

            <p style='font-size: 13px; color: #6b7280;'>Jika tombol di atas tidak berfungsi, salin dan tempel tautan berikut ke browser Anda:<br>
            <a href='{$activationLink}' style='color: #2563eb;'>{$activationLink}</a></p>
        ";

        // Bungkus dengan Layout Utama
        $finalHtml = self::getEmailTemplate($subject, $bodyContent);

        return self::send($recipientEmail, $name, $subject, $finalHtml);
    }

    private static function ensureConfigLoaded(): void {
        if (!empty(self::$smtpHost)) return; // sudah dimuat

        // Coba ambil dari beberapa sumber (getenv dulu, lalu $_ENV)
        self::$smtpHost   = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? null);
        self::$smtpUser   = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? null);
        self::$smtpPass   = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? null);
        self::$senderName = getenv('SMTP_SENDER_NAME') ?: ($_ENV['SMTP_SENDER_NAME'] ?? 'Sinergi');

        // (optional) kamu bisa melempar exception kalau mandatory missing
        if (empty(self::$smtpHost) || empty(self::$smtpUser) || empty(self::$smtpPass)) {
            // throw new \RuntimeException('SMTP config missing. Pastikan env SMTP_HOST/SMTP_USER/SMTP_PASS ter-set.');
            // Atau cukup log dan biarkan return false di send()
        }
    }

    /**
     * Fungsi Generic Pengirim Email (Private)
     */
    private static function send($toEmail, $toName, $subject, $htmlBody) {
        $mail = new PHPMailer(true);
        self::ensureConfigLoaded();

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = self::$smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = self::$smtpUser;
            $mail->Password   = self::$smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom(self::$smtpUser, self::$senderName);
            $mail->addAddress($toEmail, $toName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            // Plain text version (opsional, untuk klien email jadul)
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (Exception $e) {
            // Log error jika perlu: error_log($mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Template HTML Utama (Layout)
     * Agar desain konsisten untuk semua jenis email
     */
    private static function getEmailTemplate($title, $content) {
        $year = date('Y');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; }
                .container { width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; margin-top: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
                .header { background-color: #2563eb; padding: 20px; text-align: center; }
                .header h1 { color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 1px; }
                .content { padding: 30px; color: #374151; line-height: 1.6; font-size: 16px; }
                .footer { background-color: #f9fafb; padding: 20px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
                .footer a { color: #2563eb; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <!-- Ganti SINERGI dengan <img> logo jika sudah online -->
                    <h1>SINERGI</h1>
                </div>
                <div class='content'>
                    {$content}
                </div>
                <div class='footer'>
                    <p>&copy; {$year} Sinergi App. All rights reserved.</p>
                    <p>Politeknik Negeri Jakarta.<br>Jl. Prof. Dr. G.A. Siwabessy, Kampus UI, Depok.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}