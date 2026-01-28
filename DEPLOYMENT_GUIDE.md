# Wildlife Sighting Tracker - Deployment Guide
## Deploying to Digital Ocean with GitHub CI/CD

**Domain:** koteglasye.com  
**Hosting:** Digital Ocean  
**CI/CD:** GitHub Actions  
**Database:** PostgreSQL + PostGIS (Docker)  

---

## 📋 Prerequisites Checklist

- [ ] GitHub account
- [ ] Digital Ocean account with payment method
- [ ] GoDaddy domain: koteglasye.com
- [ ] Git installed locally
- [ ] SSH key pair generated

---

## Step 1: Prepare Your GitHub Repository

### 1.1 Initialize Git Repository (if not already done)

```bash
cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe
git init
git add .
git commit -m "Initial commit - Wildlife Sighting Tracker"
```

### 1.2 Create GitHub Repository

1. Go to https://github.com/new
2. Repository name: `wildlife-sighting-tracker` (or your choice)
3. Description: "Wildlife sighting tracking app with GPS photo uploads"
4. **Keep it Private** (recommended) or Public
5. Do NOT initialize with README (we already have files)
6. Click "Create repository"

### 1.3 Push to GitHub

```bash
# Replace with your GitHub username
git remote add origin https://github.com/YOUR_USERNAME/wildlife-sighting-tracker.git
git branch -M main
git push -u origin main
```

---

## Step 2: Set Up Digital Ocean Droplet

### 2.1 Create Droplet

1. Log in to https://cloud.digitalocean.com
2. Click "Create" → "Droplets"
3. **Choose Image:**
   - Distribution: **Ubuntu 22.04 (LTS) x64**
4. **Choose Size:**
   - Basic plan
   - CPU: Regular with SSD
   - **$12/month** (2 GB RAM, 1 vCPU, 50 GB SSD) - Recommended minimum
   - OR **$18/month** (2 GB RAM, 2 vCPU, 60 GB SSD) - Better performance
5. **Choose Region:**
   - Select region closest to your users (e.g., New York, San Francisco)
