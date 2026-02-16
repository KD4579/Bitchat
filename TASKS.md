# Bitchat — Functionality Issues Tracker

## Status Legend
- [ ] Not Started
- [~] In Progress
- [x] Completed

---

## Task 0: Blank Feed / Content Area Not Loading
**Status:** [x] Completed
**Reported:** Admin (BITCHAT) sees header but blank content area after login
**Root Cause:** Two issues combined:
1. WoWonder's SPA navigation adds `opacity_start` class to `#ajax_loading` making content invisible when transitions fail.
2. Feed posts loaded purely via AJAX (`jQuery .load()`) which was failing silently for all users — server returned valid data but JS never rendered it.
**Fix Applied:**
- `footer.js` safety script: removes stuck `opacity_start`, syncs dark mode class, and independently rescues stuck feeds via XMLHttpRequest
- **Server-side feed rendering**: replaced skeleton loaders with `<?php echo Wo_LoadPage('home/load-posts'); ?>` so posts render in the initial HTML without JavaScript dependency
- `loadposts()` kept as function for SPA navigation refreshes
**Files Modified:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/js/footer.js`

---

## Task 1: TRDC Balance Transfer Not Showing Until Receiver Refreshes (Pusher)
**Status:** [x] Completed
**Issue:** When TRDC tokens are transferred to another user, the receiver's balance does not update in real-time. The receiver must manually refresh the page to see the updated balance.
**Expected:** Balance should update instantly via Pusher/WebSocket push notification.
**Root Cause:** The wallet transfer handler (`xhr/wallet.php`) updated the database and sent a notification via `Wo_RegisterNotification()`, but the JSON response only contained `status` and `message` — no updated balance values.
**Fix Applied:**
1. `xhr/wallet.php`: Added `sender_balance` and `receiver_balance` fields to the JSON response
2. `wallet.phtml`: Added `id="wallet-balance-amount"` for JS targeting
3. `send_money.phtml`: Sender's balance updates immediately; added `wallet_balance` to socket emit
4. `container.phtml`: Enhanced `new_notification` handler to update receiver's wallet balance
**Files Modified:** `xhr/wallet.php`, `themes/wondertag/layout/ads/wallet.phtml`, `themes/wondertag/layout/ads/send_money.phtml`, `themes/wondertag/layout/container.phtml`

---

## Task 2: Story Not Showing to All Users
**Status:** [x] Completed
**Issue:** Stories posted by users are not visible to all other users who should be able to see them.
**Root Cause:** Story-fetching functions only showed stories from users the viewer **follows**. Non-followed users' stories were invisible. No expiration check enforced.
**Fix Applied:**
- Modified all 3 story functions to show stories from **all active users**
- Added `expire > UNIX_TIMESTAMP()` check for 24-hour TTL
- Added blocked-user exclusion in both directions
**Files Modified:** `assets/includes/functions_three.php`

---

## Task 3: Audio and Video Calls Not Working in Messenger
**Status:** [x] Completed
**Issue:** Audio and video call features in the messenger are not functioning properly.
**Root Cause:** Admin config `video_call_request` and `audio_call_request` were set to `pro` — only premium users could use calls.
**Fix Applied:**
- Changed both config values to `all` (database update)
- Upgraded Agora SDK from v3.6.11 to v4.22.0
- Rewrote video call handler with proper `user-left` disconnect propagation
- Added double-tap support for mobile stream swap
**Files Modified:** `Wo_Config` table, `themes/wondertag/layout/video/agora.phtml`, `themes/wondertag/layout/modals/talking.phtml`

---

## Task 4: Double Click on Post
**Status:** [x] Completed
**Issue:** Double-clicking on reaction buttons triggers duplicate AJAX requests.
**Root Cause:** Race condition — `data_react` guard set after AJAX callback, not before.
**Fix Applied:**
1. `Wo_RegisterReactionLike()`: Guard set immediately before AJAX call
2. `Wo_RegisterReaction()`: Added same guard pattern
**Files Modified:** `themes/wondertag/layout/extra_js/content.phtml`

---
---

# BITCHAT — GROWTH & TECHNICAL IMPROVEMENT INDEX

> **Principle:** New development must NOT affect or disturb existing working features. All improvements must be backwards-compatible, reversible, and tested against existing functionality.

---

## Topic 1: Feed Quality Problem (Major Growth Blocker)
**Status:** [x] Completed
**Priority:** Critical
**Impact:** User retention, first impression, platform perception

- Feed dominated by repetitive SEO/link posts
- Creates "advertising site" perception for new users
- Add post dominance control (limit same user frequency)
- Penalize excessive external links
- Improve feed trust before acquiring users

**Implementation:**
- Score-based feed ranking with configurable weights (engagement, media, freshness, penalties)
- Same-user diversity limit (max N posts per user in top results)
- Link-only posts penalized, media-rich posts boosted
- All behind `feed_algorithm_enabled` config toggle
- **Files:** `assets/includes/functions_feed.php`, `assets/includes/functions_spam.php`

---

## Topic 2: Bitchat Algorithm v1 (Feed Ranking)
**Status:** [x] Completed
**Priority:** Critical
**Impact:** Engagement, time-on-site, content quality

- Replace pure chronological feed with score-based ranking
- Score = engagement + media + freshness − spam penalties
- Add PRO boost weight inside score calculation
- Maintain chronological fallback layer

**Implementation:**
- `Wo_GetRankedPosts()` — single SQL scoring query with Redis caching (30s TTL)
- `Wo_BuildFeedWhereClause()` — mirrors `Wo_GetPosts()` privacy/blocking filters
- Scoring formula: `engagement + media_bonus + freshness + pro_boost - spam_penalty - link_penalty - frequency_penalty`
- Admin panel UI for all weight configuration
- Automatic fallback to `Wo_GetPosts()` when disabled
- **Files:** `assets/includes/functions_feed.php`, `xhr/feed_algorithm.php`, `admin-panel/pages/feed-algorithm/content.phtml`

---

## Topic 3: PRO System Protection
**Status:** [x] Completed
**Priority:** High
**Impact:** Revenue, PRO user value

- PRO visibility remains stronger than normal users
- Configurable PRO boost score bonus (default +3.0)
- Promoted posts still render at top (existing behavior untouched)
- Algorithm enhances PRO value, doesn't replace it

**Implementation:**
- PRO boost is a flat score bonus configurable in admin panel
- Promoted posts section in `load-posts.phtml` completely untouched
- PRO users excluded from boosted feed query (`AND boosted = '0'` preserved)
- **Files:** `assets/includes/functions_feed.php`

---

## Topic 4: Anti-Spam & Dominance Control
**Status:** [x] Completed
**Priority:** High
**Impact:** Feed quality, user trust

- Limit >2 posts/hour from same user (frequency penalty)
- Downrank duplicate text via text hash (spam penalty)
- Reduce visibility instead of banning (soft limits only)
- Domain frequency tracking via new `Wo_Spam_Tracking` table

**Implementation:**
- `Wo_TrackPostSpam()` — records domain + text hash per post, hooked into `Wo_RegisterPost()`
- `Wo_GenerateTextHash()` — normalizes text (strips links/mentions/hashtags, lowercase) then MD5
- `Wo_CleanupSpamTracking()` — cron cleanup of records older than 48h
- Frequency penalty: 3.0 per post beyond 2/hour
- Spam penalty: 5.0 per duplicate text in 24h window
- **Files:** `assets/includes/functions_spam.php`, `sql/001_feed_algorithm.sql`

---

## Topic 5: Story System Activation
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Daily retention, engagement loops

- Story visibility already fixed (Task 2) — all users see all stories
- Story boost integrated into feed scoring algorithm
- `Wo_UserHasActiveStory()` checks for unexpired stories
- Creators with active stories get score boost in feed

**Implementation:**
- Story boost score factor in `Wo_BuildRankedFeedIds()` scoring query
- `Wo_UserHasActiveStory()` in `functions_creator.php`
- 24h expiration already enforced by existing system
- **Files:** `assets/includes/functions_feed.php`, `assets/includes/functions_creator.php`

---

## Topic 6: Automation & Scheduled Posting
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Consistency, admin efficiency

- Full scheduled posting system with cron integration
- `Wo_Scheduled_Posts` table for post queue
- Cron publishes via `Wo_RegisterPost()` — identical to normal posting
- Admin panel for viewing/managing scheduled posts

**Implementation:**
- `Wo_CreateScheduledPost()`, `Wo_PublishScheduledPosts()` (cron), `Wo_DeleteScheduledPost()`
- Saves/restores `$wo['user']` context for each publish
- Status tracking: pending, published, failed, cancelled
- Admin toggle: `scheduled_posts_enabled`
- **Files:** `assets/includes/functions_scheduled.php`, `xhr/scheduled_posts.php`, `admin-panel/pages/scheduled-posts/content.phtml`

---

## Topic 7: Ghost Activity Layer (Safe Engagement)
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Perceived activity, new user retention

- Delayed reactions from real admin/system accounts
- Uses existing `Wo_AddReactions()` — real reactions, real notifications
- New user welcome: shorter delay for first-time posters
- Max 1 ghost reaction per post, minimum 5-minute delay

**Implementation:**
- `Wo_QueueGhostReaction()` — queued from `Wo_RegisterPost()` success block
- `Wo_ProcessGhostQueue()` — cron processor, saves/restores user context
- `Wo_Ghost_Queue` table with pending/executed/cancelled status
- Configurable accounts, min/max delay from admin panel
- Auto-cleanup of executed items older than 7 days
- **Files:** `assets/includes/functions_ghost.php`, `xhr/ghost_activity.php`, `admin-panel/pages/ghost-activity/content.phtml`

---

## Topic 8: Creator Mode System
**Status:** [x] Completed
**Priority:** Low
**Impact:** Content quality, user identity

- Creator profile toggle & orange star badge on posts
- Creator dashboard with engagement stats
- Feed score boost for creators
- Featured creators sidebar widget function

**Implementation:**
- `is_creator` column added to `Wo_Users` table
- Creator badge (orange star SVG) in post header, between verified and PRO badges
- `Wo_GetCreatorStats()` — total posts, reactions, comments, shares, followers, weekly stats
- `Wo_GetFeaturedCreators()` for sidebar widget
- Creator dashboard page at `/creator-dashboard`
- Admin toggle: `creator_mode_enabled`
- **Files:** `assets/includes/functions_creator.php`, `xhr/creator.php`, `sources/creator_dashboard.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/layout/story/includes/header.phtml`

---

## Topic 9: TRDC Ecosystem Integration
**Status:** [x] Completed
**Priority:** Low
**Impact:** Token utility, platform economy

- Milestone-based TRDC token rewards for creators
- Rewards: 100 reactions (0.5 TRDC), 500 reactions (2.0 TRDC), 1000 reactions (5.0 TRDC), first video (0.25 TRDC)
- Uses existing wallet system (`Wo_Users.wallet` column)
- Unique constraint prevents double-rewarding

**Implementation:**
- `Wo_ProcessMilestoneRewards()` — cron function checking all creators
- `Wo_AwardTRDC()` — updates wallet, logs to `Wo_TRDC_Rewards`, sends notification
- `Wo_TRDC_Rewards` table with unique constraint on (user_id, milestone_type, post_id)
- Configurable milestones from admin panel
- Admin toggle: `trdc_creator_rewards_enabled`
- **Files:** `assets/includes/functions_trdc_rewards.php`, `xhr/trdc_rewards.php`, `admin-panel/pages/trdc-rewards/content.phtml`

---

## Topic 10: Feed Perception Optimization
**Status:** [x] Completed
**Priority:** Critical
**Impact:** First impression, new user conversion

- First posts in feed define platform quality
- Image/video/audio posts get +2.0 score bonus
- Link-only posts penalized (-2.0), link+text gets -1.0
- Same-user diversity enforced (max 2 posts per user in top results)
- Feed must feel social, not SEO blog

**Implementation:**
- All handled by the feed algorithm scoring formula (Topic 2)
- Media bonus, link penalty, and diversity limit are configurable from admin panel
- Chronological fallback always available
- **Files:** `assets/includes/functions_feed.php`

---

## Topic 11: Platform Positioning Correction (Strategic)
**Status:** [x] Completed
**Priority:** Strategic
**Impact:** Brand perception, growth trajectory

- Achieved by Topics 1-10 working together
- Feed algorithm + anti-spam + creator mode = engagement-focused platform
- Link dumping penalized, original content rewarded
- Creator identity system encourages quality over quantity
- TRDC rewards create economic incentive for engagement

**Implementation:**
- Strategic goal achieved through all other topics combined
- All features toggleable via admin panel config flags
- Instant rollback: set any `*_enabled` config to `0`
