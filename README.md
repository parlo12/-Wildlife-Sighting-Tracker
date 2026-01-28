# Wildlife Sighting Tracker

![Wildlife Sighting Tracker](https://img.shields.io/badge/status-active-success.svg)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

A real-time wildlife sighting tracking application with GPS-tagged photo uploads, interactive maps, and automatic expiration system.

## 🌟 Features

- **📸 GPS Photo Uploads** - Upload photos with automatic GPS coordinate extraction from EXIF data
- **🗺️ Interactive Map** - Real-time map showing all sightings with Leaflet.js
- **⏰ Auto-Expiration** - Sightings expire after 4 hours with user confirmation prompts
- **📍 Location Services** - Automatic user location detection (HTTPS required)
- **🔒 Secure** - HTTPS/SSL enabled, secure database connections
- **📱 Mobile Friendly** - Responsive design works on desktop and mobile
- **🚀 CI/CD Pipeline** - Automatic deployment via GitHub Actions

## 🎯 Live Demo

**Production:** [https://koteglasye.com](https://koteglasye.com)

## 🛠️ Tech Stack

- **Frontend:** HTML5, CSS3, JavaScript (Vanilla)
- **Map:** Leaflet.js
- **Backend:** PHP 8.2
- **Database:** PostgreSQL 16 + PostGIS
- **Server:** Nginx + PHP-FPM
- **Containerization:** Docker & Docker Compose
- **CI/CD:** GitHub Actions
- **Hosting:** Digital Ocean
- **SSL:** Let's Encrypt (Certbot)

## 📋 Prerequisites

- PHP 8.2+
- PostgreSQL 16+ with PostGIS extension
- Docker & Docker Compose
- Nginx
- Domain name with SSL certificate

## 🚀 Quick Start (Local Development)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/wildlife-sighting-tracker.git
   cd wildlife-sighting-tracker
   ```

2. **Start the database:**
   ```bash
   docker start wildlife-postgis
   ```

3. **Run database migrations:**
   ```bash
   docker exec -i wildlife-postgis psql -U postgres -d wildlife_map < schema_update.sql
   ```

4. **Start PHP server:**
   ```bash
   php -d upload_max_filesize=50M -d post_max_size=60M -S localhost:8000
   ```

5. **Open in browser:**
   ```
   http://localhost:8000/index.html
   ```

## 🌐 Production Deployment

See **[DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)** for complete step-by-step deployment instructions to Digital Ocean with CI/CD.

### Quick Summary:

1. Set up Digital Ocean Droplet (Ubuntu 22.04)
2. Configure domain DNS (A records)
3. Install dependencies (Docker, Nginx, Certbot)
4. Set up SSL certificate
5. Configure GitHub secrets
6. Push to GitHub → Automatic deployment! 🎉

## 📁 Project Structure

```
wildlife-sighting-tracker/
├── .github/
│   └── workflows/
│       └── deploy.yml           # GitHub Actions CI/CD
├── scripts/
│   ├── setup-server.sh          # Server setup automation
│   ├── backup-db.sh             # Database backup script
│   └── deploy.sh                # Manual deployment script
├── index.html                   # Main web interface
├── app.js                       # Frontend JavaScript
├── config.php                   # PHP configuration
├── upload_sighting.php          # Upload API endpoint
├── list_sightings.php           # List sightings API
├── confirm_sighting.php         # Confirm sighting API
├── check_expirations.php        # Expiration check API
├── schema_update.sql            # Database schema
├── docker-compose.yml           # Docker configuration
├── .env.production              # Production environment
├── php.ini                      # PHP configuration
└── php-fpm.conf                 # PHP-FPM configuration
```

## 🔌 API Endpoints

### `POST /upload_sighting.php`
Upload a photo with GPS data
```json
{
  "sighting_id": 1,
  "image_url": "https://koteglasye.com/uploads/sighting_xxx.jpg",
  "lat": 37.1234567,
  "lon": -122.1234567
}
```

### `GET /list_sightings.php?limit=500`
Get all active sightings
```json
{
  "data": [
    {
      "id": 1,
      "image_url": "https://koteglasye.com/uploads/sighting_xxx.jpg",
      "lat": 37.1234567,
      "lon": -122.1234567,
      "taken_at": "2026-01-15T08:00:36Z",
      "expires_at": "2026-01-15T12:00:36Z"
    }
  ]
}
```

### `POST /confirm_sighting.php`
Extend sighting expiration by 4 hours
```json
{
  "sighting_id": 123
}
```

### `GET /check_expirations.php`
Check and cleanup expired sightings
```json
{
  "expiring_soon": [...],
  "deleted_ids": [121, 122],
  "deleted_count": 2
}
```

## 🔒 Security Features

- HTTPS/SSL encryption
- Secure database passwords
- UFW firewall configured
- Fail2ban protection
- Automatic security updates
- Input validation and sanitization
- CORS protection
- SQL injection prevention (PDO prepared statements)

## 💾 Backup & Maintenance

**Automatic daily backups** configured via cron:
```bash
0 2 * * * /home/deploy/backup-db.sh
```

Backups stored in `/home/deploy/backups/` with 7-day retention.

## 🐛 Troubleshooting

See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md#-troubleshooting) for common issues and solutions.

## 📝 License

This project is licensed under the MIT License.

## 👥 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📞 Support

For issues and questions, please open an issue on GitHub.

## 🙏 Acknowledgments

- Leaflet.js for the amazing mapping library
- OpenStreetMap for map tiles
- PostGIS for spatial database capabilities
- Digital Ocean for hosting

---

**Made with ❤️ for wildlife conservation**
