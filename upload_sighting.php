<?php

declare(strict_types=1);

header('Content-Type: application/json');

// Raise upload limits for mobile images (can be overridden via CLI flags).
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '60M');
ini_set('max_file_uploads', '20');

$config = require __DIR__ . '/config.php';

$CORS = $config['cors'];
$DB_HOST = $config['db']['host'];
$DB_PORT = $config['db']['port'];
$DB_NAME = $config['db']['name'];
$DB_USER = $config['db']['user'];
$DB_PASS = $config['db']['pass'];
$BASE_URL = $config['base_url'];
$UPLOAD_DIR = rtrim($config['uploads_dir'], DIRECTORY_SEPARATOR);
$LOG_FILE = $config['log_file'];
$RADIUS_METERS = $config['radius_meters']; // default 30 miles
$FCM_KEY = $config['fcm']['server_key'];
$FCM_TITLE = $config['fcm']['notification_title'];
$FCM_BODY = $config['fcm']['notification_body'];
$FCM_V1 = $config['fcm_v1'];

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

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        default => 'Unknown upload error',
    };
}

function absoluteImageUrl(string $baseUrl, string $relativePath): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
}

function gpsPartToFloat(string $value): float
{
    // EXIF GPS values are stored as fractions like "427/10".
    if (strpos($value, '/') !== false) {
        [$num, $den] = array_map('floatval', explode('/', $value, 2));
        if ($den != 0.0) {
            return $num / $den;
        }
    }
    return (float) $value;
}

function gpsToDecimal(array $coord, string $hemisphere): float
{
    // Converts EXIF GPS array to decimal degrees.
    $degrees = gpsPartToFloat($coord[0] ?? '0');
    $minutes = gpsPartToFloat($coord[1] ?? '0');
    $seconds = gpsPartToFloat($coord[2] ?? '0');

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    if (in_array(strtoupper($hemisphere), ['S', 'W'], true)) {
        $decimal *= -1;
    }
    return $decimal;
}

function extractGpsFromExif(string $filePath): ?array
{
    $exif = @exif_read_data($filePath, 'GPS', true);
    if (!$exif || empty($exif['GPS']['GPSLatitude']) || empty($exif['GPS']['GPSLongitude'])) {
        return null;
    }

    $lat = gpsToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
    $lon = gpsToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');

    return [$lat, $lon];
}

function sanitizeClientCoords(): ?array
{
    if (!isset($_POST['lat'], $_POST['lon'])) {
        return null;
    }
    $lat = filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT);
    $lon = filter_var($_POST['lon'], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false) {
        return null;
    }
    // Basic range check
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }
    return [$lat, $lon];
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getGoogleAccessToken(array $fcmV1): ?string
{
    if (empty($fcmV1['project_id']) || empty($fcmV1['client_email']) || empty($fcmV1['private_key'])) {
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $fcmV1['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $fcmV1['token_uri'],
        'exp' => $now + 3600,
        'iat' => $now,
    ];

    $jwt = base64UrlEncode(json_encode($header)) . '.' . base64UrlEncode(json_encode($claims));

    $privateKey = openssl_pkey_get_private($fcmV1['private_key']);
    if ($privateKey === false) {
        return null;
    }

    $signature = '';
    $signed = openssl_sign($jwt, $signature, $privateKey, 'sha256');
    openssl_free_key($privateKey);

    if (!$signed) {
        return null;
    }
    $jwt .= '.' . base64UrlEncode($signature);

    $ch = curl_init($fcmV1['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$response) {
        return null;
    }
    $decoded = json_decode($response, true);
    return $decoded['access_token'] ?? null;
}

function sendFcmV1Notification(array $tokens, float $lat, float $lon, string $title, string $body, array $fcmV1): array
{
    $result = [
        'used' => 'v1',
        'requested' => count($tokens),
        'success' => 0,
        'failure' => 0,
        'responses' => [],
    ];

    $accessToken = getGoogleAccessToken($fcmV1);
    if (!$accessToken) {
        $result['error'] = 'Failed to obtain access token';
        return $result;
    }

    $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $fcmV1['project_id']);
    foreach ($tokens as $token) {
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'lat' => (string) $lat,
                    'lon' => (string) $lon,
                ],
            ],
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $result['success']++;
        } else {
            $result['failure']++;
        }
        $result['responses'][] = [
            'token' => $token,
            'http_code' => $code,
            'response' => $response ? json_decode($response, true) : null,
        ];
    }

    return $result;
}

