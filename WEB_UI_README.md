# Wildlife Sighting Tracker - Web UI

## New Features Added

### 1. Auto-Expiration System
- Sightings automatically expire after 4 hours
- Before deletion, users are prompted to confirm if the sighting is still in the area
- If confirmed, the sighting gets another 4 hours of life
- Expired sightings are automatically removed from the map

### 2. Web-Based Interface
A complete browser-based UI with:
- Interactive map using Leaflet.js
- Real-time sighting display with markers
- Photo upload capability
- Automatic location detection
- Mobile-friendly responsive design

## Setup Instructions

### 1. Database Schema Update
Run the schema update to add expiration tracking:

```bash
docker exec -i wildlife-postgis psql -U postgres -d wildlife_map < schema_update.sql
```

### 2. Start the Server
```bash
cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe
php -d upload_max_filesize=50M -d post_max_size=60M -d max_file_uploads=20 -S 0.0.0.0:8000
```

### 3. Access the Web App
Open your browser and navigate to:
```
http://100.110.176.220:8000/index.html
```

Or from the same computer:
```
http://localhost:8000/index.html
```

## New API Endpoints

### POST /confirm_sighting.php
Extends a sighting's expiration by 4 hours.

**Request:**
```json
{
  "sighting_id": 123
}
```

**Response:**
```json
{
  "success": true,
  "sighting_id": 123,
  "expires_at": "2026-01-28T14:30:00Z",
  "last_confirmed_at": "2026-01-28T10:30:00Z"
}
```

### GET /check_expirations.php
Returns sightings that are about to expire and deletes expired ones.

**Response:**
```json
{
  "expiring_soon": [
    {
      "id": 123,
      "image_path": "uploads/sighting_xxx.jpg",
      "lat": 37.1234567,
      "lon": -122.1234567,
      "taken_at": "2026-01-28T06:30:00Z",
      "expires_at": "2026-01-28T10:32:00Z"
    }
  ],
  "deleted_ids": [121, 122],
  "deleted_count": 2
}
```

## How It Works

### Expiration Flow
1. When a sighting is uploaded, it gets an expiration time of 4 hours from creation
2. The web app checks for expiring sightings every minute
3. When a sighting is within 5 minutes of expiration, a confirmation dialog appears
4. Users can click "Yes, Keep It" to extend for another 4 hours
5. If users click "No, Remove It" or don't respond, the sighting is deleted after expiration

### Upload Flow
1. User clicks "📷 Upload Sighting" button
2. Selects a photo from their device (must have GPS data in EXIF)
3. Preview is shown
4. Clicks "Upload" to submit
5. Photo is processed and added to the map with a 4-hour expiration

## Features

- **Interactive Map**: Zoom, pan, and click on markers to view sighting details
- **Image Previews**: Popup windows show the actual photos taken
- **Time Display**: Shows when each sighting was taken and when it expires
- **Auto-Refresh**: Map updates automatically when sightings expire
- **Responsive Design**: Works on desktop and mobile browsers
- **GPS Support**: Uses device location for map centering
- **Photo Upload**: Direct upload from browser with camera support on mobile

## Files Added/Modified

### New Files
- `index.html` - Main web interface
- `app.js` - JavaScript application logic
- `confirm_sighting.php` - API to extend sighting expiration
- `check_expirations.php` - API to check and cleanup expired sightings
- `schema_update.sql` - Database schema updates
- `WEB_UI_README.md` - This documentation

### Modified Files
- `list_sightings.php` - Now filters out expired sightings
- `upload_sighting.php` - Sets initial 4-hour expiration on new sightings

## Troubleshooting

### Map doesn't load
- Check that PHP server is running on port 8000
- Verify firewall allows connections
- Check browser console for errors

### Upload fails
- Ensure photo has GPS EXIF data (take new photo with location services on)
- Check file size (max 50MB)
- Verify uploads/ directory exists and is writable

### Expiration notifications don't appear
- Check that schema_update.sql was run successfully
- Verify check_expirations.php is accessible
- Check browser console for JavaScript errors

### Can't access from phone
- Ensure phone and server are on same network
- Update `.env` file if IP address changed
- Check macOS firewall settings allow port 8000

## Technical Notes

- Expiration checks run every 60 seconds
- Warning dialog appears when sighting has < 5 minutes remaining
- Confirmation extends expiration by 4 hours from current time
- Expired sightings are permanently deleted from database
- Map markers update automatically after deletions
