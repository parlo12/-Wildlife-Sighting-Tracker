<?php

declare(strict_types=1);

/**
 * Loads environment variables from a .env file (simple KEY=VALUE, # comments).
 */
function loadEnvFile(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_readable($path)) {
        return;
    }

    $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, "'\"");
        if ($name === '') {
            continue;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    $loaded = true;
}

loadEnvFile(__DIR__ . '/.env');

function envArray(string $key, string $default = ''): array
{
    $value = getenv($key);
    $raw = $value !== false ? $value : $default;
    $parts = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');
    return $parts ?: [];
}

function envBool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

function envPrivateKey(string $key): string
{
    $value = getenv($key) ?: '';
    // Support \n encoded keys (common in env vars).
    return str_replace(["\\n", "\r\n", "\r"], ["\n", "\n", "\n"], $value);
}

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'name' => getenv('DB_NAME') ?: 'wildlife_map',
        'user' => getenv('DB_USER') ?: 'postgres',
        'pass' => getenv('DB_PASS') ?: 'wildlife',
    ],
    'base_url' => rtrim(getenv('BASE_URL') ?: 'http://localhost:8000', '/'),
    'uploads_dir' => getenv('UPLOAD_DIR') ?: (__DIR__ . '/uploads'),
    'radius_meters' => (int) (getenv('RADIUS_METERS') ?: 48280), // 30 miles
    'log_file' => getenv('LOG_FILE') ?: (__DIR__ . '/logs/access.log'),
    'cors' => [
        'allowed_origins' => envArray('CORS_ALLOWED_ORIGINS', '*'),
        'allowed_methods' => envArray('CORS_ALLOWED_METHODS', 'GET,POST,OPTIONS'),
        'allowed_headers' => envArray('CORS_ALLOWED_HEADERS', 'Content-Type, Authorization'),
        'allow_credentials' => envBool('CORS_ALLOW_CREDENTIALS', false),
    ],
    'fcm' => [
        'server_key' => getenv('FCM_SERVER_KEY') ?: '',
        'notification_title' => getenv('FCM_NOTIFICATION_TITLE') ?: 'New Wildlife Sighting',
        'notification_body' => getenv('FCM_NOTIFICATION_BODY') ?: 'A new sighting was reported near you.',
    ],
    'fcm_v1' => [
        'project_id' => getenv('FCM_PROJECT_ID') ?: '',
        'client_email' => getenv('FCM_CLIENT_EMAIL') ?: '',
        'private_key' => envPrivateKey('FCM_PRIVATE_KEY'),
        'token_uri' => getenv('FCM_TOKEN_URI') ?: 'https://oauth2.googleapis.com/token',
    ],
    'vapid' => [
        'public_key' => getenv('VAPID_PUBLIC_KEY') ?: '',
        'private_key' => getenv('VAPID_PRIVATE_KEY') ?: '',
        'subject' => getenv('VAPID_SUBJECT') ?: 'mailto:admin@koteglasye.com',
    ],
    'dashboard_password' => getenv('DASHBOARD_PASSWORD') ?: '',
];
