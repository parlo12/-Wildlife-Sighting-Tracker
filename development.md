# Wildlife Sighting Tracker - Development Guide

## Project Overview
Wildlife sighting tracking application with real-time map display, auto-expiration, and user identification.

**Live URL**: https://koteglasye.com  
**Repository**: https://github.com/parlo12/-Wildlife-Sighting-Tracker

---

## Server Access

### SSH Connection
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
```

**Server Details:**
- **IP Address**: 68.183.56.123
- **Domain**: koteglasye.com (via GoDaddy DNS)
- **OS**: Ubuntu 22.04
- **User**: deploy
- **SSH Key**: ~/.ssh/wildlife_tracker_do

### Server Directory Structure
```
/var/www/wildlife-tracker/
├── app.js                    # Frontend JavaScript
├── index.html                # Main UI
├── upload_sighting.php       # API: Submit sighting
├── list_sightings.php        # API: Get all sightings
├── check_expirations.php     # API: Check for expired sightings
├── confirm_sighting.php      # API: Confirm sighting still active
├── config.php                # Database configuration
├── .env                      # Environment variables (NOT in git)
├── docker-compose.yml        # Container orchestration
├── Dockerfile.php            # PHP-FPM custom image
├── nginx.conf                # Nginx configuration
├── logs/                     # Application logs
└── uploads/                  # Upload directory (empty since images removed)
```

---

## Database Access

### PostgreSQL with PostGIS
```bash
# Access via Docker
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
docker exec -it wildlife-postgis psql -U postgres -d wildlife_map

# Or direct connection (from server)
psql -U postgres -d wildlife_map -h localhost -p 5432
```

**Credentials:**
- **Host**: postgis (Docker service name) / localhost (external)
- **Port**: 5432
- **Database**: wildlife_map
- **User**: postgres
- **Password**: wildlife_secure_2024

### Database Schema
```sql
-- Main table
CREATE TABLE sightings (
    id SERIAL PRIMARY KEY,
    species VARCHAR(100) NOT NULL,
    location GEOMETRY(Point, 4326) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    user_id VARCHAR(100),              -- Browser-generated anonymous ID
    device_token VARCHAR(255),         -- For push notifications (unused)
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE,
    last_confirmed_at TIMESTAMP WITH TIME ZONE
);

-- Indexes
CREATE INDEX idx_sightings_location ON sightings USING GIST(location);
CREATE INDEX idx_sightings_expires_at ON sightings(expires_at);
CREATE INDEX idx_sightings_created_at ON sightings(created_at DESC);

-- Auto-expiration trigger (4 hours)
CREATE OR REPLACE FUNCTION set_expiration_on_insert()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.expires_at IS NULL THEN
        NEW.expires_at := NEW.created_at + INTERVAL '4 hours';
    END IF;
    IF NEW.last_confirmed_at IS NULL THEN
        NEW.last_confirmed_at := NEW.created_at;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER set_expiration_trigger
    BEFORE INSERT ON sightings
    FOR EACH ROW
    EXECUTE FUNCTION set_expiration_on_insert();
```

---

## Environment Configuration

### .env File (Server Only)
**Location**: `/var/www/wildlife-tracker/.env`

**CRITICAL**: This file is NOT tracked in git and must be manually maintained.

```bash
# Database Configuration
DB_HOST=postgis           # MUST be 'postgis' (Docker service name)
DB_PORT=5432
DB_NAME=wildlife_map
DB_USER=postgres
DB_PASS=wildlife_secure_2024

# Application Configuration
BASE_URL=https://koteglasye.com
UPLOADS_DIR=/var/www/wildlife-tracker/uploads
LOG_FILE=/var/www/wildlife-tracker/logs/access.log

# Notification Settings (currently unused)
FCM_SERVER_KEY=
FCM_NOTIFICATION_TITLE=New Wildlife Sighting!
FCM_NOTIFICATION_BODY=A new sighting has been reported near you
FCM_V1_PROJECT_ID=
FCM_V1_CLIENT_EMAIL=
FCM_V1_PRIVATE_KEY=

# CORS Settings
CORS_ALLOWED_ORIGINS=*
CORS_ALLOWED_METHODS=GET,POST,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization
CORS_ALLOW_CREDENTIALS=false

