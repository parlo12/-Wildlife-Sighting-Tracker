#!/bin/bash

# Wildlife Sighting Tracker - Server Setup Script
# Run this script on your Digital Ocean droplet after initial creation

set -e

echo "🚀 Starting Wildlife Sighting Tracker setup..."

# Update system
echo "📦 Updating system packages..."
apt update && apt upgrade -y

# Install Docker
echo "🐳 Installing Docker..."
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
systemctl enable docker
systemctl start docker
rm get-docker.sh

# Install Docker Compose
echo "🐳 Installing Docker Compose..."
apt install docker-compose -y

# Install other dependencies
echo "📦 Installing additional packages..."
apt install -y git nginx certbot python3-certbot-nginx ufw fail2ban unattended-upgrades

# Create deploy user
echo "👤 Creating deploy user..."
if ! id "deploy" &>/dev/null; then
    adduser --disabled-password --gecos "" deploy
    usermod -aG docker deploy
    usermod -aG sudo deploy
    
    # Set up SSH for deploy user
    mkdir -p /home/deploy/.ssh
    if [ -f ~/.ssh/authorized_keys ]; then
        cp ~/.ssh/authorized_keys /home/deploy/.ssh/
        chown -R deploy:deploy /home/deploy/.ssh
        chmod 700 /home/deploy/.ssh
        chmod 600 /home/deploy/.ssh/authorized_keys
    fi
fi

# Create application directory
echo "📁 Creating application directory..."
mkdir -p /var/www/wildlife-tracker
chown -R deploy:deploy /var/www/wildlife-tracker

# Configure firewall
echo "🔒 Configuring firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# Configure fail2ban
echo "🔒 Configuring fail2ban..."
systemctl enable fail2ban
systemctl start fail2ban

# Configure automatic security updates
echo "🔒 Enabling automatic security updates..."
dpkg-reconfigure -plow unattended-upgrades

echo ""
echo "✅ Server setup complete!"
echo ""
echo "Next steps:"
echo "1. Configure Nginx (see DEPLOYMENT_GUIDE.md)"
echo "2. Set up SSL with Certbot"
echo "3. Clone your repository to /var/www/wildlife-tracker"
echo "4. Configure .env.production file"
echo "5. Start Docker containers"
echo ""
echo "🎉 Your server is ready for deployment!"
