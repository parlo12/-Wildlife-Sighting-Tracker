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
        'context' => $context,
    ];
    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function getClientIP(): string
{
    // Check for forwarded IP (when behind proxy/load balancer)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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

$visitorId = trim($input['visitor_id'] ?? '');
if (empty($visitorId)) {
    respond(400, ['error' => 'visitor_id is required']);
}

// Collect visitor data
$ipAddress = getClientIP();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$screenWidth = isset($input['screen_width']) ? (int)$input['screen_width'] : null;
$screenHeight = isset($input['screen_height']) ? (int)$input['screen_height'] : null;
$language = $input['language'] ?? '';
$platform = $input['platform'] ?? '';
$referrer = $input['referrer'] ?? '';
$isMobile = !empty($input['is_mobile']);
$sessionId = $input['session_id'] ?? '';
$pageUrl = $input['page_url'] ?? '/';

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Check if visitor exists
    $checkStmt = $pdo->prepare('SELECT id, visit_count FROM visitors WHERE visitor_id = :visitor_id');
    $checkStmt->execute([':visitor_id' => $visitorId]);
    $existing = $checkStmt->fetch();

    $isNewVisitor = false;

    if ($existing) {
        // Update existing visitor
        $updateStmt = $pdo->prepare(
            'UPDATE visitors SET
                last_visit_at = NOW(),
                visit_count = visit_count + 1,
                ip_address = :ip,
                user_agent = :ua
            WHERE visitor_id = :visitor_id'
        );
        $updateStmt->execute([
            ':visitor_id' => $visitorId,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
        ]);
        $visitCount = $existing['visit_count'] + 1;
    } else {
        // Insert new visitor
        $insertStmt = $pdo->prepare(
            'INSERT INTO visitors (visitor_id, ip_address, user_agent, screen_width, screen_height, language, platform, referrer, is_mobile)
            VALUES (:visitor_id, :ip, :ua, :sw, :sh, :lang, :platform, :referrer, :mobile)'
        );
        $insertStmt->execute([
            ':visitor_id' => $visitorId,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
            ':sw' => $screenWidth,
            ':sh' => $screenHeight,
            ':lang' => $language,
            ':platform' => $platform,
            ':referrer' => $referrer,
            ':mobile' => $isMobile ? 't' : 'f',
        ]);
        $visitCount = 1;
        $isNewVisitor = true;
    }

    // Record page view
    $pageViewStmt = $pdo->prepare(
        'INSERT INTO page_views (visitor_id, page_url, session_id) VALUES (:visitor_id, :page_url, :session_id)'
    );
    $pageViewStmt->execute([
        ':visitor_id' => $visitorId,
        ':page_url' => $pageUrl,
        ':session_id' => $sessionId,
    ]);

    // Update daily stats
    $today = date('Y-m-d');
    $statsStmt = $pdo->prepare(
        'INSERT INTO daily_stats (stat_date, unique_visitors, total_visits, new_visitors, returning_visitors)
        VALUES (:date, 1, 1, :new_v, :ret_v)
        ON CONFLICT (stat_date) DO UPDATE SET
            total_visits = daily_stats.total_visits + 1,
            new_visitors = daily_stats.new_visitors + EXCLUDED.new_visitors,
            returning_visitors = daily_stats.returning_visitors + EXCLUDED.returning_visitors'
    );
    $statsStmt->execute([
        ':date' => $today,
        ':new_v' => $isNewVisitor ? 1 : 0,
        ':ret_v' => $isNewVisitor ? 0 : 1,
    ]);

    // Update unique visitors count for today (only if new visitor today)
    if ($isNewVisitor) {
        // Already counted in insert above
    } else {
        // Check if this visitor already visited today
        $todayCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM page_views
            WHERE visitor_id = :visitor_id
            AND visited_at::date = CURRENT_DATE
            AND id != (SELECT MAX(id) FROM page_views WHERE visitor_id = :visitor_id2)'
        );
        $todayCheck->execute([':visitor_id' => $visitorId, ':visitor_id2' => $visitorId]);
        $alreadyVisitedToday = $todayCheck->fetchColumn() > 0;

        if (!$alreadyVisitedToday) {
            // First visit today, increment unique visitors
            $pdo->exec("UPDATE daily_stats SET unique_visitors = unique_visitors + 1 WHERE stat_date = '$today'");
        }
    }

    logEvent('info', 'visit_tracked', [
        'visitor_id' => $visitorId,
        'is_new' => $isNewVisitor,
        'visit_count' => $visitCount,
    ]);

    respond(200, [
        'success' => true,
        'visitor_id' => $visitorId,
        'visit_count' => $visitCount,
        'is_new_visitor' => $isNewVisitor,
    ]);

} catch (Throwable $e) {
    logEvent('error', 'tracking_error', ['error' => $e->getMessage()]);
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
