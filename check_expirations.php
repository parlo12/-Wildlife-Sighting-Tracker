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

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Find sightings that are about to expire (within next 5 minutes)
    // or have already expired
    $stmt = $pdo->prepare(
        "SELECT id, species, photo_url, 
                ST_Y(location::geometry) AS latitude, 
                ST_X(location::geometry) AS longitude,
                created_at,
                expires_at,
                last_confirmed_at
         FROM sightings
         WHERE expires_at IS NOT NULL 
         AND expires_at <= NOW() + INTERVAL '5 minutes'
         ORDER BY expires_at ASC"
    );
    
    $stmt->execute();
    $expiring = $stmt->fetchAll();

    // Delete sightings that have passed their expiration time
    $stmtDelete = $pdo->prepare(
        "DELETE FROM sightings 
         WHERE expires_at IS NOT NULL 
         AND expires_at <= NOW()
         RETURNING id"
    );
    
    $stmtDelete->execute();
    $deleted = $stmtDelete->fetchAll(PDO::FETCH_COLUMN);

    logEvent('info', 'expiration_check', [
        'expiring_soon_count' => count($expiring),
        'deleted_count' => count($deleted),
        'deleted_ids' => $deleted
    ]);

    respond(200, [
        'expiring_soon' => $expiring,
        'deleted_ids' => $deleted,
        'deleted_count' => count($deleted)
    ]);

} catch (PDOException $e) {
    logEvent('error', 'db_error', ['message' => $e->getMessage()]);
    respond(500, ['error' => 'Database error']);
}
