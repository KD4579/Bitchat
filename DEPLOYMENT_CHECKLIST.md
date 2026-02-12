# Bitchat Production Deployment Checklist

Use this checklist before deploying to production to ensure everything is configured correctly.

## Pre-Deployment Checklist

### 1. Security ✅

- [ ] **CSRF Protection Enabled**
  - Verify `BitchatSecurity::requireCsrfToken()` is in critical XHR handlers
  - Test CSRF tokens are working on key actions

- [ ] **Session Configuration**
  - [ ] `session.cookie_httponly = 1` in `.user.ini`
  - [ ] `session.use_strict_mode = 1`
  - [ ] `session.cookie_secure = 1` (if using HTTPS)
  - [ ] `session.gc_maxlifetime = 14400` (4 hours)

- [ ] **SSL/HTTPS**
  - [ ] SSL certificate installed and valid
  - [ ] Force HTTPS redirect enabled
  - [ ] Mixed content warnings resolved

- [ ] **File Upload Security**
  - [ ] File extension whitelist configured
  - [ ] File size limits set per plan
  - [ ] Upload directory permissions: 755
  - [ ] `.htaccess` in upload directory prevents PHP execution

- [ ] **Database Security**
  - [ ] Strong database password (16+ characters)
  - [ ] Database user has minimum required permissions
  - [ ] Remote database access disabled (if not needed)
  - [ ] Regular backups scheduled

### 2. Performance ⚡

- [ ] **Database Indexes**
  ```bash
  cd database
  php apply_performance_indexes.php
  ```
  - [ ] All 45+ indexes applied successfully
  - [ ] FULLTEXT indexes working

- [ ] **Caching**
  - [ ] Redis server running and accessible
  - [ ] Redis cache tests passing
  - [ ] Cache TTLs configured appropriately

- [ ] **Notification Optimizer**
  - [ ] `notification-optimizer.js` loaded in theme
  - [ ] Test polling adjusts based on activity

- [ ] **PHP Configuration**
  - [ ] `memory_limit = 256M`
  - [ ] `upload_max_filesize = 1024M`
  - [ ] `post_max_size = 1024M`
  - [ ] `max_execution_time = 300`
  - [ ] OPcache enabled

- [ ] **Web Server**
  - [ ] Gzip compression enabled
  - [ ] Browser caching headers configured
  - [ ] Static asset CDN (optional)

### 3. Configuration 🔧

- [ ] **config.php**
  - [ ] `$sql_db_*` credentials correct for production
  - [ ] `$wo['config']['site_url']` set to production URL
  - [ ] Debug mode disabled
  - [ ] Error reporting set to production levels

- [ ] **Email Configuration**
  - [ ] SMTP settings configured
  - [ ] Test email sending works
  - [ ] Admin email address correct

- [ ] **API Keys**
  - [ ] OneSignal configured (if using push notifications)
  - [ ] Google Maps API key (if using)
  - [ ] Payment gateway keys (live, not test)
  - [ ] Social login keys (Facebook, Google, etc.)

- [ ] **Node.js Server**
  - [ ] `nodejs/config.json` has production database credentials
  - [ ] Socket.io server running as service
  - [ ] Port 3000 accessible (or configured port)

### 4. File Permissions 📁

```bash
# Directories: 755
find . -type d -exec chmod 755 {} \;

# Files: 644
find . -type f -exec chmod 644 {} \;

# Writable directories: 755 with www-data ownership
chmod 755 upload/ cache/
chown -R www-data:www-data upload/ cache/
```

- [ ] All file permissions set correctly
- [ ] Writable directories owned by web server user
- [ ] config.php not world-readable (chmod 600)

### 5. Testing 🧪

- [ ] **Functionality Tests**
  - [ ] User registration works
  - [ ] Login/logout works
  - [ ] Post creation works
  - [ ] Image/video upload works
  - [ ] Notifications appear
  - [ ] Messages send/receive
  - [ ] Search returns results
  - [ ] Payment processing (test transaction)

- [ ] **Performance Tests**
  - [ ] Page load time < 3 seconds
  - [ ] Search response time < 1 second
  - [ ] Feed loads smoothly
  - [ ] No console errors in browser

- [ ] **Cross-Browser Testing**
  - [ ] Chrome ✓
  - [ ] Firefox ✓
  - [ ] Safari ✓
  - [ ] Edge ✓
  - [ ] Mobile browsers ✓

