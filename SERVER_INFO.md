# Wildlife Tracker - Server Information

## Droplet Details
- **IP Address:** 68.183.56.123
- **Domain:** koteglasye.com
- **OS:** Ubuntu 22.04 LTS
- **Region:** NYC3
- **Size:** 1GB RAM / 1 vCPU / 25GB SSD

## SSH Access
```bash
# From your Mac:
ssh -i ~/.ssh/wildlife_tracker_do root@68.183.56.123

# Or add to ~/.ssh/config for easier access:
# Host wildlife
#     HostName 68.183.56.123
#     User root
#     IdentityFile ~/.ssh/wildlife_tracker_do
# Then use: ssh wildlife
```

## Pre-installed Software ✅
- Docker (already installed!)
- Docker Compose (already installed!)
- UFW Firewall (enabled, ports 22, 2375, 2376 open)

## What's Already Done
- [x] Digital Ocean droplet created
- [x] SSH key added and working
- [x] Docker pre-installed (1-Click droplet)
- [x] Basic firewall configured

## Next Steps (GoDaddy DNS)
1. Log in to https://dcc.godaddy.com
2. Go to koteglasye.com → DNS
3. Delete existing A records for @ and www
4. Add NEW A records:
   - Type: A, Name: @, Value: 68.183.56.123, TTL: 600
   - Type: A, Name: www, Value: 68.183.56.123, TTL: 600
5. Save and wait 10-30 minutes

## GitHub Secrets to Add
Go to: https://github.com/parlo12/-Wildlife-Sighting-Tracker/settings/secrets/actions

Add these secrets:
- **DROPLET_IP**: `68.183.56.123`
- **DROPLET_USER**: `root` (will create 'deploy' user later)
- **SSH_PRIVATE_KEY**: Contents of `~/.ssh/wildlife_tracker_do` (private key!)
- **DB_PASSWORD**: Choose a secure password for production

## Quick Commands

### Get SSH private key for GitHub:
```bash
cat ~/.ssh/wildlife_tracker_do
```

### Test DNS propagation:
```bash
nslookup koteglasye.com
dig koteglasye.com +short
```

### SSH into droplet:
```bash
ssh -i ~/.ssh/wildlife_tracker_do root@68.183.56.123
```