# Geospatial Settings
RADIUS_METERS=48280   # 30 miles for nearby notifications
```

### Known Issue: DB_HOST Reversion
**Problem**: After `git pull`, the .env file sometimes has `DB_HOST=db` instead of `DB_HOST=postgis`

**Solution**: Always run this command after deployment:
```bash
sed -i 's/DB_HOST=db/DB_HOST=postgis/' .env
docker-compose restart php-fpm
```

---

## Deployment Process

### Standard Deployment Workflow

**From Local Machine:**
```bash
# 1. Make changes locally
cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe

# 2. Test changes (if applicable)

# 3. Commit and push
git add .
git commit -m "Description of changes"
git push origin main

# 4. Deploy to production (ONE COMMAND - includes .env fix)
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 \
  "cd /var/www/wildlife-tracker && \
   git reset --hard origin/main && \
   sed -i 's/DB_HOST=db/DB_HOST=postgis/' .env && \
   docker-compose restart php-fpm"

# 5. Verify deployment
curl -s https://koteglasye.com/list_sightings.php | python3 -m json.tool
```

### Emergency Rollback
```bash
# 1. SSH into server
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123

# 2. Check git log
cd /var/www/wildlife-tracker
git log --oneline -10

# 3. Rollback to specific commit
git reset --hard <commit-hash>

# 4. Fix .env and restart
sed -i 's/DB_HOST=db/DB_HOST=postgis/' .env
docker-compose restart php-fpm
```

### Deploy New Database Schema
```bash
# 1. Create SQL file locally (e.g., schema_update.sql)

# 2. Copy to server
scp -i ~/.ssh/wildlife_tracker_do schema_update.sql \
  deploy@68.183.56.123:/var/www/wildlife-tracker/

# 3. Execute on server
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 \
  "docker exec -i wildlife-postgis psql -U postgres -d wildlife_map \
   < /var/www/wildlife-tracker/schema_update.sql"
```

---

## Docker Services

### Container Management
```bash
# View running containers
docker-compose ps

# View logs
docker-compose logs -f php-fpm
docker-compose logs -f postgis
docker-compose logs -f nginx

# Restart services
docker-compose restart php-fpm
docker-compose restart postgis
docker-compose restart nginx

# Rebuild containers
docker-compose down
docker-compose up -d --build

# Access container shell
docker exec -it wildlife-php-fpm sh
docker exec -it wildlife-postgis bash
```

### Service Details
- **nginx**: Port 80/443, reverse proxy + SSL termination
- **php-fpm**: PHP 8.2-FPM, exposes port 9000 to nginx
- **postgis**: PostgreSQL 16 + PostGIS 3.4, port 5432

### Network
- **Name**: wildlife-network
- **Type**: bridge
- **Services**: All three containers communicate internally

---

## SSL Certificate

### Let's Encrypt Configuration
```bash
# Certificate location
/etc/letsencrypt/live/koteglasye.com/

# Auto-renewal (configured via certbot)
# Certificates renew automatically every 90 days
```

### Manual Renewal (if needed)
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
sudo certbot renew
sudo nginx -t
sudo systemctl reload nginx
```

---

## Application Architecture

### Frontend
- **Framework**: Vanilla JavaScript
- **Map**: Leaflet.js 1.9.4
- **Styling**: Inline CSS with purple gradient theme
- **User ID**: Generated via localStorage (anonymous)

### Backend
- **Language**: PHP 8.2
- **Database**: PostgreSQL 16 + PostGIS 3.4
- **Server**: Nginx 1.18.0
- **Containerization**: Docker Compose

### Key Features
1. **User Identification**: Browser-based anonymous ID (localStorage)
2. **Auto-Expiration**: Sightings expire after 4 hours
3. **Confirmation System**: Users can confirm sighting still active
4. **Geospatial**: PostGIS for location storage and queries
5. **Responsive Markers**: Text wraps, random offset to prevent stacking
6. **Auto-Fit Bounds**: Map zooms to show all markers

---

## API Endpoints

### GET /list_sightings.php
**Parameters**: `?limit=500` (optional)  
**Returns**: JSON array of all active sightings
```json
{
  "data": [
    {
      "id": 7,
      "species": "mwen wè glas nan zon sa",
      "user_id": "user_1738107234_k7x9p2m1q",
      "created_at": "2026-01-28 23:48:42.669662+00",
      "expires_at": "2026-01-29 03:48:42.669662+00",
      "last_confirmed_at": "2026-01-28 23:48:42.669662+00",
      "latitude": "27.039253910529",
      "longitude": "-82.310130337356"
    }
  ]
}
```

### POST /upload_sighting.php
**Parameters**: 
- `species` (string, required)
- `lat` (float, required)
- `lon` (float, required)
- `user_id` (string, optional)

