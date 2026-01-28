#!/bin/bash

# Database Backup Script
# Place this in /home/deploy/backup-db.sh on your server
# Run via cron: 0 2 * * * /home/deploy/backup-db.sh

set -e

BACKUP_DIR="/home/deploy/backups"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=7

# Create backup directory
mkdir -p $BACKUP_DIR

echo "Starting database backup at $(date)"

# Backup database
docker exec wildlife-postgis pg_dump -U postgres wildlife_map > $BACKUP_DIR/wildlife_map_$DATE.sql

# Compress backup
gzip $BACKUP_DIR/wildlife_map_$DATE.sql

echo "Backup created: wildlife_map_$DATE.sql.gz"

# Remove old backups
find $BACKUP_DIR -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Backup completed at $(date)"
echo "Old backups older than $RETENTION_DAYS days removed"
