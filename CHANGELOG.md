# Bitchat — Changelog

All notable changes to the Bitchat platform are documented here. Entries are grouped by date and listed in reverse chronological order.

---

## 2026-02-18 — Frontend UI Master Improvement Plan (11 Parts)

### Feature: Landing Hero (Part 1)
- Added "Earn. Create. Trade." hero section on the welcome/login page left column
- Includes badge "India's Creator & Crypto Network", headline, tagline, and stats row (10,000+ Creators | ₹50L+ Earned | Live Markets)
- Old generic WoWonder tagline hidden via CSS
- **Files:** `themes/wondertag/layout/welcome/content-simple.phtml`, `themes/wondertag/custom/css/style.css`

### Feature: Market Strip Ticker (Part 2)
- Live BTC/ETH/NIFTY/SENSEX price ticker bar displayed for logged-in users at top of every page
- Auto-refreshes every 60s via CoinGecko API (crypto) and Yahoo Finance (indices)
- Shows price, 24h change%, color-coded green/red
- **Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`

### Feature: Native App Install Popup (Part 3)
- Replaced OneSignal's native browser push prompt with a custom branded popup
- Triggers on 40% scroll depth OR 25s session time; cookie-gated for 7 days
- Shows "Stay ahead of the markets" notification card with Enable/Dismiss buttons
- OneSignal `autoRegister` set to `false` to prevent duplicate native prompt
- **Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`

### Feature: Feed Tabs (Part 4)
- Added "For You | Trading | Creators | Following" tab bar above the home feed
- Each tab triggers filtered AJAX post load
- **Files:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/css/style.css`

### Feature: Simplified Post Composer (Part 5)
- Composer toolbar shows only Image + Video + Live (first 3 buttons) by default
- Remaining options (GIF, Feeling, Poll, Location, Music, etc.) hidden behind a "More Options" toggle
- **Files:** `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`

### Feature: Right Sidebar Restructure (Part 6)
- Added TRDC Earnings Card showing user's live TRDC balance with link to wallet
- Added styled Trending Tags widget with pill buttons (replaces bare hashtag list)
- **Files:** `themes/wondertag/layout/sidebar/content.phtml`, `themes/wondertag/custom/css/style.css`

### Fix: Chat Offline Banner (Part 7)
- Hidden "You are currently offline" WoWonder chat banner via CSS
- **Files:** `themes/wondertag/custom/css/style.css`

### Feature: Micro UX Animations (Part 8)
- Post cards fade in with stagger on load (`bc-fade-in` keyframe)
- Like button bounce animation on click (`bc-like-bounce` keyframe)
- Card hover lift effect on `.wow_content` cards
- Enhanced skeleton shimmer animation
- **Files:** `themes/wondertag/custom/css/style.css`, `themes/wondertag/custom/js/footer.js`

### Feature: Nav Cleanup (Part 9)
- Hidden rarely-used sidebar nav items: Games, Movies, Offers, Memories, Common Things, Funding, Open to Work
- Items remain accessible via the Explore/Discover page
- Pure CSS — fully reversible
- **Files:** `themes/wondertag/custom/css/style.css`

### Feature: Psychological Activation Greeting (Part 10)
- Replaced generic "Good morning/evening, [Name]" with trading/creator-themed messages:
  - Morning: "Markets are opening. What's your move today, [Name]?"
  - Midday: "Markets are moving, [Name]. Share your insight."
  - Peak hours: "Peak hours, [Name]. Creators are earning now."
  - Evening: "Evening session live, [Name]. Your TRDC awaits."
- **Files:** `themes/wondertag/layout/home/content.phtml`

### Feature: Mobile Sticky Bottom Navigation Bar (Part 11)
- Added X/Instagram-style 5-tab bottom nav bar on mobile (≤900px)
- Tabs: Home | Create Post | Notifications | Messages | Profile
- Auto-highlights active tab based on current URL
- Create tab opens post composer modal directly
- **Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/css/style.css`, `themes/wondertag/custom/js/footer.js`

---

## 2026-02-17

### Feature: TRDC Usage Hint UI
- Added "Use TRDC to boost posts, promote content & grow faster" text on wallet page and creator dashboard wallet card
- **Files:** `themes/wondertag/layout/ads/wallet.phtml`, `themes/wondertag/layout/creator_dashboard/content.phtml`

