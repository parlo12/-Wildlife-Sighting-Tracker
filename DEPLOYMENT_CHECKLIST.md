# Quick Start Checklist - Wildlife Sighting Tracker Deployment

## ✅ Pre-Deployment Checklist

- [ ] Digital Ocean account created
- [ ] Domain koteglasye.com accessible in GoDaddy
- [ ] GitHub account ready
- [ ] SSH key generated locally
- [ ] Git installed on local machine

## 📝 Step-by-Step Execution Order

### Phase 1: Repository Setup (15 minutes)

1. **Initialize Git locally:**
   ```bash
   cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe
   git init
   git add .
   git commit -m "Initial commit - Wildlife Sighting Tracker"
   ```

2. **Create GitHub repository:**
   - Visit: https://github.com/new
   - Name: `wildlife-sighting-tracker`
   - Private repository
   - Don't initialize with README

3. **Push to GitHub:**
   ```bash
   git remote add origin https://github.com/YOUR_USERNAME/wildlife-sighting-tracker.git
   git branch -M main
   git push -u origin main
   ```

### Phase 2: Digital Ocean Setup (20 minutes)

4. **Create Droplet:**
   - Log in: https://cloud.digitalocean.com
   - Create → Droplets
   - Ubuntu 22.04 LTS
   - $12/month plan (2GB RAM)
   - Add your SSH key
   - Hostname: `wildlife-tracker`

5. **Note your droplet IP:** `_________________`

### Phase 3: Domain Configuration (5-30 minutes)

6. **Configure DNS in GoDaddy:**
   - Log in: https://dcc.godaddy.com
   - My Products → koteglasye.com → DNS
   - Add A records:
     * Type: A, Name: @, Value: YOUR_DROPLET_IP
     * Type: A, Name: www, Value: YOUR_DROPLET_IP
   - **Wait 10-30 minutes for propagation**

7. **Verify DNS:**
   ```bash
   nslookup koteglasye.com
   # Should return your droplet IP
   ```

### Phase 4: Server Setup (30 minutes)

8. **SSH into droplet:**
   ```bash
   ssh root@YOUR_DROPLET_IP
   ```

9. **Run automated setup script:**
   ```bash
   curl -O https://raw.githubusercontent.com/YOUR_USERNAME/wildlife-sighting-tracker/main/scripts/setup-server.sh
   chmod +x setup-server.sh
   ./setup-server.sh
   ```

10. **Configure Nginx:**
    ```bash
    nano /etc/nginx/sites-available/wildlife-tracker
    ```
    Copy configuration from DEPLOYMENT_GUIDE.md → Step 5.1

11. **Enable site:**
    ```bash
    ln -s /etc/nginx/sites-available/wildlife-tracker /etc/nginx/sites-enabled/
    nginx -t
    systemctl restart nginx
    ```

12. **Install SSL certificate:**
    ```bash
    certbot --nginx -d koteglasye.com -d www.koteglasye.com
    ```
    Follow prompts, choose redirect HTTP to HTTPS

### Phase 5: Application Deployment (20 minutes)

13. **Switch to deploy user:**
    ```bash
    su - deploy
    cd /var/www/wildlife-tracker
    ```

14. **Clone repository:**
    ```bash
    git clone https://github.com/YOUR_USERNAME/wildlife-sighting-tracker.git .
    ```

15. **Configure environment:**
    ```bash
    cp .env.production .env
    nano .env
    # Update DB_PASSWORD to a secure password
    ```

16. **Start database:**
    ```bash
    docker-compose up -d postgis
    sleep 10
    docker exec -i wildlife-postgis psql -U postgres -d wildlife_map < schema_update.sql
    ```

17. **Start PHP-FPM:**
    ```bash
    docker-compose up -d php-fpm
    ```

18. **Set permissions:**
    ```bash
    chmod 755 /var/www/wildlife-tracker
    mkdir -p uploads logs
    chmod -R 755 uploads logs
    chown -R www-data:www-data uploads logs
    ```

### Phase 6: GitHub Actions Setup (10 minutes)

19. **Configure GitHub Secrets:**
    - Go to: https://github.com/YOUR_USERNAME/wildlife-sighting-tracker/settings/secrets/actions
    - Add secrets:
      * `DROPLET_IP` = Your droplet IP
      * `DROPLET_USER` = deploy
      * `SSH_PRIVATE_KEY` = Contents of `~/.ssh/id_ed25519` (from local Mac)
      * `DB_PASSWORD` = Your database password

20. **Get SSH private key:**
    ```bash
    # On your Mac:
    cat ~/.ssh/id_ed25519
    # Copy entire contents including BEGIN/END lines
    ```

### Phase 7: Testing & Verification (10 minutes)

21. **Test deployment:**
    - Visit: https://koteglasye.com
    - Should see the map
    - Allow location permission
    - Test upload functionality

22. **Check logs if needed:**
    ```bash
    ssh deploy@YOUR_DROPLET_IP
    tail -f /var/log/nginx/wildlife-tracker-error.log
    tail -f /var/www/wildlife-tracker/logs/access.log
    docker-compose logs -f
    ```

23. **Test CI/CD:**
    ```bash
    # On your Mac:
    cd /Users/rolflouisdor/Desktop/RMH-Real-Estate/Kashe
    echo "# Test" >> README.md
    git add .
    git commit -m "Test CI/CD pipeline"
    git push origin main
    # Go to GitHub → Actions tab to watch deployment
    ```

### Phase 8: Production Hardening (15 minutes)

24. **Set up automatic backups:**
    ```bash
    ssh deploy@YOUR_DROPLET_IP
    cp scripts/backup-db.sh /home/deploy/
    chmod +x /home/deploy/backup-db.sh
    crontab -e
    # Add: 0 2 * * * /home/deploy/backup-db.sh
    ```

25. **Final security check:**
    ```bash
    ufw status
    systemctl status fail2ban
    certbot certificates
    ```

## 🎉 Deployment Complete!

Your application is now live at:
- **https://koteglasye.com**
- **https://www.koteglasye.com**

## 🔄 Future Deployments

Simply push to GitHub:
```bash
git add .
git commit -m "Your changes"
git push origin main
# Automatically deploys!
```

## 📞 Need Help?

Refer to [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) for detailed troubleshooting.

## 💡 Pro Tips

- Monitor GitHub Actions for deployment status
- Check logs regularly for errors
- Test on staging before major changes
- Keep backups in a safe location
- Update SSL certificates before expiration (auto-renewed)
- Monitor server resources with `htop`

---

**Total Estimated Time: 2-3 hours** (including DNS propagation wait time)
