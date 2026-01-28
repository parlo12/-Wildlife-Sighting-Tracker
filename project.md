# Backend Setup for Mobile App Testing

## Overview
- Base URL (local over LAN): `http://100.110.176.220:8000`
- DB: PostgreSQL 16 + PostGIS (Docker container `wildlife-postgis`)
- Endpoints:
  - `POST /upload_sighting.php` — upload photo with GPS EXIF, store sighting, return nearby FCM tokens.
  - `GET /list_sightings.php?limit=500` — list latest sightings with lat/lon and image URLs for the map.
- CORS: Enabled for `GET, POST, OPTIONS`; origins from `.env` (`CORS_ALLOWED_ORIGINS`, defaults to `*`).

## Prerequisites
- Docker installed and running
- PHP CLI installed
- PostgreSQL/PostGIS container (`wildlife-postgis`) already created

## Quick Start
1) Start the database:
```bash
docker start wildlife-postgis
```
   - Connection: host `localhost`, port `5432`, db `wildlife_map`, user `postgres`, pass `wildlife`.

2) Ensure `.env` is set (already configured):
```env
BASE_URL=http://100.110.176.220:8000
UPLOAD_DIR=uploads
CORS_ALLOWED_ORIGINS=*
```
If your IP changes, update `.env` and restart PHP.

3) Create uploads directory:
```bash
mkdir -p /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe/uploads
chmod 755 /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe/uploads
```

4) Start PHP server (with higher upload limits for mobile photos):
```bash
cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe
php -d upload_max_filesize=50M -d post_max_size=60M -d max_file_uploads=20 -S 0.0.0.0:8000
```
   - For background: `nohup php -S 0.0.0.0:8000 >/tmp/wildlife-php.log 2>&1 &`

5) Verify backend:
```bash
curl http://100.110.176.220:8000/list_sightings.php
```
Expected: `{"data":[]}` (until sightings exist).

## Network Requirements
- iPhone and Mac on the same network (or VPN like Tailscale).
- macOS firewall must allow incoming on port 8000.
- Server must bind to `0.0.0.0` (already set).

Firewall checks (if needed):
```bash
# Temporarily disable firewall for testing
sudo /usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate off
# Or allow PHP specifically
sudo /usr/libexec/ApplicationFirewall/socketfilterfw --add /usr/bin/php
```

## API Endpoints
### POST /upload_sighting.php
- Request: `multipart/form-data` with `image` (raw photo with GPS EXIF; do not compress/resize).
- Success (200):
```json
{
  "sighting_id": 1,
  "image_path": "uploads/sighting_xxx.jpg",
  "image_url": "http://100.110.176.220:8000/uploads/sighting_xxx.jpg",
  "lat": 37.1234567,
  "lon": -122.1234567
}
```
- Errors: 400/422 for upload/EXIF issues; 500 for server errors.

### GET /list_sightings.php?limit=500
- Response:
```json
{
  "data": [
    {
      "id": 1,
      "image_path": "uploads/sighting_xxx.jpg",
      "image_url": "http://100.110.176.220:8000/uploads/sighting_xxx.jpg",
      "lat": 37.1234567,
      "lon": -122.1234567,
      "taken_at": "2026-01-15T08:00:36Z"
    }
  ]
}
```

## Troubleshooting
- App can’t load sightings: `curl http://100.110.176.220:8000/list_sightings.php`; ensure same network/firewall open; confirm `.env` BASE_URL matches.
- Upload GPS error: ensure camera location services on; take a new photo with GPS; backend rejects images without EXIF GPS.
- Images missing: verify `uploads/` exists and is writable; confirm `image_url` uses the correct IP/port.
- DB error: ensure container is running (`docker ps | grep wildlife`); verify `.env` DB creds.

## Mobile App Config Reference
- Backend URL should match `.env` BASE_URL: `http://100.110.176.220:8000`.
- On IP change, update both:
  1. Mobile app `config.dart` (`baseUrl`)
  2. Backend `.env` `BASE_URL`, then restart PHP server
