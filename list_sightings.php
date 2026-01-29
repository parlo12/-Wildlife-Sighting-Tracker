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

// 1 mile = 1609.34 meters
$PROXIMITY_RADIUS_METERS = 1609.34;

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Select sightings, but exclude older sightings if a newer one exists within 1 mile.
    // This ensures only the most recent sighting in each 1-mile cluster is shown.
    $stmt = $pdo->prepare(
        'SELECT s1.id, s1.species, s1.user_id, s1.created_at, s1.expires_at, s1.last_confirmed_at, ' .
        'ST_Y(s1.location::geometry) AS latitude, ' .
        'ST_X(s1.location::geometry) AS longitude ' .
        'FROM sightings s1 ' .
        'WHERE (s1.expires_at IS NULL OR s1.expires_at > NOW()) ' .
        'AND NOT EXISTS ( ' .
        '    SELECT 1 FROM sightings s2 ' .
        '    WHERE s2.id != s1.id ' .
        '    AND (s2.expires_at IS NULL OR s2.expires_at > NOW()) ' .
        '    AND s2.created_at > s1.created_at ' .
        '    AND ST_DWithin(s1.location::geography, s2.location::geography, :radius) ' .
        ') ' .
        'ORDER BY s1.created_at DESC ' .
        'LIMIT :limit'
    );
    $stmt->bindValue(':radius', $PROXIMITY_RADIUS_METERS, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll();

    logEvent('info', 'list_sightings', ['count' => count($data), 'limit' => $limit]);
    respond(200, ['data' => $data]);
} catch (Throwable $e) {
    logEvent('error', 'exception', ['error' => $e->getMessage()]);
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
