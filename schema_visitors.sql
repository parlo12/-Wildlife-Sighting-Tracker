-- Visitor tracking table
CREATE TABLE IF NOT EXISTS visitors (
    id SERIAL PRIMARY KEY,
    visitor_id VARCHAR(100) NOT NULL,          -- Unique ID stored in browser localStorage
    ip_address VARCHAR(45),                     -- IPv4 or IPv6 address
    user_agent TEXT,                            -- Browser/device info
    screen_width INTEGER,                       -- Screen resolution width
    screen_height INTEGER,                      -- Screen resolution height
    language VARCHAR(20),                       -- Browser language
    platform VARCHAR(100),                      -- OS platform
    referrer TEXT,                              -- Where they came from
    is_mobile BOOLEAN DEFAULT FALSE,            -- Mobile device detection
    first_visit_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_visit_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    visit_count INTEGER DEFAULT 1               -- Number of visits from this visitor
);

-- Index for quick visitor lookup
CREATE INDEX IF NOT EXISTS idx_visitors_visitor_id ON visitors(visitor_id);
CREATE INDEX IF NOT EXISTS idx_visitors_first_visit ON visitors(first_visit_at);
CREATE INDEX IF NOT EXISTS idx_visitors_last_visit ON visitors(last_visit_at);

-- Table for individual page views (detailed tracking)
CREATE TABLE IF NOT EXISTS page_views (
    id SERIAL PRIMARY KEY,
    visitor_id VARCHAR(100) NOT NULL,
    page_url VARCHAR(500),
    visited_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    session_id VARCHAR(100)                     -- Group views by session
);

-- Index for page views
CREATE INDEX IF NOT EXISTS idx_page_views_visitor ON page_views(visitor_id);
CREATE INDEX IF NOT EXISTS idx_page_views_visited ON page_views(visited_at);

-- Daily stats summary table (for quick dashboard queries)
CREATE TABLE IF NOT EXISTS daily_stats (
    id SERIAL PRIMARY KEY,
    stat_date DATE NOT NULL UNIQUE,
    unique_visitors INTEGER DEFAULT 0,
    total_visits INTEGER DEFAULT 0,
    new_visitors INTEGER DEFAULT 0,
    returning_visitors INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_daily_stats_date ON daily_stats(stat_date);
