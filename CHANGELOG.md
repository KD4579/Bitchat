# Bitchat — Changelog

All notable changes to the Bitchat platform are documented here. Entries are grouped by date and listed in reverse chronological order.

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
