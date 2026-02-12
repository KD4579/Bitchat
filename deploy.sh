#!/bin/bash
# ============================================
# Bitchat Live Server Deployment Script
# Safely updates existing installation from Git
# ============================================

set -e  # Exit on error

# Configuration - EDIT THESE VALUES
# IMPORTANT: HestiaCP uses /home/KamalDave/web/bitchat.live/public_html/
# Do NOT use /var/www/html/bitchat/ — that is a stale, unused copy
SITE_PATH="/home/KamalDave/web/bitchat.live/public_html"
BACKUP_PATH="/home/KamalDave/backups"
GIT_REPO="git@github.com:Velentino007/Bitchat.git"
GIT_BRANCH="main"
WEB_USER="KamalDave"  # HestiaCP runs as the system user

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Bitchat Deployment Script${NC}"
echo -e "${GREEN}========================================${NC}"

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then
    echo -e "${YELLOW}Note: Running without root. Some operations may require sudo.${NC}"
fi

# Create backup directory
mkdir -p "$BACKUP_PATH"

# Step 1: Create backup
echo -e "\n${YELLOW}[1/7] Creating backup...${NC}"
BACKUP_NAME="bitchat_backup_$(date +%Y%m%d_%H%M%S)"
if [ -d "$SITE_PATH" ]; then
    # Backup critical files only (config, not uploads)
    tar -czf "$BACKUP_PATH/${BACKUP_NAME}.tar.gz" \
        -C "$(dirname $SITE_PATH)" \
        --exclude='upload/*' \
        --exclude='node_modules' \
        --exclude='.git' \
        "$(basename $SITE_PATH)/config.php" \
        "$(basename $SITE_PATH)/nodejs/config.json" \
        "$(basename $SITE_PATH)/.user.ini" 2>/dev/null || true
    echo -e "${GREEN}Backup created: $BACKUP_PATH/${BACKUP_NAME}.tar.gz${NC}"
else
    echo -e "${RED}Site path not found: $SITE_PATH${NC}"
    exit 1
fi

# Step 2: Save config files
echo -e "\n${YELLOW}[2/7] Preserving configuration files...${NC}"
TEMP_DIR="/tmp/bitchat_deploy_$$"
mkdir -p "$TEMP_DIR"

# Save files that should not be overwritten
[ -f "$SITE_PATH/config.php" ] && cp "$SITE_PATH/config.php" "$TEMP_DIR/"
[ -f "$SITE_PATH/nodejs/config.json" ] && cp "$SITE_PATH/nodejs/config.json" "$TEMP_DIR/"
[ -f "$SITE_PATH/.user.ini" ] && cp "$SITE_PATH/.user.ini" "$TEMP_DIR/"
[ -f "$SITE_PATH/.htaccess" ] && cp "$SITE_PATH/.htaccess" "$TEMP_DIR/"

echo -e "${GREEN}Configuration files preserved${NC}"

# Step 3: Pull latest code from Git
echo -e "\n${YELLOW}[3/7] Pulling latest code from Git...${NC}"
cd "$SITE_PATH"

# Initialize git if not already a repo
if [ ! -d ".git" ]; then
    echo -e "${YELLOW}Initializing Git repository...${NC}"
    git init
    git remote add origin "$GIT_REPO"
fi

# Fetch and reset to latest
git fetch origin "$GIT_BRANCH"
git reset --hard "origin/$GIT_BRANCH"

echo -e "${GREEN}Code updated to latest version${NC}"

# Step 4: Restore config files
echo -e "\n${YELLOW}[4/7] Restoring configuration files...${NC}"
[ -f "$TEMP_DIR/config.php" ] && cp "$TEMP_DIR/config.php" "$SITE_PATH/"
[ -f "$TEMP_DIR/config.json" ] && mkdir -p "$SITE_PATH/nodejs" && cp "$TEMP_DIR/config.json" "$SITE_PATH/nodejs/"
[ -f "$TEMP_DIR/.user.ini" ] && cp "$TEMP_DIR/.user.ini" "$SITE_PATH/"
[ -f "$TEMP_DIR/.htaccess" ] && cp "$TEMP_DIR/.htaccess" "$SITE_PATH/"

# Cleanup temp
rm -rf "$TEMP_DIR"

echo -e "${GREEN}Configuration files restored${NC}"

# Step 5: Set permissions
echo -e "\n${YELLOW}[5/7] Setting file permissions...${NC}"
chown -R "$WEB_USER:$WEB_USER" "$SITE_PATH" 2>/dev/null || sudo chown -R "$WEB_USER:$WEB_USER" "$SITE_PATH"
find "$SITE_PATH" -type d -exec chmod 755 {} \;
find "$SITE_PATH" -type f -exec chmod 644 {} \;

# Make upload directory writable
chmod -R 775 "$SITE_PATH/upload" 2>/dev/null || true
chmod -R 775 "$SITE_PATH/cache" 2>/dev/null || true

echo -e "${GREEN}Permissions set${NC}"

# Step 6: Install/update Node.js dependencies
echo -e "\n${YELLOW}[6/7] Updating Node.js dependencies...${NC}"
if [ -f "$SITE_PATH/nodejs/package.json" ]; then
    cd "$SITE_PATH/nodejs"
    npm install --production 2>/dev/null || echo -e "${YELLOW}npm install skipped (run manually if needed)${NC}"
fi

echo -e "${GREEN}Node.js dependencies updated${NC}"

# Step 7: Verify Redis
echo -e "\n${YELLOW}[7/7] Checking Redis status...${NC}"
if command -v redis-cli &> /dev/null; then
    if redis-cli ping | grep -q "PONG"; then
        echo -e "${GREEN}Redis is running${NC}"
    else
        echo -e "${YELLOW}Redis is installed but not responding. Start with: sudo systemctl start redis-server${NC}"
    fi
else
    echo -e "${YELLOW}Redis not installed. Install with: sudo apt install redis-server${NC}"
fi

# Summary
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}  Deployment Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo -e ""
echo -e "Backup location: ${YELLOW}$BACKUP_PATH/${BACKUP_NAME}.tar.gz${NC}"
echo -e ""
echo -e "${YELLOW}Post-deployment checklist:${NC}"
echo -e "  1. Test the website in browser"
echo -e "  2. Verify Redis is running: ${GREEN}redis-cli ping${NC}"
echo -e "  3. Restart Node.js socket server if used"
echo -e "  4. Clear browser cache"
echo -e ""
echo -e "${YELLOW}If issues occur, restore backup:${NC}"
echo -e "  tar -xzf $BACKUP_PATH/${BACKUP_NAME}.tar.gz -C $(dirname $SITE_PATH)"
echo -e ""
