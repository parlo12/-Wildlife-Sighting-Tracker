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
$VAPID = $config['vapid'];

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

function sendWebPushNotifications(PDO $pdo, string $title, string $body, array $vapid): array
{
    $result = [
        'requested' => 0,
        'success' => 0,
        'failure' => 0,
    ];

    // Get all push subscriptions
    $stmt = $pdo->query('SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions');
    $subscriptions = $stmt->fetchAll();
    $result['requested'] = count($subscriptions);

    if (empty($subscriptions)) {
        return $result;
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => '/'
    ]);

    foreach ($subscriptions as $sub) {
        try {
            $sent = sendSingleWebPush(
                $sub['endpoint'],
                $sub['p256dh_key'],
                $sub['auth_key'],
                $payload,
                $vapid
            );
            if ($sent) {
                $result['success']++;
            } else {
                $result['failure']++;
            }
        } catch (Throwable $e) {
            $result['failure']++;
        }
    }

    return $result;
}

function sendSingleWebPush(string $endpoint, string $p256dh, string $auth, string $payload, array $vapid): bool
{
    // Decode subscriber keys
    $userPublicKey = base64_decode($p256dh);
    $userAuth = base64_decode($auth);

    if (!$userPublicKey || !$userAuth) {
        return false;
    }

    // Generate local ECDH key pair
    $localKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);
    if (!$localKey) {
        return false;
    }

    $localKeyDetails = openssl_pkey_get_details($localKey);
    $localPublicKey = chr(4) . $localKeyDetails['ec']['x'] . $localKeyDetails['ec']['y'];

    // Derive shared secret using ECDH
    // Create public key resource from user's key
    $userKeyPem = createEcPublicKeyPem($userPublicKey);
    if (!$userKeyPem) {
        return false;
    }

    $sharedSecret = '';
    $userKeyResource = openssl_pkey_get_public($userKeyPem);
    if (!$userKeyResource) {
        return false;
    }

    // Use openssl_pkey_derive for ECDH (PHP 7.3+)
    if (function_exists('openssl_pkey_derive')) {
        $sharedSecret = openssl_pkey_derive($userKeyResource, $localKey);
    } else {
        // Fallback - just skip encryption and send unencrypted (won't work for most browsers)
        return false;
    }

    if (!$sharedSecret) {
        return false;
    }

    // HKDF for encryption keys
    $salt = random_bytes(16);
    $context = createContext($userPublicKey, $localPublicKey);

    $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);
    $cek_info = createInfo('aesgcm', $context);
    $cek = hkdfExpand($prk, $cek_info, 16);

    $nonce_info = createInfo('nonce', $context);
    $nonce = hkdfExpand($prk, $nonce_info, 12);

    // Pad payload
    $padding = chr(0) . chr(0);
    $paddedPayload = $padding . $payload;

    // Encrypt with AES-GCM
    $encrypted = openssl_encrypt(
        $paddedPayload,
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($encrypted === false) {
        return false;
    }

    $body = $salt . pack('n', 4096) . chr(strlen($localPublicKey)) . $localPublicKey . $encrypted . $tag;

    // Create VAPID JWT
    $jwt = createVapidJwt($endpoint, $vapid);
    if (!$jwt) {
        return false;
    }

    // Send the request
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aesgcm',
        'Content-Length: ' . strlen($body),
        'TTL: 86400',
        'Authorization: WebPush ' . $jwt,
        'Crypto-Key: dh=' . rtrim(strtr(base64_encode($localPublicKey), '+/', '-_'), '=') . ';p256ecdsa=' . $vapid['public_key'],
        'Encryption: salt=' . rtrim(strtr(base64_encode($salt), '+/', '-_'), '='),
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

function createEcPublicKeyPem(string $publicKey): ?string
{
    if (strlen($publicKey) !== 65 || $publicKey[0] !== chr(4)) {
        return null;
    }

    // DER encoding for EC public key
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $publicKey;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
}

function createContext(string $userPublicKey, string $localPublicKey): string
{
    return "P-256\0" .
        pack('n', strlen($userPublicKey)) . $userPublicKey .
        pack('n', strlen($localPublicKey)) . $localPublicKey;
}

function createInfo(string $type, string $context): string
{
    return "Content-Encoding: " . $type . chr(0) . $context;
}

function hkdfExpand(string $prk, string $info, int $length): string
{
    $t = '';
    $lastBlock = '';
    $counter = 1;

    while (strlen($t) < $length) {
        $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($counter), $prk, true);
        $t .= $lastBlock;
        $counter++;
    }

    return substr($t, 0, $length);
}

function createVapidJwt(string $endpoint, array $vapid): ?string
{
    $parsedUrl = parse_url($endpoint);
    $audience = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $payload = [
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $vapid['subject'],
    ];

    $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $data = $headerB64 . '.' . $payloadB64;

    // Decode private key
    $privateKeyRaw = base64_decode(strtr($vapid['private_key'] . str_repeat('=', (4 - strlen($vapid['private_key']) % 4) % 4), '-_', '+/'));

    // Create PEM from raw private key
    // Build DER structure for EC private key
    $der = hex2bin('30770201010420') . $privateKeyRaw .
           hex2bin('a00a06082a8648ce3d030107a144034200') .
           base64_decode(strtr($vapid['public_key'] . str_repeat('=', (4 - strlen($vapid['public_key']) % 4) % 4), '-_', '+/'));

    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END EC PRIVATE KEY-----\n";

    $key = openssl_pkey_get_private($pem);
    if (!$key) {
        return null;
    }

    $signature = '';
    if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    // Convert DER signature to raw format (r + s, each 32 bytes)
    $sigRaw = derToRaw($signature);
    if (!$sigRaw) {
        return null;
    }

    $signatureB64 = rtrim(strtr(base64_encode($sigRaw), '+/', '-_'), '=');
    return $data . '.' . $signatureB64;
}

