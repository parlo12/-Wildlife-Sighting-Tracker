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
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

applyCors($CORS);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method not allowed']);
}

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$endpoint = trim($input['endpoint'] ?? '');
$p256dhKey = trim($input['p256dh'] ?? '');
$authKey = trim($input['auth'] ?? '');

if (empty($endpoint) || empty($p256dhKey) || empty($authKey)) {
    respond(400, ['error' => 'Missing subscription data']);
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Upsert subscription
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent)
        VALUES (:endpoint, :p256dh, :auth, :ua)
        ON CONFLICT (endpoint) DO UPDATE SET
            p256dh_key = EXCLUDED.p256dh_key,
            auth_key = EXCLUDED.auth_key,
            user_agent = EXCLUDED.user_agent,
            last_used_at = NOW()
        RETURNING id'
    );
    $stmt->execute([
        ':endpoint' => $endpoint,
        ':p256dh' => $p256dhKey,
        ':auth' => $authKey,
        ':ua' => $userAgent,
    ]);

    $id = $stmt->fetchColumn();

    respond(200, [
        'success' => true,
        'subscription_id' => $id,
    ]);

} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