**Returns**: 
```json
{
  "sighting_id": 7,
  "lat": 27.0392539,
  "lon": -82.3101304,
  "species": "Ice cube sighting"
}
```

### POST /confirm_sighting.php
**Parameters**: 
- `sighting_id` (int, required)

**Returns**: 
```json
{
  "success": true,
  "expires_at": "2026-01-29 07:48:42.669662+00"
}
```

### GET /check_expirations.php
**Returns**: JSON of expired sighting IDs requiring confirmation
```json
{
  "expiring": [
    {
      "id": 5,
      "species": "Glas la tou fè attansyon",
      "expires_at": "2026-01-29 03:48:42.669662+00"
    }
  ]
}
```

---

## Troubleshooting

### Application Not Loading
```bash
# Check if containers are running
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
docker-compose ps

# Check nginx logs
docker-compose logs nginx | tail -50

# Check PHP-FPM logs
docker-compose logs php-fpm | tail -50

# Verify .env file
cat .env | grep DB_HOST
# Should show: DB_HOST=postgis
```

### Database Connection Errors
```bash
# 1. Check .env file
cat /var/www/wildlife-tracker/.env | grep DB_HOST

# 2. If shows DB_HOST=db, fix it:
sed -i 's/DB_HOST=db/DB_HOST=postgis/' .env
docker-compose restart php-fpm

# 3. Test database connection
docker exec -it wildlife-postgis psql -U postgres -d wildlife_map -c "SELECT COUNT(*) FROM sightings;"
```

### Markers Not Showing
```bash
# 1. Check API response
curl -s https://koteglasye.com/list_sightings.php | python3 -m json.tool

# 2. Check browser console (F12)
# Look for JavaScript errors

# 3. Verify database has data
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
docker exec -it wildlife-postgis psql -U postgres -d wildlife_map
SELECT id, species, latitude, longitude FROM sightings;
```

### SSL Certificate Issues
```bash
# Check certificate expiry
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123
sudo certbot certificates

# Test renewal
sudo certbot renew --dry-run

# Force renewal
sudo certbot renew --force-renewal
sudo systemctl reload nginx
```

---

## Development Tips

### Local Development
```bash
# The app currently runs only on production server
# For local testing, you would need:
# 1. Docker + Docker Compose installed
# 2. Copy docker-compose.yml locally
# 3. Update .env with local settings
# 4. Run: docker-compose up -d
```

### Testing Changes Before Deployment
```bash
# 1. Make changes in local files
# 2. Use browser's "Inspect" (F12) to test JS changes directly
# 3. Commit and deploy when ready
```

### Common Git Operations
```bash
# Check current branch
git branch

# View recent commits
git log --oneline -10

# Create feature branch (optional)
git checkout -b feature/new-feature
git push origin feature/new-feature

# Merge to main
git checkout main
git merge feature/new-feature
git push origin main
```

---

## Quick Reference Commands

### Deploy Code Changes
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 "cd /var/www/wildlife-tracker && git reset --hard origin/main && sed -i 's/DB_HOST=db/DB_HOST=postgis/' .env && docker-compose restart php-fpm"
```

### Check Application Status
```bash
curl -s https://koteglasye.com/list_sightings.php | python3 -m json.tool
```

### View Server Logs
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 "cd /var/www/wildlife-tracker && docker-compose logs -f --tail=100 php-fpm"
```

### Database Query
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 "docker exec -it wildlife-postgis psql -U postgres -d wildlife_map -c 'SELECT COUNT(*) FROM sightings;'"
```

### Restart All Services
```bash
ssh -i ~/.ssh/wildlife_tracker_do deploy@68.183.56.123 "cd /var/www/wildlife-tracker && docker-compose restart"
```

---

## Contact & Resources

**GitHub Repository**: https://github.com/parlo12/-Wildlife-Sighting-Tracker  
**Live Application**: https://koteglasye.com  
**Domain Registrar**: GoDaddy (koteglasye.com)  
**Hosting**: Digital Ocean Droplet  

**Technology Stack:**
- Frontend: HTML5, CSS3, JavaScript (Leaflet.js)
- Backend: PHP 8.2-FPM
- Database: PostgreSQL 16 + PostGIS 3.4
- Web Server: Nginx 1.18.0
- Containerization: Docker + Docker Compose
- SSL: Let's Encrypt (auto-renewal)

---

*Last Updated: January 28, 2026*