### Feature: Cron Job Execution Logging
- Each cron run logged to `assets/logs/cron.log` with timestamp, duration, and sections executed
- Auto-rotation keeps log under 500KB
- **Files:** `cron-job.php`

### Feature: Fake User Isolation
- Fake/generated users (`src = 'Fake'`) excluded from leaderboard, growth dashboard analytics, TRDC rewards, and milestone processing
- **Files:** `sources/leaderboard.php`, `admin-panel/pages/growth-intelligence/content.phtml`, `assets/includes/functions_trdc_rewards.php`

### Feature: Invitation Code Analytics
- Analytics summary cards on invitation codes admin page: Total/Used/Available codes, Referral Joins (7d)
- Top Referrers table (top 10 by referral count)
- **Files:** `admin-panel/pages/manage-invitation-keys/content.phtml`

### Feature: Automated Backup Scheduler
- Cron-based automated DB backups (mysqldump + gzip), configurable interval (12h/daily/weekly)
- Auto-cleanup keeps last 7 backups
- Admin UI on Backups page with enable/disable and interval settings
- **Files:** `cron-job.php`, `admin-panel/pages/backups/content.phtml`, `xhr/auto_backup_settings.php` (NEW)

### Feature: Admin Activity Log
- New `Wo_LogAdminAction()` function logs admin actions to `assets/logs/admin_activity.log`
- New admin page: Admin Panel > Bitchat Growth > Admin Activity Log
- Color-coded action labels, auto-rotation (1MB max)
- Integrated into: growth presets, banner settings, creator mode, TRDC freeze, backup settings
- **Files:** `assets/includes/functions_admin_log.php` (NEW), `admin-panel/pages/admin-activity-log/content.phtml` (NEW), `assets/init.php`, `admin-panel/autoload.php`, plus 5 XHR handlers

### Feature: TRDC Event Notifications
- Referral joined notification: referrer notified when their invite signs up
- Creator rank upgrade notification: creators notified on rank promotion (Rising Star → Contributor → Influencer → Champion)
- **Files:** `xhr/register.php`, `assets/includes/functions_trdc_rewards.php`

---

## 2026-02-18

### Feature: Growth Intelligence Dashboard (Admin)
- New admin panel page: Admin Panel > Bitchat Growth > Growth Dashboard
- Key metrics: Daily Active Users, New Users Today, Posts Today, Referral Joins (7d)
- 7-day bar charts for new users and referral joins (CSS-only, no JS library)
- TRDC Economy section: total in circulation, issued today, ghost actions today, active stories
- Engagement Health: reactions/comments/posts (24h) + engagement per active user ratio
- Top 5 TRDC holders table
- **Files:** `admin-panel/pages/growth-intelligence/content.phtml` (NEW), `admin-panel/autoload.php`

### Feature: Growth Mode Presets (Admin)
- New admin panel page: Admin Panel > Bitchat Growth > Growth Presets
- 3 one-click presets: Creator Growth Week, Referral Boost Week, Engagement Boost Week
- Each preset configures feed algorithm + ghost activity + TRDC rewards + boost weights
- Active preset indicator, Reset to Custom button
- **Files:** `admin-panel/pages/growth-presets/content.phtml` (NEW), `xhr/growth_presets.php` (NEW), `admin-panel/autoload.php`

### Feature: Ghost Activity Safety Limits
- Max Ghost Reactions Per Hour (default: 10) — prevents artificial over-inflation
- Ghost-to-Real Ratio Cap % (default: 30%) — ghost reactions cannot exceed % of real reactions
- **Files:** `admin-panel/pages/ghost-activity/content.phtml`, `xhr/ghost_activity.php`

### Feature: Creator Moderation Quick Actions
- TRDC wallet balance column in creator admin table
- "Freeze" button per creator — freezes TRDC earning via config flag
- **Files:** `admin-panel/pages/creator-mode/content.phtml`, `xhr/creator.php`

### Feature: Announcement Banner Scheduling
- Start Date and End Date (datetime-local) fields added to banner admin page
- Banner auto-shows/hides based on schedule (server-side check in container.phtml)
- Empty dates = immediate/no expiration (backwards compatible)
- **Files:** `admin-panel/pages/announcement-banner/content.phtml`, `xhr/announcement_banner.php`, `themes/wondertag/layout/container.phtml`