function derToRaw(string $der): ?string
{
    // Parse DER signature and extract r and s values
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) return null;

    $length = ord($der[$pos++]);
    if ($length & 0x80) {
        $pos += ($length & 0x7f);
    }

    // R
    if (ord($der[$pos++]) !== 0x02) return null;
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;

    // S
    if (ord($der[$pos++]) !== 0x02) return null;
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);

    // Pad or trim to 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return substr($r, -32) . substr($s, -32);
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

// Validate required fields
if (!isset($_POST['species']) || empty(trim($_POST['species']))) {
    logEvent('warn', 'missing_species');
    respond(400, ['error' => 'Species name is required']);
}

if (!isset($_POST['lat'], $_POST['lon'])) {
    logEvent('warn', 'missing_coordinates');
    respond(400, ['error' => 'Location coordinates are required']);
}

// Check if we have either an image or lat/lon coordinates
$hasImage = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;
$hasCoordinates = isset($_POST['lat'], $_POST['lon']);

if (!$hasImage && !$hasCoordinates) {
    logEvent('warn', 'no_image_or_coordinates');
    respond(400, ['error' => 'Either an image with GPS data or current location coordinates are required']);
}

$storedPath = null;
$targetPath = null;

// Handle image upload if provided
if ($hasImage) {
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
}

// Get and validate coordinates
$clientCoords = sanitizeClientCoords();
if (!$clientCoords) {
    logEvent('warn', 'invalid_coordinates');
    respond(422, ['error' => 'Invalid location coordinates']);
}

[$lat, $lon] = $clientCoords;
$species = trim($_POST['species']);
$userId = isset($_POST['user_id']) ? trim($_POST['user_id']) : null;

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Insert the sighting; location stored as geometry with SRID 4326.
    // Set expiration to 4 hours from now
    $insert = $pdo->prepare(
        'INSERT INTO sightings (species, location, latitude, longitude, user_id, expires_at, last_confirmed_at) ' .
        'VALUES (:species, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), :lat, :lon, :user_id, ' .
        "NOW() + INTERVAL '4 hours', NOW()) " .
        'RETURNING id'
    );
    $insert->execute([
        ':species' => $species,
        ':lat' => $lat,
        ':lon' => $lon,
        ':user_id' => $userId,
    ]);
    $sightingId = (int) $insert->fetchColumn();

    // Find nearby users within 30 miles.
    // Note: users table may not exist yet, so we'll skip notifications for now
    // $tokensStmt = $pdo->prepare(
    //     'SELECT device_token FROM sightings ' .
    //     'WHERE device_token IS NOT NULL AND device_token <> \'\' ' .
    //     'AND ST_DWithin(location, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), :radius)'
    // );
    $tokens = []; // Disabled until users table is created
    // $tokensStmt->bindValue(':lon', $lon);
    // $tokensStmt->bindValue(':lat', $lat);
    // $tokensStmt->bindValue(':radius', $RADIUS_METERS, PDO::PARAM_INT);
    // $tokensStmt->execute();
    // $tokens = $tokensStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $fcmResult = null;
    if ($tokens) {
        if (!empty($FCM_V1['project_id']) && !empty($FCM_V1['client_email']) && !empty($FCM_V1['private_key'])) {
            $fcmResult = sendFcmV1Notification($tokens, $lat, $lon, $FCM_TITLE, $FCM_BODY, $FCM_V1);
        } elseif ($FCM_KEY) {
            $fcmResult = sendFcmLegacy($tokens, $lat, $lon, $FCM_KEY, $FCM_TITLE, $FCM_BODY);
        }
    }

    // Send web push notifications
    $webPushResult = null;
    if (!empty($VAPID['public_key']) && !empty($VAPID['private_key'])) {
        $webPushResult = sendWebPushNotifications(
            $pdo,
            'Nouvo Glas Poste!',
            "Yon moun te poste $species nan zòn ou!",
            $VAPID
        );
    }

    logEvent('info', 'sighting_created', [
        'sighting_id' => $sightingId,
        'lat' => $lat,
        'lon' => $lon,
        'species' => $species,
        'tokens' => count($tokens),
        'fcm_used' => $fcmResult['used'] ?? null,
        'fcm_success' => $fcmResult['success'] ?? null,
        'fcm_failure' => $fcmResult['failure'] ?? null,
        'webpush_requested' => $webPushResult['requested'] ?? 0,
        'webpush_success' => $webPushResult['success'] ?? 0,
    ]);

    respond(200, [
        'sighting_id' => $sightingId,
        'lat' => $lat,
        'lon' => $lon,
        'species' => $species,
        'fcm_tokens' => $tokens,
        'fcm' => $fcmResult,
        'webpush' => $webPushResult,
    ]);
} catch (Throwable $e) {
    logEvent('error', 'exception', ['error' => $e->getMessage()]);
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
