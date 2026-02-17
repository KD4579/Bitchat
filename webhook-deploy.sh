#!/bin/bash
# ============================================
# GitHub Webhook Deployment Script for Bitchat
# Runs as KamalDave user via sudo
# ============================================

# Configuration
SITE_PATH="/home/KamalDave/web/bitchat.live/public_html"
BACKUP_PATH="/home/KamalDave/backups/webhook-backups"

# Navigate to site directory
cd "$SITE_PATH" || exit 1

# Ensure git trusts this directory
git config --global --add safe.directory "$SITE_PATH" 2>/dev/null

# Create backup of config files
mkdir -p "$BACKUP_PATH"
BACKUP_NAME="config_backup_$(date +%Y%m%d_%H%M%S).tar.gz"
tar -czf "$BACKUP_PATH/$BACKUP_NAME" \
    config.php \
    nodejs/config.json \
    .user.ini \
    .htaccess 2>/dev/null || true

echo "Backup created: $BACKUP_NAME"

# Stash any local changes
git stash --include-untracked 2>/dev/null || true

# Pull latest code from GitHub
git fetch origin main
git reset --hard origin/main

echo "Code updated to latest version"

# Restore execute permission on webhook files
chmod +x "$SITE_PATH/webhook-deploy.sh"

# Set upload and cache directories writable
chmod -R 775 "$SITE_PATH/upload" 2>/dev/null || true
chmod -R 775 "$SITE_PATH/cache" 2>/dev/null || true

echo "Permissions set"

# Update Node.js dependencies if needed
if [ -f "$SITE_PATH/nodejs/package.json" ]; then
    cd "$SITE_PATH/nodejs"
    npm install --production 2>/dev/null || true
    cd "$SITE_PATH"
    echo "Node.js dependencies updated"
fi

echo "Deployment completed successfully!"
exit 0