function sendFcmLegacy(array $tokens, float $lat, float $lon, string $serverKey, string $title, string $body): array
{
    $endpoint = 'https://fcm.googleapis.com/fcm/send';
    $chunks = array_chunk(array_values($tokens), 1000);
    $summary = [
        'used' => 'legacy',
        'requested' => count($tokens),
        'success' => 0,
        'failure' => 0,
        'responses' => [],
    ];

    foreach ($chunks as $batch) {
        $payload = [
            'registration_ids' => $batch,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => [
                'lat' => $lat,
                'lon' => $lon,
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: key=' . $serverKey,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload),
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        $decoded = $response ? json_decode($response, true) : null;

        $success = (int) ($decoded['success'] ?? 0);
        $failure = (int) ($decoded['failure'] ?? 0);

        $summary['success'] += $success;
        $summary['failure'] += $failure;
        $summary['responses'][] = $decoded ?: ['error' => 'No response'];
    }

    return $summary;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logEvent('warn', 'invalid_method');
    respond(405, ['error' => 'Method not allowed']);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['image']['error'] ?? null;
    $message = $code !== null ? uploadErrorMessage((int) $code) : 'Image upload failed';
    logEvent('warn', 'upload_failed', ['code' => $code, 'message' => $message]);
    respond(400, ['error' => 'Image upload failed', 'detail' => $message, 'code' => $code]);
}

if (!is_dir($UPLOAD_DIR) && !mkdir($UPLOAD_DIR, 0755, true) && !is_dir($UPLOAD_DIR)) {
    logEvent('error', 'upload_dir_failure', ['dir' => $UPLOAD_DIR]);
    respond(500, ['error' => 'Failed to create upload directory']);
}

$originalName = $_FILES['image']['name'] ?? 'upload';
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$targetName = uniqid('sighting_', true) . ($extension ? ".{$extension}" : '');
$targetPath = $UPLOAD_DIR . DIRECTORY_SEPARATOR . $targetName;
$storedPath = basename($UPLOAD_DIR) . '/' . $targetName;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    logEvent('error', 'move_uploaded_file_failed', ['target' => $targetPath]);
    respond(500, ['error' => 'Failed to save uploaded image']);
}

$gps = extractGpsFromExif($targetPath);
[$lat, $lon] = [null, null];
if ($gps) {
    [$lat, $lon] = $gps;
    $source = 'exif';
} else {
    $clientCoords = sanitizeClientCoords();
    if ($clientCoords) {
        [$lat, $lon] = $clientCoords;
        $source = 'client';
        logEvent('warn', 'missing_exif_client_fallback', ['file' => $targetName]);
    } else {
        logEvent('warn', 'missing_exif', ['file' => $targetName]);
        respond(422, ['error' => 'No GPS metadata found in image']);
    }
}

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Insert the sighting; coord stored as geography for spheroid math.
    // Set expiration to 4 hours from now
    $insert = $pdo->prepare(
        'INSERT INTO sightings (image_path, coord, expires_at, last_confirmed_at) ' .
        'VALUES (:path, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, ' .
        "NOW() + INTERVAL '4 hours', NOW()) " .
        'RETURNING id'
    );
    $insert->execute([
        ':path' => $storedPath,
        ':lat' => $lat,
        ':lon' => $lon,
    ]);
    $sightingId = (int) $insert->fetchColumn();

    // Find nearby users within 30 miles.
    $tokensStmt = $pdo->prepare(
        'SELECT fcm_token FROM users ' .
        'WHERE fcm_token IS NOT NULL AND fcm_token <> \'\' ' .
        'AND ST_DWithin(current_loc, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)'
    );
    $tokensStmt->bindValue(':lon', $lon);
    $tokensStmt->bindValue(':lat', $lat);
    $tokensStmt->bindValue(':radius', $RADIUS_METERS, PDO::PARAM_INT);
    $tokensStmt->execute();
    $tokens = $tokensStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $fcmResult = null;
    if ($tokens) {
        if (!empty($FCM_V1['project_id']) && !empty($FCM_V1['client_email']) && !empty($FCM_V1['private_key'])) {
            $fcmResult = sendFcmV1Notification($tokens, $lat, $lon, $FCM_TITLE, $FCM_BODY, $FCM_V1);
        } elseif ($FCM_KEY) {
            $fcmResult = sendFcmLegacy($tokens, $lat, $lon, $FCM_KEY, $FCM_TITLE, $FCM_BODY);
        }
    }

    logEvent('info', 'sighting_created', [
        'sighting_id' => $sightingId,
        'lat' => $lat,
        'lon' => $lon,
        'coord_source' => $source ?? 'unknown',
        'tokens' => count($tokens),
        'fcm_used' => $fcmResult['used'] ?? null,
        'fcm_success' => $fcmResult['success'] ?? null,
        'fcm_failure' => $fcmResult['failure'] ?? null,
    ]);

    respond(200, [
        'sighting_id' => $sightingId,
        'image_path' => $storedPath,
        'image_url' => absoluteImageUrl($BASE_URL, $storedPath),
        'lat' => $lat,
        'lon' => $lon,
        'coord_source' => $source ?? 'unknown',
        'fcm_tokens' => $tokens,
        'fcm' => $fcmResult,
    ]);
} catch (Throwable $e) {
    logEvent('error', 'exception', ['error' => $e->getMessage()]);
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
