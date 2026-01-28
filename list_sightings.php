<?php

declare(strict_types=1);

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';

$CORS = $config['cors'];
$DB_HOST = $config['db']['host'];
$DB_PORT = $config['db']['port'];
$DB_NAME = $config['db']['name'];
$DB_USER = $config['db']['user'];
$DB_PASS = $config['db']['pass'];
$BASE_URL = $config['base_url'];
$LOG_FILE = $config['log_file'];

function applyCors(array $corsConfig): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    $allowedOrigins = $corsConfig['allowed_origins'] ?: ['*'];

    $allowOrigin = '*';
    if ($allowedOrigins !== ['*']) {
        if (in_array($origin, $allowedOrigins, true)) {
            $allowOrigin = $origin;
        } else {
            $allowOrigin = $allowedOrigins[0];
        }
    }

    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Access-Control-Allow-Methods: ' . implode(',', $corsConfig['allowed_methods'] ?: ['GET', 'POST', 'OPTIONS']));
    header('Access-Control-Allow-Headers: ' . implode(',', $corsConfig['allowed_headers'] ?: ['Content-Type', 'Authorization']));
    header('Access-Control-Allow-Credentials: ' . (!empty($corsConfig['allow_credentials']) ? 'true' : 'false'));
}

function respond(int $statusCode, array $payload): void
{
    applyCors($GLOBALS['CORS']);
    logEvent($statusCode >= 400 ? 'error' : 'info', 'response', [
        'status' => $statusCode,
        'payload' => $statusCode >= 400 ? $payload : null,
    ]);
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function absoluteImageUrl(string $baseUrl, string $relativePath): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
}

function logEvent(string $level, string $message, array $context = []): void
{
    $logFile = $GLOBALS['LOG_FILE'] ?? (__DIR__ . '/logs/access.log');
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $entry = [
        'ts' => date('c'),
        'level' => $level,
        'message' => $message,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'context' => $context,
    ];
    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

applyCors($CORS);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    logEvent('warn', 'invalid_method');
    respond(405, ['error' => 'Method not allowed']);
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
$limit = max(1, min($limit, 1000)); // avoid unbounded queries

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare(
        'SELECT id, species, created_at, expires_at, last_confirmed_at, ' .
        'ST_Y(location::geometry) AS latitude, ' .
        'ST_X(location::geometry) AS longitude ' .
        'FROM sightings ' .
        'WHERE (expires_at IS NULL OR expires_at > NOW()) ' .
        'ORDER BY created_at DESC ' .
        'LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll();

    logEvent('info', 'list_sightings', ['count' => count($data), 'limit' => $limit]);
    respond(200, ['data' => $data]);
} catch (Throwable $e) {
    logEvent('error', 'exception', ['error' => $e->getMessage()]);
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
