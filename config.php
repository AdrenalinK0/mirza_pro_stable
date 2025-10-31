<?php
// ----------------------
// تنظیمات دیتابیس
// ----------------------
$dbname = '{database_name}';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';
$hostdb = 'localhost';

$connect = mysqli_connect($hostdb, $usernamedb, $passworddb, $dbname);

// بررسی اتصال mysqli
if (!$connect) {
    error_log("MySQLi connection failed: " . mysqli_connect_error());
    die("Internal Server Error"); // خطای عمومی
}
mysqli_set_charset($connect, "utf8mb4");

// اتصال PDO
$dsn = "mysql:host=$hostdb;dbname=$dbname;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    error_log("PDO connection failed: " . $e->getMessage());
    die("Internal Server Error"); // خطای عمومی
}

// ----------------------
// تنظیمات ربات تلگرام
// ----------------------
$APIKEY = '{API_KEY}';
$usernamebot = '{username_bot}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';

// ----------------------
// تنظیمات اضافی
// ----------------------
$new_marzban = true;

// تابع کمکی برای ثبت لاگ‌ها
function logError($msg) {
    error_log("[VPN Bot] " . $msg);
}
?>