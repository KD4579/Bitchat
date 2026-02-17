#!/bin/bash
# ============================================
# GitHub Webhook Deployment Script
# Triggered automatically by webhook-deploy.php
# ============================================

set -e  # Exit on error

# Configuration
SITE_PATH="/home/KamalDave/web/bitchat.live/public_html"
BACKUP_PATH="/home/KamalDave/backups"
LOG_FILE="$SITE_PATH/webhook-deploy.log"

# Function to log messages
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log_message "Starting webhook deployment..."

# Navigate to site directory
cd "$SITE_PATH" || exit 1

# Create backup of critical files
log_message "Creating config backup..."
mkdir -p "$BACKUP_PATH/webhook-backups"
BACKUP_NAME="config_backup_$(date +%Y%m%d_%H%M%S).tar.gz"
tar -czf "$BACKUP_PATH/webhook-backups/$BACKUP_NAME" \
    config.php \
    nodejs/config.json \
    .user.ini \
    .htaccess 2>/dev/null || log_message "Warning: Some config files not found"

# Stash any local changes
log_message "Stashing local changes..."
git stash --include-untracked || true

# Pull latest code
log_message "Pulling latest code from GitHub..."
git fetch origin main
git reset --hard origin/main

# Set correct permissions
log_message "Setting permissions..."
find "$SITE_PATH" -type d -exec chmod 755 {} \;
find "$SITE_PATH" -type f -exec chmod 644 {} \;
chmod -R 775 "$SITE_PATH/upload" 2>/dev/null || true
chmod -R 775 "$SITE_PATH/cache" 2>/dev/null || true

# Update Node.js dependencies if package.json changed
if [ -f "$SITE_PATH/nodejs/package.json" ]; then
    log_message "Checking Node.js dependencies..."
    cd "$SITE_PATH/nodejs"
    npm install --production 2>/dev/null || log_message "npm install skipped"
    cd "$SITE_PATH"
fi

# Clear any application cache
if [ -d "$SITE_PATH/cache" ]; then
    log_message "Clearing application cache..."
    find "$SITE_PATH/cache" -type f -name "*.cache" -delete 2>/dev/null || true
fi

log_message "Deployment completed successfully!"
log_message "Backup saved: $BACKUP_PATH/webhook-backups/$BACKUP_NAME"

exit 0
