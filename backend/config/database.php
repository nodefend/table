<?php
declare(strict_types=1);

// Biến môi trường hệ điều hành ưu tiên hơn file .env tại gốc dự án.
function tf_config(string $key, ?string $default = null): string
{
    static $file = null;
    if ($file === null) {
        $path = dirname(__DIR__, 2) . '/.env';
        $file = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        if ($file === false) throw new RuntimeException('Invalid environment configuration');
    }
    $value = getenv($key);
    if ($value !== false) return $value;
    if (isset($file[$key]) && is_string($file[$key])) return $file[$key];
    if ($default !== null) return $default;
    throw new RuntimeException('Missing environment configuration: ' . $key);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $host = tf_config('DB_HOST');
    $port = tf_config('DB_PORT');
    $name = tf_config('DB_NAME');
    $charset = tf_config('DB_CHARSET', 'utf8mb4');
    if (!preg_match('/^[a-zA-Z0-9_.:\[\]-]+$/D', $host) ||
        !preg_match('/^[0-9]+$/D', $port) || (int)$port < 1 || (int)$port > 65535 ||
        !preg_match('/^[a-zA-Z0-9_]+$/D', $name) || $charset !== 'utf8mb4') {
        throw new RuntimeException('Invalid database configuration');
    }
    $connection = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=$charset",
        tf_config('DB_USER'), tf_config('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    $connection->exec("SET time_zone = '+00:00'");
    $pdo = $connection;
    return $pdo;
}

// Transaction thuộc service nghiệp vụ; callback phải dùng chính PDO này.
function db_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

