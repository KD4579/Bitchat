# GitHub Webhook Auto-Deployment Setup

This guide will set up automatic deployment when you push code to GitHub.

## 🎯 Overview

When you push code to the `main` branch:
1. GitHub sends a webhook to your server
2. Server automatically pulls latest code
3. Deployment happens instantly

---

## 📋 Setup Steps

### Step 1: Upload Files to Server (via HestiaCP)

1. Go to **https://bitchat.live:8083** (or your HestiaCP URL)
2. Login with:
   - Username: `KamalDave`
   - Password: `KDTradex@2424`

3. Navigate to: **Web** → **bitchat.live** → **File Manager**

4. Go to: `/home/KamalDave/web/bitchat.live/public_html/`

5. The files should already be there from the latest git pull, but verify:
   - ✅ `webhook-deploy.php`
   - ✅ `webhook-deploy.sh`

### Step 2: Make Shell Script Executable (via HestiaCP Terminal)

1. In HestiaCP, go to: **Web** → **Terminal** (or **SSH Terminal**)

2. Run these commands:
```bash
cd /home/KamalDave/web/bitchat.live/public_html
chmod +x webhook-deploy.sh
git pull origin main  # Pull the webhook files
```

### Step 3: Configure GitHub Webhook

1. Go to: **https://github.com/Velentino007/Bitchat/settings/hooks**

2. Click: **Add webhook**

3. Configure:
   - **Payload URL**: `https://bitchat.live/webhook-deploy.php`
   - **Content type**: `application/json`
   - **Secret**: `bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec`
   - **Which events**: Select "Just the push event"
   - **Active**: ✅ Checked

4. Click: **Add webhook**

### Step 4: Test the Deployment

Make a small change and push to test:

```bash
# On your local machine
cd /Users/KD/Desktop/Bitchat/Bitchat
echo "# Test deployment" >> README.md
git add README.md
git commit -m "Test webhook deployment"
git push
```

Then check:
1. GitHub webhook page shows a ✅ green checkmark
2. View deployment log on server: `/home/KamalDave/web/bitchat.live/public_html/webhook-deploy.log`

---

## 🔐 Security Token

Your webhook secret token is:
```
bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec
```

This verifies that deployment requests come from GitHub only.

---

## 📝 Deployment Log

Check deployment history:
```bash
tail -50 /home/KamalDave/web/bitchat.live/public_html/webhook-deploy.log
```

---

## 🛠️ Troubleshooting

**Webhook shows error:**
- Check the deployment log file
- Verify script is executable: `ls -la webhook-deploy.sh`
- Test script manually: `./webhook-deploy.sh`

**No deployment happening:**
- Verify webhook is active on GitHub
- Check webhook delivery history on GitHub
- Ensure secret token matches

**Permission errors:**
- Run: `chmod +x webhook-deploy.sh`
- Check file ownership: `chown KamalDave:KamalDave webhook-deploy.*`

---

## ✅ Done!

Now every time you `git push`, your live site updates automatically! 🚀