6. **Authentication:**
   - Select "SSH Key" (recommended)
   - Click "New SSH Key"
   - Paste your SSH public key (see below if you don't have one)
7. **Hostname:** `wildlife-tracker`
8. Click "Create Droplet"

**Generate SSH Key (if needed):**
```bash
ssh-keygen -t ed25519 -C "your_email@example.com"
# Press Enter to accept default location
# Enter passphrase (optional but recommended)
# Copy public key:
cat ~/.ssh/id_ed25519.pub
```

### 2.2 Note Your Droplet IP

Once created, copy your droplet's **IP address** (e.g., `164.92.123.456`)

---

## Step 3: Configure Domain DNS (GoDaddy)

### 3.1 Add DNS Records

1. Log in to https://dcc.godaddy.com
2. Go to "My Products" → Find `koteglasye.com` → Click "DNS"
3. **Add/Edit A Records:**

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | @ | YOUR_DROPLET_IP | 600 |
| A | www | YOUR_DROPLET_IP | 600 |

4. Click "Save"
5. **Wait 5-30 minutes** for DNS propagation

### 3.2 Verify DNS (after waiting)

```bash
# Should return your droplet IP
nslookup koteglasye.com
nslookup www.koteglasye.com
```

---

## Step 4: Initial Server Setup

### 4.1 SSH into Your Droplet

```bash
ssh root@YOUR_DROPLET_IP
```

### 4.2 Update System

```bash
apt update && apt upgrade -y
```

### 4.3 Install Required Software

```bash
# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
systemctl enable docker
systemctl start docker

# Install Docker Compose
apt install docker-compose -y

# Install Git
apt install git -y

# Install Nginx
apt install nginx -y

# Install Certbot for SSL
apt install certbot python3-certbot-nginx -y
```

### 4.4 Create Deployment User

```bash
# Create non-root user for deployments
adduser deploy
usermod -aG docker deploy
usermod -aG sudo deploy

# Set up SSH for deploy user
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

### 4.5 Create Application Directory

```bash
mkdir -p /var/www/wildlife-tracker
chown -R deploy:deploy /var/www/wildlife-tracker
```

---

## Step 5: Configure Nginx

### 5.1 Create Nginx Configuration

```bash
nano /etc/nginx/sites-available/wildlife-tracker
```

**Paste this configuration:**

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name koteglasye.com www.koteglasye.com;

    root /var/www/wildlife-tracker;
    index index.html index.php;

    # Max upload size for images
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Serve uploaded images
    location /uploads/ {
        alias /var/www/wildlife-tracker/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Logging
    access_log /var/log/nginx/wildlife-tracker-access.log;
    error_log /var/log/nginx/wildlife-tracker-error.log;
}
```

### 5.2 Enable Site

```bash
ln -s /etc/nginx/sites-available/wildlife-tracker /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

---

## Step 6: Set Up SSL Certificate (HTTPS)

### 6.1 Obtain SSL Certificate

```bash
certbot --nginx -d koteglasye.com -d www.koteglasye.com
```

**Follow prompts:**
- Enter email address
- Agree to Terms of Service
- Choose whether to share email
- Select option 2: Redirect HTTP to HTTPS

### 6.2 Test Auto-Renewal

```bash
certbot renew --dry-run
```

**✅ Now your site is accessible via HTTPS and geolocation will work!**

---

## Step 7: Set Up Database Container

### 7.1 Create Docker Compose File

```bash
mkdir -p /var/www/wildlife-tracker
cd /var/www/wildlife-tracker
nano docker-compose.yml
```

**Paste the configuration from `docker-compose.yml` in this repo**

### 7.2 Create Environment File

```bash
nano .env.production
```

**Paste the configuration from `.env.production` in this repo**

### 7.3 Start Database

```bash
docker-compose up -d
```

### 7.4 Initialize Database

```bash
# Wait 10 seconds for DB to start
sleep 10

# Run schema
docker exec -i wildlife-postgis psql -U postgres -d wildlife_map < schema_update.sql
```

---

## Step 8: GitHub Actions Setup

### 8.1 Create GitHub Secrets

1. Go to your GitHub repo → Settings → Secrets and variables → Actions
2. Click "New repository secret" for each:

| Secret Name | Value |
|-------------|-------|
| `DROPLET_IP` | Your droplet IP address |
| `DROPLET_USER` | `deploy` |
| `SSH_PRIVATE_KEY` | Contents of `~/.ssh/id_ed25519` (private key) |
| `DB_PASSWORD` | Your secure database password |

**Get your SSH private key:**
```bash
cat ~/.ssh/id_ed25519
```

### 8.2 GitHub Actions Workflow

The `.github/workflows/deploy.yml` file in this repo handles:
- Automatic deployment on push to `main` branch
- Runs tests (if any)
- Deploys to Digital Ocean
- Restarts services

---

## Step 9: Deploy Application

### 9.1 Initial Manual Deployment

```bash
# SSH to droplet as deploy user
ssh deploy@YOUR_DROPLET_IP

cd /var/www/wildlife-tracker

# Clone repository
git clone https://github.com/YOUR_USERNAME/wildlife-sighting-tracker.git .

# Or if already cloned, pull latest
git pull origin main

# Set permissions
chmod 755 /var/www/wildlife-tracker
chmod -R 755 uploads/
chown -R www-data:www-data uploads/

# Create logs directory
mkdir -p logs
chmod -R 755 logs
chown -R www-data:www-data logs
```

### 9.2 Start PHP-FPM Container

```bash
docker-compose up -d
```

---

## Step 10: Verify Deployment

### 10.1 Test Application

1. Visit: https://koteglasye.com
2. Map should load
3. Click location permission (should work now with HTTPS!)
4. Test upload functionality
5. Verify sightings appear on map

### 10.2 Check Logs

```bash
# Nginx logs
tail -f /var/log/nginx/wildlife-tracker-error.log
tail -f /var/log/nginx/wildlife-tracker-access.log

# Application logs
tail -f /var/www/wildlife-tracker/logs/access.log

# Docker logs
docker-compose logs -f
```

---

## Step 11: Future Deployments (Automatic via GitHub Actions)

### How It Works:

1. Make changes to your code locally
2. Commit changes:
   ```bash
   git add .
   git commit -m "Your changes"
   ```
3. Push to GitHub:
   ```bash
   git push origin main
   ```
4. **GitHub Actions automatically:**
   - Detects the push
   - SSHs into your droplet
   - Pulls latest code
   - Restarts services
   - Deployment complete! 🎉

### Monitor Deployments:

- Go to your GitHub repo → Actions tab
- See deployment status in real-time

---

## 🔒 Security Checklist

- [ ] Change default PostgreSQL password
- [ ] Enable UFW firewall:
  ```bash
  ufw allow OpenSSH
  ufw allow 'Nginx Full'
  ufw enable
  ```
- [ ] Set up automatic security updates:
  ```bash
  apt install unattended-upgrades -y
  dpkg-reconfigure -plow unattended-upgrades
  ```
- [ ] Configure fail2ban:
  ```bash
  apt install fail2ban -y
  systemctl enable fail2ban
  systemctl start fail2ban
  ```
- [ ] Regular backups (see backup section)

---

## 💾 Backup Strategy

### Database Backup Script

```bash
nano /home/deploy/backup-db.sh
```

**Paste:**
```bash
#!/bin/bash
BACKUP_DIR="/home/deploy/backups"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

docker exec wildlife-postgis pg_dump -U postgres wildlife_map > $BACKUP_DIR/wildlife_map_$DATE.sql
find $BACKUP_DIR -name "*.sql" -mtime +7 -delete
```

**Set up cron:**
```bash
chmod +x /home/deploy/backup-db.sh
crontab -e
# Add: 0 2 * * * /home/deploy/backup-db.sh
```

---

## 🎯 Monitoring & Maintenance

### Check System Resources

```bash
# Disk space
df -h

# Memory usage
free -h

# Running containers
docker ps

# System logs
journalctl -xe
```

### Update Application

```bash
ssh deploy@YOUR_DROPLET_IP
cd /var/www/wildlife-tracker
git pull origin main
docker-compose restart
```

---

## 🐛 Troubleshooting

### App Not Loading
```bash
systemctl status nginx
docker-compose ps
nginx -t
```

### Database Connection Issues
```bash
docker logs wildlife-postgis
docker exec -it wildlife-postgis psql -U postgres -d wildlife_map
```

### SSL Certificate Issues
```bash
certbot certificates
certbot renew --force-renewal
```

### Upload Not Working
```bash
ls -la /var/www/wildlife-tracker/uploads/
chown -R www-data:www-data /var/www/wildlife-tracker/uploads/
chmod -R 755 /var/www/wildlife-tracker/uploads/
```

---

## 📞 Support Resources

- Digital Ocean Docs: https://docs.digitalocean.com
- GitHub Actions Docs: https://docs.github.com/actions
- Certbot Docs: https://certbot.eff.org
- Nginx Docs: https://nginx.org/en/docs

---

## ✅ Deployment Complete!

Your wildlife sighting tracker is now live at:
- **https://koteglasye.com**
- **https://www.koteglasye.com**

Features working:
- ✅ HTTPS/SSL encryption
- ✅ Geolocation (works on HTTPS)
- ✅ Photo uploads with GPS
- ✅ Auto-expiration after 4 hours
- ✅ Interactive map
- ✅ CI/CD pipeline
- ✅ Automatic deployments from GitHub

🎉 **Happy tracking!**
