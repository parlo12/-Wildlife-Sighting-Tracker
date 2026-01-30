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
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

applyCors($CORS);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['error' => 'Method not allowed']);
}

try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $DB_HOST, $DB_PORT, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Get overall stats
    $totalVisitors = $pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
    $totalPageViews = $pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn();

    // Today's stats
    $todayStats = $pdo->query(
        "SELECT * FROM daily_stats WHERE stat_date = CURRENT_DATE"
    )->fetch() ?: ['unique_visitors' => 0, 'total_visits' => 0, 'new_visitors' => 0, 'returning_visitors' => 0];

    // Last 7 days stats
    $last7Days = $pdo->query(
        "SELECT stat_date, unique_visitors, total_visits, new_visitors, returning_visitors
        FROM daily_stats
        WHERE stat_date >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY stat_date DESC"
    )->fetchAll();

    // Last 30 days stats
    $last30Days = $pdo->query(
        "SELECT stat_date, unique_visitors, total_visits, new_visitors, returning_visitors
        FROM daily_stats
        WHERE stat_date >= CURRENT_DATE - INTERVAL '30 days'
        ORDER BY stat_date DESC"
    )->fetchAll();

    // Visitors by device type
    $deviceStats = $pdo->query(
        "SELECT
            CASE WHEN is_mobile THEN 'Mobile' ELSE 'Desktop' END as device_type,
            COUNT(*) as count
        FROM visitors
        GROUP BY is_mobile"
    )->fetchAll();

    // Top referrers
    $topReferrers = $pdo->query(
        "SELECT referrer, COUNT(*) as count
        FROM visitors
        WHERE referrer IS NOT NULL AND referrer != ''
        GROUP BY referrer
        ORDER BY count DESC
        LIMIT 10"
    )->fetchAll();

    // Recent visitors (last 20)
    $recentVisitors = $pdo->query(
        "SELECT visitor_id, ip_address, user_agent, is_mobile, first_visit_at, last_visit_at, visit_count
        FROM visitors
        ORDER BY last_visit_at DESC
        LIMIT 20"
    )->fetchAll();

    // Hourly distribution for today
    $hourlyToday = $pdo->query(
        "SELECT EXTRACT(HOUR FROM visited_at) as hour, COUNT(*) as visits
        FROM page_views
        WHERE visited_at::date = CURRENT_DATE
        GROUP BY EXTRACT(HOUR FROM visited_at)
        ORDER BY hour"
    )->fetchAll();

    // Calculate summary stats
    $totalVisitsAllTime = $pdo->query('SELECT COALESCE(SUM(visit_count), 0) FROM visitors')->fetchColumn();

    respond(200, [
        'summary' => [
            'total_unique_visitors' => (int)$totalVisitors,
            'total_page_views' => (int)$totalPageViews,
            'total_visits_all_time' => (int)$totalVisitsAllTime,
        ],
        'today' => [
            'unique_visitors' => (int)($todayStats['unique_visitors'] ?? 0),
            'total_visits' => (int)($todayStats['total_visits'] ?? 0),
            'new_visitors' => (int)($todayStats['new_visitors'] ?? 0),
            'returning_visitors' => (int)($todayStats['returning_visitors'] ?? 0),
        ],
        'last_7_days' => $last7Days,
        'last_30_days' => $last30Days,
        'devices' => $deviceStats,
        'top_referrers' => $topReferrers,
        'recent_visitors' => $recentVisitors,
        'hourly_today' => $hourlyToday,
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}
