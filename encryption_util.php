<?php
class EncryptionUtil {
    private static $encryptionKey;
    private static $cipher = 'aes-256-cbc';

    public static function initialize() {
        if (!isset($_ENV['ENCRYPTION_KEY'])) {
            // Generate a new key if not set
            if (!file_exists(__DIR__ . '/.env')) {
                $key = base64_encode(random_bytes(32));
                file_put_contents(__DIR__ . '/.env', "\nENCRYPTION_KEY=\"$key\"", FILE_APPEND);
                $_ENV['ENCRYPTION_KEY'] = $key;
            } else {
                die('ENCRYPTION_KEY not set in .env file');
            }
        }
        self::$encryptionKey = base64_decode($_ENV['ENCRYPTION_KEY']);
    }

    public static function encrypt($data) {
        $ivLength = openssl_cipher_iv_length(self::$cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $encrypted = openssl_encrypt(
            $data,
            self::$cipher,
            self::$encryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        // Combine IV and encrypted data
        $combined = $iv . $encrypted;
        
        // Base64 encode for URL safety
        return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
    }

    public static function decrypt($data) {
        try {
            // Base64 decode
            $data = base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
            
            $ivLength = openssl_cipher_iv_length(self::$cipher);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            return openssl_decrypt(
                $encrypted,
                self::$cipher,
                self::$encryptionKey,
                OPENSSL_RAW_DATA,
                $iv
            );
        } catch (Exception $e) {
            return false;
        }
    }
}