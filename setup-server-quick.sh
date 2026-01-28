#!/bin/bash
# Wildlife Tracker - Quick Server Setup Script
# Run this on your Digital Ocean droplet

set -e

echo "🚀 Starting Wildlife Tracker server setup..."

# Update system
echo "📦 Updating system packages..."
apt update && apt upgrade -y

# Install required packages (Docker already installed)
echo "📦 Installing Nginx, Certbot, and other tools..."
apt install -y nginx certbot python3-certbot-nginx git ufw fail2ban

# Configure firewall
echo "🔒 Configuring firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# Create deploy user
echo "👤 Creating deploy user..."
if ! id "deploy" &>/dev/null; then
    adduser --disabled-password --gecos "" deploy
    usermod -aG docker deploy
    usermod -aG sudo deploy
    
    # Set up SSH for deploy user
    mkdir -p /home/deploy/.ssh
    if [ -f /root/.ssh/authorized_keys ]; then
        cp /root/.ssh/authorized_keys /home/deploy/.ssh/
        chown -R deploy:deploy /home/deploy/.ssh
        chmod 700 /home/deploy/.ssh
        chmod 600 /home/deploy/.ssh/authorized_keys
    fi
    echo "✅ Deploy user created"
else
    echo "ℹ️  Deploy user already exists"
fi

# Create application directory
echo "📁 Creating application directory..."
mkdir -p /var/www/wildlife-tracker
chown -R deploy:deploy /var/www/wildlife-tracker

# Enable fail2ban
echo "🔒 Enabling fail2ban..."
systemctl enable fail2ban
systemctl start fail2ban

echo ""
echo "✅ Server setup complete!"
echo ""
echo "Next steps:"
echo "1. Configure Nginx"
echo "2. Set up SSL with Certbot"
echo "3. Clone your repository"
echo "4. Deploy the application"