### Fix: System Status Warning Resolved
- **Root cause:** `/xml` folder not writable (required by WoWonder's `getStatus()`)
- Created `/xml` dir with 777 permissions, installed `ffmpeg`/`ffprobe`, created `upload/stickers`, fixed `assets/logs` permissions
- `getStatus()` now returns empty — dashboard warning gone

### Server: New Config Rows
- `ghost_activity_max_per_hour=10`, `ghost_activity_ratio_cap=30`
- `announcement_banner_start=''`, `announcement_banner_end=''`
- `growth_active_preset=custom`

---

## 2026-02-17

### Feature: Creator Growth Stats (Step 12)
- Added 3 growth stat cards to creator dashboard: Reach Score, Invited Users, Total Engagement
- Extended `Wo_GetCreatorStats()` with `reach_score`, `invited_users`, `total_engagement` fields
- **Files:** `assets/includes/functions_creator.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/custom/css/style.css`

### Feature: Leaderboard Page (Step 13)
- Created `/leaderboard` page with 3 tabs: Top Creators, Top Inviters, Top TRDC Earners
- Medal icons (gold/silver/bronze) for top 3 in each category
- Route added in `index.php`, `ajax_loading.php`, `.htaccess`, `nginx.conf`
- Sidebar navigation link added
- **Files:** `sources/leaderboard.php` (NEW), `themes/wondertag/layout/leaderboard/content.phtml` (NEW), `index.php`, `ajax_loading.php`, `.htaccess`, `nginx.conf`, `themes/wondertag/layout/sidebar/left-sidebar.phtml`, `themes/wondertag/custom/css/style.css`

### Feature: Creator Rank Badges (Step 14)
- `Wo_GetCreatorRank()` — composite score: engagement + posts*2 + invites*10 + followers*3
- 4 tiers: Rising Star (green, <200), Contributor (blue, 200+), Influencer (purple, 800+), Champion (gold, 2000+)
- Rank badge displayed in creator dashboard header
- **Files:** `assets/includes/functions_creator.php`, `sources/creator_dashboard.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/custom/css/style.css`

### Feature: Announcement Banner System (Step 16)
- Admin panel page: enable/disable, text, URL, background color, text color, live preview
- XHR handler with admin-only access, URL validation via `filter_var()`, color sanitization via regex
- Site-wide banner after header with sessionStorage-based dismiss (per session)
- 5 config rows added to `Wo_Config`
- **Files:** `admin-panel/pages/announcement-banner/content.phtml` (NEW), `xhr/announcement_banner.php` (NEW), `themes/wondertag/layout/container.phtml`, `admin-panel/autoload.php`, `themes/wondertag/custom/css/style.css`

### Feature: Invite Button Exposure (Step 17)
- Left sidebar: "Invite & Earn" navigation link
- Creator dashboard: "Invite & Earn" button in header actions
- Right sidebar: Compact Invite & Earn widget between creators widget and trending hashtags
- **Files:** `themes/wondertag/layout/sidebar/left-sidebar.phtml`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/layout/sidebar/content.phtml`, `themes/wondertag/custom/css/style.css`

### Fix: Session GC Lifetime Alignment
- **Problem:** `.user.ini` had `session.gc_maxlifetime = 14400` (4 hours) conflicting with `assets/init.php`'s 30-day session config. PHP garbage collector could destroy sessions prematurely.
- **Fix:** Changed `.user.ini` `session.gc_maxlifetime` to `2592000` (30 days) to match init.php.
- **Files:** `.user.ini`

### Fix: Notification Settings Bug (functions_one.php)
- **Problem 1:** Line 2870 had `= !1` (assignment to false) instead of `!= 1` (comparison). This silently disabled page-liked notifications for all users.
- **Problem 2:** Empty `notification_settings` fallback was `array()` — meaning new users with no settings got zero notifications.
- **Fix:** Corrected `= !1` → `!= 1`. Changed fallback to all-enabled defaults (`e_liked=1, e_shared=1, e_commented=1, e_followed=1, e_accepted=1, e_mentioned=1, e_joined_group=1, e_liked_page=1, e_visited=1, e_profile_wall_post=1, e_memory=1`).
- **Files:** `assets/includes/functions_one.php`

### UI: Removed TRDC Post Cost from Publisher
- Removed "Post Cost in TRDC (min 10 TRDC)" input field and "Buy TRDC Now" dropdown from post composer
- **Files:** `themes/wondertag/layout/story/publisher-box.phtml`

### Database: Missing Tables & Config Sync
- Created `Wo_Ghost_Queue` table (post_id, actor_user_id, action_type, action_data, execute_at, executed_at, status)
- Created `Wo_TRDC_Rewards` table (user_id, milestone_key, amount, reason, created_at)
- Inserted 5 `announcement_banner_*` config rows into `Wo_Config`
- Changed `affiliate_type` from `0` to `1` (enabled invite & earn system)
- Flushed Redis cache

---

## 2026-02-16

### Feature: Bitchat Growth & Technical Improvement Index (11 Topics)

Complete implementation of the Bitchat Growth system — feed algorithm, anti-spam, scheduled posting, ghost activity, creator mode, and TRDC rewards. All features are behind config toggles (disabled by default) and can be enabled individually from the admin panel. Zero modifications to existing core functions (`Wo_GetPosts()`, `Wo_PostData()`, `Wo_RegisterPost()` logic preserved).

**Phase 1 — Feed Algorithm + Anti-Spam (Topics 1-4, 10)**
- `Wo_GetRankedPosts()` — score-based feed ranking with Redis caching (30s TTL)
- Scoring formula: engagement + media_bonus + freshness + pro_boost - spam/link/frequency penalties
- `Wo_TrackPostSpam()` — domain + text hash tracking per post for duplicate detection
- Same-user diversity limit (max 2 posts per user in top results)
- Admin panel with configurable weights, pool size, and spam window
- Automatic fallback to chronological feed when disabled
- **Files:** `functions_feed.php`, `functions_spam.php`, `xhr/feed_algorithm.php`, `admin-panel/pages/feed-algorithm/content.phtml`

**Phase 2 — Scheduled Posting (Topic 6)**
- `Wo_Scheduled_Posts` table with pending/published/failed/cancelled status
- `Wo_PublishScheduledPosts()` cron function using `Wo_RegisterPost()` — identical to normal posting
- Admin panel for viewing queue, cancelling posts
- **Files:** `functions_scheduled.php`, `xhr/scheduled_posts.php`, `admin-panel/pages/scheduled-posts/content.phtml`

**Phase 3 — Ghost Activity Layer (Topic 7)**
- Delayed reactions from admin accounts on new posts (30min-2hr default delay)
- Uses real `Wo_AddReactions()` — real reactions, real notifications
- Shorter delay for first-time posters (welcome engagement)
- Max 1 ghost reaction per post, auto-cleanup of old queue items
- **Files:** `functions_ghost.php`, `xhr/ghost_activity.php`, `admin-panel/pages/ghost-activity/content.phtml`

**Phase 4 — Creator Mode (Topics 5, 8)**
- `is_creator` column on `Wo_Users` table
- Orange star creator badge on posts (between verified and PRO badges)
- Creator dashboard at `/creator-dashboard` with engagement stats
- `Wo_UserHasActiveStory()` for story boost in feed scoring
- **Files:** `functions_creator.php`, `xhr/creator.php`, `sources/creator_dashboard.php`, `creator_dashboard/content.phtml`, `header.phtml`

**Phase 5 — TRDC Ecosystem Rewards (Topic 9)**
- Milestone-based TRDC token rewards: 100 reactions (0.5), 500 (2.0), 1000 (5.0), first video (0.25)
- `Wo_AwardTRDC()` updates wallet + logs to `Wo_TRDC_Rewards` + sends notification
- Unique constraint prevents double-rewarding
- **Files:** `functions_trdc_rewards.php`, `xhr/trdc_rewards.php`, `admin-panel/pages/trdc-rewards/content.phtml`

**Integration Points (existing files modified):**
- `assets/init.php` — 6 new require_once lines
- `assets/includes/tabels.php` — 4 table constants
- `assets/includes/functions_one.php` — spam tracking + ghost reaction hooks in `Wo_RegisterPost()` (guarded by `function_exists()`)
- `themes/wondertag/layout/home/load-posts.phtml` — algorithm toggle wrapper
- `xhr/posts.php` — ranked feed branch for load-more
- `themes/wondertag/javascript/script.js` — ranked_page counter
- `cron-job.php` — 4 new cron sections (spam cleanup, scheduled posts, ghost activity, TRDC rewards)
- `admin-panel/autoload.php` — 4 admin pages + sidebar menu
- `index.php` — creator-dashboard route
- `themes/wondertag/layout/story/includes/header.phtml` — creator badge

**Database Migration:** `sql/001_feed_algorithm.sql` — creates 4 tables, 15+ config rows, 1 ALTER TABLE

**Rollback:** Set any `*_enabled` config to `0` in Wo_Config. No code deployment needed.

---

### Fix: Blank Feed — Server-Side Rendering
- **Problem:** All users saw header and stories but blank feed area after login. AJAX `jQuery .load()` was failing silently — server returned valid 23-27KB responses but browser never rendered the content.
- **Fix:** Replaced AJAX-dependent skeleton loaders with server-side rendered posts using `<?php echo Wo_LoadPage('home/load-posts'); ?>` in `content.phtml`. Posts now render in the initial HTML without any JavaScript dependency.
- **Safety net:** `footer.js` independently detects stuck feeds at 6s/12s and rescues via XMLHttpRequest fallback.
- **Files:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/js/footer.js`

---

## 2026-02-15

### Fix: Cross-Platform Compatibility (iOS, Android, Older Browsers)
- **iOS Safari AJAX caching:** Added `cache: false` to prevent aggressive GET request caching.
- **Mobile double-tap:** `dblclick` event doesn't fire reliably on touch devices. Added custom `touchend` double-tap handler (350ms window) for video call stream swap.
- **CSS `:has()` fallback:** Not supported in Samsung Internet, Android WebView < 105, Firefox < 121. Added `.bc-dark-mode` class on `<html>` synced via MutationObserver as fallback for dark mode detection.
- **Webkit prefixes:** All `backdrop-filter` usages prefixed with `-webkit-` for iOS Safari.
- **Files:** `footer.js`, `agora.phtml`, `style.css`

### Fix: Feed Rescue Script (footer.js)
- Independent safety net that runs without depending on the page's own `loadposts()` function.
- Part 1: Removes stuck `opacity_start` class (WoWonder SPA transition bug).
- Part 2: Detects empty feed container and loads posts via standalone XMLHttpRequest.
- Part 3: Syncs `.bc-dark-mode` class for CSS custom properties fallback.
- **Files:** `themes/wondertag/custom/js/footer.js`

### Fix: Video Call Disconnect Not Propagating
- **Problem:** When one party ends a video call, the other party's call stays active indefinitely.
- **Fix:** Added `user-left` event handler in Agora SDK v4 that stops remote tracks, cleans up UI, and redirects when no remote users remain. Promotes next user to main stream in multi-party calls.
- **Files:** `themes/wondertag/layout/video/agora.phtml`

### Fix: Call Not Ending — Agora Table Cleanup
- **Problem:** Stale entries in Agora call table prevented new calls from connecting.
- **Fix:** Added cleanup of Agora table entries on call disconnect via `cancel_call` XHR.
- **Files:** `themes/wondertag/layout/video/agora.phtml`

### Upgrade: Agora SDK v3.6.11 to v4.22.0
- Migrated from legacy Agora Web SDK 3.x to current 4.x API.
- Rewrote client initialization (`createClient` with `rtc` mode + `vp8` codec).
- Replaced stream-based API with track-based API (`createMicrophoneAudioTrack`, `createCameraVideoTrack`).
- Updated event handlers: `stream-added` → `user-published`, `peer-leave` → `user-left`.
- Added screen sharing support structure and proper track cleanup in `leaveChannel()`.
- **Files:** `themes/wondertag/layout/video/agora.phtml`, `themes/wondertag/layout/modals/talking.phtml`

---

## 2026-02-14

### Fix: TRDC Wallet Real-Time Balance Updates (Pusher/Socket)
- **Problem:** When TRDC tokens are transferred, receiver's balance doesn't update until page refresh.
- **Fix:** Added `sender_balance` and `receiver_balance` to `xhr/wallet.php` JSON response. Sender's UI updates immediately after transfer. Socket emit includes `wallet_balance` for receiver. Enhanced `new_notification` handler in `container.phtml` to update receiver's wallet display.
- **Files:** `xhr/wallet.php`, `wallet.phtml`, `send_money.phtml`, `container.phtml`

### Fix: Stories Not Visible to All Users
- **Problem:** Stories only visible to users the viewer follows. Non-followed users' stories were hidden.
- **Fix:** Modified all 3 story-fetching functions to show stories from all active users. Added `expire > UNIX_TIMESTAMP()` for 24-hour TTL enforcement. Added blocked-user exclusion in both directions.
- **Files:** `assets/includes/functions_three.php`

### Fix: Audio/Video Calls Restricted to Premium Users
- **Problem:** `video_call_request` and `audio_call_request` config values were set to `pro`.
- **Fix:** Changed both config values to `all` in `Wo_Config` database table. All users can now make calls.
- **Files:** Database update (`Wo_Config` table)

### Fix: Double-Click Reaction Duplicates
- **Problem:** Double-clicking reaction buttons triggers duplicate AJAX requests due to race condition.
- **Fix:** Moved `data_react` guard to fire immediately before AJAX call (not in callback). Applied same pattern to `Wo_RegisterReaction()`.
- **Files:** `themes/wondertag/layout/extra_js/content.phtml`

---

## 2026-02-13

### Feature: UI Modernization (CSS Override Layer)
- 767 lines of custom CSS in `themes/wondertag/custom/css/style.css`.
- Design tokens (CSS custom properties) for spacing, radius, shadows, colors, transitions.
- Dark mode support via `:has(link#night-mode-css)` + `.bc-dark-mode` class fallback.
- **Login page:** Cleaner card, pill buttons, circular social icons, larger welcome text.
- **Header:** Frosted glass effect (`backdrop-filter: blur`), pill-shaped search, refined dropdowns.
- **Feed:** Thin-border post cards, circular hover action icons (Twitter-style), pill filter tabs.
- **Profile:** Shadow-ring avatar, pill action buttons, rounded active tab indicator.
- Fully reversible — remove one `<link>` tag to revert everything.
- **Files:** `themes/wondertag/custom/css/style.css`, `themes/wondertag/layout/container.phtml`

### Feature: SEO Structured Data
- Added JSON-LD `WebSite` schema markup to welcome/login page.
- Added robots meta tags for search engine indexing.
- **Files:** `themes/wondertag/layout/container.phtml`

### Feature: Persistent Login (30-Day Sessions)
- Configured `session.gc_maxlifetime = 2592000` (30 days).
- Session cookie set with extended lifetime for remember-me functionality.
- **Files:** `assets/init.php`, `.user.ini`

### Fix: Session Expired Dialog (Multiple Iterations)
- **Root cause:** PHP session configuration mismatch between server and cookie settings.
- **Fix:** Aligned session GC lifetime, cookie parameters, and session handler config.
- Added cache-proof server-side session validation endpoint.
- Removed false-positive session expiry detection.
- **Files:** `assets/init.php`, `.user.ini`, `xhr/session_status.php`, `container.phtml`

### Fix: deploy.sh Document Root
- **Problem:** Script deployed to `/var/www/html/bitchat/` instead of correct HestiaCP path.
- **Fix:** Updated to `/home/KamalDave/web/bitchat.live/public_html/`.
- Cleaned up debug logging from session diagnostics.
- **Files:** `deploy.sh`

---

## 2026-02-12

### Security: CSRF Protection
- Added CSRF token generation and validation to critical user-facing operations.
- Added legacy CSRF token support for backward compatibility.
- Strengthened token generation.
- **Files:** `assets/includes/security_helpers.php`, various XHR handlers

### Fix: Search and Filtering Bugs
- Fixed critical search functionality issues.
- Added proper error messages to XHR handlers.
- **Files:** Various `xhr/` handlers

### Fix: UI Bugs — Empty Buttons and Upload Enhancement
- Fixed empty/broken button states.
- Enhanced file upload UI.
- **Files:** Various theme layout files

### Performance: Phase 3 Optimization
- Redis caching layer improvements.
- Query optimization and pagination.
- Notification optimizer enabled in theme.
- **Files:** `assets/includes/redis_cache.php`, various

---

## 2026-02-11

### Security: Initial Hardening
- CSRF protection for critical operations (Phase 1).
- Session configuration fixes for Mac development.
- Codebase cleanup and localhost development environment setup.
- Added `CLAUDE.md` project documentation.
- **Files:** Multiple security and configuration files

---

## 2026-01-19

### Stability: Feed and Posts
- UX improvements for feed loading and post rendering.
- Feed and security improvements.
- Fixed duplicate `session_start()` warning.
- **Files:** Various feed and session files

---

## 2026-01-18

### Initial Setup
- Deployment script (`deploy.sh`) for live server updates.
- Performance optimizations: Redis caching, pagination, lazy loading.
- Security improvements and dependency updates.
- Initial codebase commit with all WoWonder framework files.
- **Files:** Full project initialization
