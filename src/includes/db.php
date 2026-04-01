<?php
// =============================================================
// includes/db.php - اتصال قاعدة البيانات
// =============================================================

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '+03:00'"
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                die(json_encode([
                    'success' => false,
                    'message' => 'خطأ في الاتصال بقاعدة البيانات'
                ]));
            }
        }
        return self::$instance;
    }

    // منع النسخ
    private function __construct() {}
    private function __clone() {}
}

/**
 * اختصار للحصول على اتصال PDO
 */
function db(): PDO {
    return Database::getInstance();
}
