<?php
session_start();

// Load config for password
$config = require __DIR__ . '/config.php';

// Password protection - from environment variable
$DASHBOARD_PASSWORD = $config['dashboard_password'];

// Check if logging out
if (isset($_GET['logout'])) {
    unset($_SESSION['dashboard_auth']);
    header('Location: dashboard.php');
    exit;
}

// Check if submitting password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $DASHBOARD_PASSWORD) {
        $_SESSION['dashboard_auth'] = true;
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid password';
    }
}

// Check if authenticated
$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;

if (!$isAuthenticated) {
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-box {
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 16px;
                padding: 40px;
                width: 100%;
                max-width: 400px;
                text-align: center;
            }
            h1 {
                color: #fff;
                margin-bottom: 10px;
                font-size: 24px;
            }
            .subtitle {
                color: #888;
                margin-bottom: 30px;
                font-size: 14px;
            }
            input[type="password"] {
                width: 100%;
                padding: 15px;
                border: 2px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                background: rgba(255,255,255,0.05);
                color: #fff;
                font-size: 16px;
                margin-bottom: 15px;
                outline: none;
            }
            input[type="password"]:focus {
                border-color: #667eea;
            }
            button {
                width: 100%;
                padding: 15px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border: none;
                border-radius: 8px;
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            }
            .error {
                color: #ff6b6b;
                margin-bottom: 15px;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>Glas Ye Dashboard</h1>
            <p class="subtitle">Enter password to access analytics</p>
            <?php if (isset($error)): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Password" autofocus required>
                <button type="submit">Access Dashboard</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// User is authenticated, show dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glas Ye - Admin Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        h1 {
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .refresh-btn, .logout-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
        }

        .logout-btn {
            background: rgba(255,255,255,0.1);
        }

        .refresh-btn:hover, .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .refresh-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.1);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            font-size: 14px;
            color: #888;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 36px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card .subtitle {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        .panel {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .panel h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel h2 span {
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        th {
            color: #888;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }

        td {
            font-size: 14px;
        }

        .visitor-id {
            font-family: monospace;
            font-size: 12px;
            color: #667eea;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-mobile {
            background: #28a745;
            color: white;
        }

        .badge-desktop {
            background: #007bff;
            color: white;
        }

        .chart-container {
            height: 200px;
            display: flex;
            align-items: flex-end;
            gap: 8px;
            padding-top: 20px;
        }

        .chart-bar {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px 4px 0 0;
            min-height: 10px;
            position: relative;
            transition: height 0.3s;
        }

        .chart-bar:hover {
            opacity: 0.8;
        }

        .chart-bar .tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
        }

        .chart-bar:hover .tooltip {
            opacity: 1;
        }

        .chart-labels {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .chart-labels span {
            flex: 1;
            text-align: center;
            font-size: 10px;
            color: #666;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.5);
            color: #ff6b6b;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .last-updated {
            font-size: 12px;
            color: #666;
        }

        .device-chart {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .device-item {
            flex: 1;
            text-align: center;
        }

        .device-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .device-count {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .device-label {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .panels {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Glas Ye Dashboard</h1>
                <p class="last-updated" id="lastUpdated">Loading...</p>
            </div>
            <div class="header-buttons">
                <button class="refresh-btn" id="refreshBtn" onclick="loadStats()">
                    Refresh Data
                </button>
                <a href="?logout=1" class="logout-btn">Logout</a>
            </div>
        </header>

        <div id="content">
            <div class="loading">Loading statistics...</div>
        </div>
    </div>

    <script>
        const STATS_URL = '/get_stats.php';

        async function loadStats() {
            const refreshBtn = document.getElementById('refreshBtn');
            const content = document.getElementById('content');

            refreshBtn.disabled = true;
            refreshBtn.textContent = 'Loading...';

            try {
                const response = await fetch(STATS_URL);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                renderDashboard(data);
                document.getElementById('lastUpdated').textContent =
                    `Last updated: ${new Date().toLocaleString()}`;

            } catch (error) {
                content.innerHTML = `
                    <div class="error">
                        <h3>Error loading stats</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            } finally {
                refreshBtn.disabled = false;
                refreshBtn.textContent = 'Refresh Data';
            }
        }

        function renderDashboard(data) {
            const content = document.getElementById('content');

            // Calculate device stats
            let mobileCount = 0;
            let desktopCount = 0;
            data.devices.forEach(d => {
                if (d.device_type === 'Mobile') mobileCount = parseInt(d.count);
                else desktopCount = parseInt(d.count);
            });

            // Get last 7 days for chart
            const last7Days = data.last_7_days.slice(0, 7).reverse();
            const maxVisits = Math.max(...last7Days.map(d => d.total_visits), 1);

            content.innerHTML = `
                <!-- Summary Stats -->
                <div class="stats-grid">
                    <div class="stat-card" style="background: linear-gradient(135deg, rgba(102,126,234,0.2) 0%, rgba(118,75,162,0.2) 100%);">
                        <h3>Total Visitors</h3>
                        <div class="value" style="font-size: 48px;">${data.summary.total_unique_visitors}</div>
                        <div class="subtitle">All time unique visitors</div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Visits</h3>
                        <div class="value">${data.summary.total_visits_all_time}</div>
                        <div class="subtitle">All time total visits</div>
                    </div>
                    <div class="stat-card">
                        <h3>Page Views</h3>
                        <div class="value">${data.summary.total_page_views}</div>
                        <div class="subtitle">Total page views</div>
                    </div>
                    <div class="stat-card">
                        <h3>Today's Visitors</h3>
                        <div class="value">${data.today.unique_visitors}</div>
                        <div class="subtitle">${data.today.new_visitors} new, ${data.today.returning_visitors} returning</div>
                    </div>
                    <div class="stat-card">
                        <h3>Today's Visits</h3>
                        <div class="value">${data.today.total_visits}</div>
                        <div class="subtitle">Total visits today</div>
                    </div>
                </div>

                <div class="panels">
                    <!-- Last 7 Days Chart -->
                    <div class="panel">
                        <h2><span>📊</span> Last 7 Days</h2>
                        <div class="chart-container">
                            ${last7Days.map(day => {
                                const height = (day.total_visits / maxVisits) * 100;
                                const date = new Date(day.stat_date).toLocaleDateString('en-US', { weekday: 'short' });
                                return `
                                    <div class="chart-bar" style="height: ${Math.max(height, 5)}%">
                                        <div class="tooltip">${date}: ${day.total_visits} visits</div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                        <div class="chart-labels">
                            ${last7Days.map(day => {
                                const date = new Date(day.stat_date).toLocaleDateString('en-US', { weekday: 'short' });
                                return `<span>${date}</span>`;
                            }).join('')}
                        </div>
                    </div>

                    <!-- Device Breakdown -->
                    <div class="panel">
                        <h2><span>📱</span> Devices</h2>
                        <div class="device-chart">
                            <div class="device-item">
                                <div class="device-icon">📱</div>
                                <div class="device-count">${mobileCount}</div>
                                <div class="device-label">Mobile</div>
                            </div>
                            <div class="device-item">
                                <div class="device-icon">💻</div>
                                <div class="device-count">${desktopCount}</div>
                                <div class="device-label">Desktop</div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Visitors -->
                    <div class="panel" style="grid-column: span 2;">
                        <h2><span>👥</span> Recent Visitors</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Visitor ID</th>
                                    <th>Device</th>
                                    <th>Visits</th>
                                    <th>First Visit</th>
                                    <th>Last Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.recent_visitors.slice(0, 10).map(v => `
                                    <tr>
                                        <td class="visitor-id">${v.visitor_id.substring(0, 20)}...</td>
                                        <td>
                                            <span class="badge ${v.is_mobile ? 'badge-mobile' : 'badge-desktop'}">
                                                ${v.is_mobile ? '📱 Mobile' : '💻 Desktop'}
                                            </span>
                                        </td>
                                        <td>${v.visit_count}</td>
                                        <td>${new Date(v.first_visit_at).toLocaleDateString()}</td>
                                        <td>${new Date(v.last_visit_at).toLocaleString()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>

                    <!-- Hourly Traffic Today -->
                    <div class="panel" style="grid-column: span 2;">
                        <h2><span>🕐</span> Hourly Traffic Today</h2>
                        <div class="chart-container" style="height: 150px;">
                            ${Array.from({length: 24}, (_, i) => {
                                const hourData = data.hourly_today.find(h => parseInt(h.hour) === i);
                                const visits = hourData ? parseInt(hourData.visits) : 0;
                                const maxHourly = Math.max(...data.hourly_today.map(h => parseInt(h.visits)), 1);
                                const height = (visits / maxHourly) * 100;
                                return `
                                    <div class="chart-bar" style="height: ${visits > 0 ? Math.max(height, 10) : 5}%; opacity: ${visits > 0 ? 1 : 0.3}">
                                        <div class="tooltip">${i}:00 - ${visits} visits</div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                        <div class="chart-labels" style="justify-content: space-between;">
                            <span>12am</span>
                            <span>6am</span>
                            <span>12pm</span>
                            <span>6pm</span>
                            <span>11pm</span>
                        </div>
                    </div>
                </div>
            `;
        }

        // Load stats on page load
        loadStats();

        // Auto-refresh every 30 seconds
        setInterval(loadStats, 30000);
    </script>
</body>
</html>