### 6. Monitoring 📊

- [ ] **Error Logging**
  - [ ] Error logger enabled
  - [ ] Log directory writable
  - [ ] Admin email notifications configured

- [ ] **MySQL Slow Query Log**
  ```sql
  SET GLOBAL slow_query_log = 'ON';
  SET GLOBAL long_query_time = 2;
  ```

- [ ] **Server Monitoring**
  - [ ] Uptime monitoring service configured
  - [ ] Resource usage alerts set up
  - [ ] Disk space alerts configured

### 7. Backup 💾

- [ ] **Database Backups**
  - [ ] Automated daily backups scheduled
  - [ ] Backup retention policy (30 days recommended)
  - [ ] Test database restore procedure

- [ ] **File Backups**
  - [ ] Upload directory backed up
  - [ ] Config files backed up
  - [ ] Backup storage location secure

### 8. Documentation 📝

- [ ] **Admin Documentation**
  - [ ] Admin credentials documented (secure location)
  - [ ] Deployment procedure documented
  - [ ] Rollback procedure documented
  - [ ] Emergency contacts listed

- [ ] **User Documentation**
  - [ ] Terms of Service updated
  - [ ] Privacy Policy updated
  - [ ] Help/FAQ pages complete

---

## Deployment Steps

### Step 1: Prepare Production Server

```bash
# 1. Clone repository
git clone https://github.com/yourusername/bitchat.git
cd bitchat

# 2. Install dependencies
cd nodejs
npm install --production

# 3. Set up database
mysql -u root -p
CREATE DATABASE bitchat_production;
# Import database schema
mysql -u root -p bitchat_production < database/schema.sql

# 4. Apply performance indexes
cd database
php apply_performance_indexes.php
```

### Step 2: Configure Environment

```bash
# 1. Copy and edit config
cp config.example.php config.php
nano config.php
# Update database credentials, site URL, etc.

# 2. Set file permissions
chmod 600 config.php
chmod 755 upload/ cache/
chown -R www-data:www-data upload/ cache/

# 3. Configure Node.js
cd nodejs
cp config.example.json config.json
nano config.json
# Update database credentials
```

### Step 3: Start Services

```bash
# 1. Start Node.js server (as service)
pm2 start nodejs/main.js --name bitchat-socket
pm2 save
pm2 startup

# 2. Restart web server
sudo systemctl restart apache2
# or
sudo systemctl restart nginx

# 3. Verify services running
pm2 status
systemctl status apache2
```

### Step 4: Post-Deployment Verification

```bash
# 1. Check logs for errors
tail -f logs/error-*.log

# 2. Test critical functions
# - Visit site and test registration
# - Create a post
# - Upload an image
# - Send a message

# 3. Monitor performance
# Check MySQL slow query log
# Monitor server resources (htop, free -m)
```

---

## Rollback Procedure

If deployment fails:

```bash
# 1. Stop new code
git checkout <previous-commit-hash>

# 2. Restore database (if schema changed)
mysql -u root -p bitchat < backup_YYYY-MM-DD.sql

# 3. Restart services
pm2 restart all
sudo systemctl restart apache2

# 4. Verify rollback successful
# Test critical functions
```

---

## Maintenance Mode

To enable maintenance mode during deployment:

```bash
# 1. Create maintenance flag
touch maintenance.flag

# 2. Check .htaccess for maintenance redirect
# (Should redirect to maintenance.html)

# 3. After deployment, remove flag
rm maintenance.flag
```

---

## Common Issues & Solutions

### Issue: Session expired errors
**Solution**: Check `.user.ini` session configuration, restart PHP-FPM

### Issue: Upload fails
**Solution**: Check directory permissions (755), file size limits in php.ini

### Issue: Notifications not working
**Solution**: Verify Node.js server running, check port 3000 accessible

### Issue: Slow performance
**Solution**: Check database indexes applied, Redis running, enable OPcache

### Issue: CSRF token errors
**Solution**: Verify legacy token support in `security_helpers.php`

---

## Support Contacts

- **Developer**: [Your Name] - [email]
- **Server Admin**: [Name] - [email]
- **Emergency**: [Phone number]

---

## Version History

| Version | Date | Changes | Deployed By |
|---------|------|---------|-------------|
| 3.0.0 | 2026-XX-XX | Phase 1-3 improvements | - |

---

**Last Updated**: 2026-02-12
**Next Review**: 30 days after deployment
