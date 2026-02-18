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

---
---

# BITCHAT — PHASE 2: FEED & ENGAGEMENT FEATURES

> **Goal:** Make the platform feel more active, curated, and engaging with 4 high-impact features.

---

## Feature 1: "Today on Bitchat" Trending Section
**Status:** [x] Completed
**Priority:** High
**Impact:** First impression, perceived activity

- Horizontal card strip above feed showing top 3-5 engagement posts from last 24h
- Ranked by reactions + comments*2 + shares*1.5
- Max 1 post per user, requires media or 50+ char text
- Redis cached for 5 minutes
- Clicking a card opens the post
- Dark mode support

**Implementation:**
- `Wo_GetTrendingPosts()` — single scoring SQL, blocked user exclusion, Redis cache (5min TTL)
- Template: horizontal scrollable strip with thumbnail + avatar + reaction count
- Only shows when 2+ qualifying trending posts exist
- **Files:** `assets/includes/functions_feed.php`, `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/css/style.css`

---

## Feature 2: First 5 Posts Quality Gate
**Status:** [x] Completed
**Priority:** High
**Impact:** First impression, content quality perception

- Ensures first 5 feed slots prioritize media-rich or high-engagement posts
- Prevents "all affiliate links" first impression
- Separates posts into quality (media or engagement >= 5) and other
- Fills first 5 slots with quality posts first, rest stays in score order
- Only affects page 1, disabled when feed algorithm is off

**Implementation:**
- ~35 lines added to `Wo_BuildRankedFeedIds()` after diversity filtering
- No new config — uses existing `feed_algorithm_enabled` toggle
- **Files:** `assets/includes/functions_feed.php`

---

## Feature 3: Story Creation Prompt
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Story adoption, daily engagement

- Dismissible banner below story carousel for users without an active story
- "Share your moment — stories appear at the top for all users" + Create Story button
- Cookie-based 24h dismiss (won't show again after dismissal)
- Only shows when `can_use_story` is enabled and user is logged in
- Dark mode support

**Implementation:**
- Inline PHP check for active story via `Wo_UserStory` table
- Cookie `story_prompt_dismissed` with 86400s max-age
- **Files:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/css/style.css`

---

## Feature 4: Anti-Flood Posting UX
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Post quality, behavioral nudge

- After 3+ posts in last hour, shows friendly guidance message
- "You've posted X times this hour. Posts spread out over time reach more people."
- Auto-hides after 10 seconds — behavioral nudge, not a hard block
- Checks via AJAX after each successful post submission

**Implementation:**
- `Wo_GetPostingCooldownInfo()` — counts posts in last 60 minutes, returns cooldown info if >= 3
- `cooldown_check` endpoint added to `xhr/posts.php`
- Hidden notice div in publisher box, shown via JS after post success callback
- **Files:** `assets/includes/functions_feed.php`, `xhr/posts.php`, `themes/wondertag/layout/story/publisher-box.phtml`, `themes/wondertag/custom/css/style.css`

---
---

# BITCHAT — PHASE 3: CREATOR DISCOVERY & TRDC VISIBILITY

> **Goal:** Drive creator discovery, TRDC engagement, and dashboard quality.

---

## Feature 1: Suggested Creators Sidebar Widget
**Status:** [x] Completed
**Priority:** High
**Impact:** Creator discovery, follows

- Sidebar widget showing 3-4 creators the user doesn't follow
- Ordered by engagement instead of random
- Includes creator badge (orange star) and follow buttons
- Redis cached (5min TTL) per user

**Implementation:**
- Modified `Wo_GetFeaturedCreators()` to exclude followed creators, order by engagement
- Added widget to sidebar after PRO members section
- **Files:** `assets/includes/functions_creator.php`, `themes/wondertag/layout/sidebar/content.phtml`

---

## Feature 2: TRDC Milestone Progress
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Creator motivation, TRDC engagement

- Progress bars showing creator's progress toward next reward milestones
- Shows current count vs threshold, reward amount, claimed checkmarks
- Pure read-only display using existing milestone data

**Implementation:**
- `Wo_GetMilestoneProgress()` — calculates progress for each milestone type
- Progress section added between stats row and reward history
- **Files:** `assets/includes/functions_trdc_rewards.php`, `sources/creator_dashboard.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`

---

## Feature 3: Enhanced Creator Dashboard
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Creator retention, dashboard utility

- TRDC Wallet card showing balance + total earned from rewards
- Weekly engagement summary with 7-day CSS bar chart
- Posts/reactions this week stat

**Implementation:**
- `Wo_GetCreatorWeeklyEngagement()` — daily reaction/comment counts for 7 days
- Wallet card, weekly chart, and additional stats added to dashboard
- **Files:** `assets/includes/functions_creator.php`, `sources/creator_dashboard.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/custom/css/style.css`

---

## Bug Fix: PHP 8.2 Fatal Errors on Home Page
**Status:** [x] Completed
**Priority:** Critical (page completely broken)

Three PHP 8.2 strict typing issues causing fatal errors that abort page rendering:
1. `home/content.phtml:100` — `mysqli_query($sqlConnect, ...)` where `$sqlConnect` undefined in `Wo_LoadPage()` scope → Fatal TypeError
2. `functions_feed.php:489` — `Wo_GetTrendingPosts()` block query used non-existent columns `block_userid`/`userid` instead of `blocker`/`blocked` → Uncaught mysqli_sql_exception
3. `functions_one.php:5122` — `implode(',', $wo['ad-con']['ads'])` where value isn't always an array → TypeError (also 2 instances in functions_three.php)

**Files:** `themes/wondertag/layout/home/content.phtml`, `assets/includes/functions_feed.php`, `assets/includes/functions_one.php`, `assets/includes/functions_three.php`

---
---

# BITCHAT — PHASE 4: RETENTION & DISCOVERY

> **Goal:** Reduce churn, improve content discovery, and increase engagement with 3 features.

---

## Feature 1: Discover Page
**Status:** [x] Completed
**Priority:** High
**Impact:** Content discovery, engagement, time-on-site

- Dedicated `/discover` page with trending posts, popular creators, hashtags, people suggestions
- 4 sections: Trending Now (card grid), Popular Creators (horizontal scroll), Trending Hashtags (pill cloud), People You May Know (grid)
- Follow buttons on creators/people, dark mode + mobile responsive
- Nav link in sidebar "Me" section

**Implementation:**
- New `sources/discover.php` — loads data via `Wo_GetTrendingPosts()`, `Wo_GetFeaturedCreators()`, `Wa_GetTrendingHashs()`, `Wo_UserSug()`
- New `themes/wondertag/layout/discover/content.phtml` — full template with 4 sections
- Route added in `index.php`, URL rewrite in `.htaccess` + `nginx.conf`
- **Files:** `sources/discover.php`, `themes/wondertag/layout/discover/content.phtml`, `index.php`, `.htaccess`, `nginx.conf`, `themes/wondertag/layout/sidebar/left-sidebar.phtml`, `assets/includes/data.php`, `themes/wondertag/custom/css/style.css`

---

## Feature 2: New User Welcome Flow
**Status:** [x] Completed
**Priority:** High
**Impact:** New user retention, profile completion

- 3-step onboarding wizard on first login: upload avatar → follow people → start exploring
- Only triggers for new users with default avatar + `onboarding_completed=0`
- Existing users automatically marked as onboarded via SQL migration
- Step 1: Avatar upload (reuses existing `update_general_settings` XHR)
- Step 2: Follow suggestions with "Follow All" button (creators + general)
- Step 3: Welcome complete → marks onboarding done → redirects to home
- All steps skippable

**Implementation:**
- `onboarding_completed` column on `Wo_Users` (migration marks all existing users as completed)
- Redirect in `index.php` — checks `onboarding_completed == 0` + default avatar + not on setup page
- New `sources/welcome_setup.php` — loads creator + people suggestions
- New `themes/wondertag/layout/welcome_setup/content.phtml` — 3-step wizard (pure HTML/CSS/JS)
- New `xhr/onboarding.php` — `complete` action sets `onboarding_completed = 1`
- **Files:** `sources/welcome_setup.php`, `themes/wondertag/layout/welcome_setup/content.phtml`, `index.php`, `.htaccess`, `nginx.conf`, `xhr/onboarding.php`, `assets/includes/data.php`, `themes/wondertag/custom/css/style.css`

---

## Feature 3: Post View Tracking & Creator Reach
**Status:** [x] Completed
**Priority:** Medium
**Impact:** Creator analytics, engagement visibility

- Impression counter on posts (atomic `post_views + 1` SQL increment)
- Eye icon + view count in post footer (shown when > 0)
- Batch increment on feed scroll (load_more_posts in xhr/posts.php)
- Single increment on post detail page (sources/story.php)
- "Total Reach" stat card on creator dashboard

**Implementation:**
- `post_views` INT UNSIGNED column on `Wo_Posts` (via SQL migration)
- `sources/story.php` — increments on single post view
- `xhr/posts.php` — batch increment via `UPDATE ... WHERE id IN (...)` on feed loads
- `themes/wondertag/layout/story/includes/footer.phtml` — eye icon + count after comments
- `Wo_GetCreatorStats()` — new `total_views` field via `SUM(post_views)`
- Creator dashboard — "Total Reach" stat card with purple accent
- **Files:** `sources/story.php`, `xhr/posts.php`, `themes/wondertag/layout/story/includes/footer.phtml`, `assets/includes/functions_creator.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `sql/002_phase4_retention.sql`


follow step-by-step without breaking the platform.

This is **NOT priority theory**.
This is the **SAFE BUILD SEQUENCE** so changes don’t conflict with login, referrals, or feed logic.

---

# ✅ BITCHAT — CODER TASK ORDER SHEET

*(Follow in exact order)*

---

# 🔴 PHASE 1 — STABILITY FIRST (DO NOT SKIP)

👉 Nothing growth-related should be touched before this.

## Step 1 — Fix Session & Login System

Coder tasks:

* Fix session expired popup
* Fix login button closing dialog instead of logging in
* Verify cookie lifetime
* Verify HTTPS secure cookies
* Sync PHP session + NodeJS auth

✅ Expected result:
Users stay logged in reliably.

---

## Step 2 — Fix Online User Tracking

* Update last_activity timestamp correctly
* Check cron jobs
* Fix websocket presence updates

✅ Admin dashboard must not show **0 online users**.

---

## Step 3 — Notification & Cron Reliability

* Verify cronjob execution frequency
* Fix delayed notifications
* Confirm queue processing

⚠️ Referral rewards depend on cron stability.

---

# 🟠 PHASE 2 — REFERRAL FOUNDATION

(Only after login stability)

---

## Step 4 — Referral Tracking Persistence

* Save referrer_id permanently in DB
* Referral survives logout/session reset
* Cookie lifetime ≥ 30 days
* Validate referral link parsing

✅ Test:
User signs up → logs out → logs in → referral still exists.

---

## Step 5 — Anti-Abuse Base Rules

Before rewards go live:

* block self-referrals
* block same IP rewards
* exclude admin/fake users

⚠️ Do BEFORE enabling rewards.

---

## Step 6 — Rename Affiliate UI

(Language edits only)

Change:

* Affiliates → Invite & Earn
* Commission → Reward

Safe cosmetic change.

---

# 🟡 PHASE 3 — TRDC CONNECTION

(Now economy becomes active)

---

## Step 7 — Affiliate Earnings → TRDC Wallet

* Remove separate affiliate balance usage
* Auto-credit TRDC wallet

Test flow:
Referral signup → TRDC credited.

---

## Step 8 — Referral Stats Panel

Add UI box:

* invited users
* rewards earned
* invite link + copy button

(No backend redesign.)

---

# 🟢 PHASE 4 — FEED & CREATOR MOMENTUM

(Now growth behavior begins)

---

## Step 9 — New Creator Boost Logic

Modify feed query:

```
IF account_age < 7 days → increase ranking weight
```

Also boost first 3 posts.

---

## Step 10 — Zero Engagement Protection

If post has no reactions after X minutes:

* trigger minimal engagement.

Prevents psychological drop-off.

---

## Step 11 — TRDC Post Boost Button

Add:
“Boost Post with TRDC”

Effect:
Temporary feed priority multiplier.

---

# 🔵 PHASE 5 — VISIBILITY & COMPETITION

(Platform starts self-promotion)

---

## Step 12 — Creator Growth Stats
**Status:** [x] Completed

Add dashboard section:

* reach score
* invited users
* engagement count

**Implementation:**
- Added 3 growth stat cards to creator dashboard: Reach Score, Invited Users, Total Engagement
- Extended `Wo_GetCreatorStats()` with `reach_score`, `invited_users`, `total_engagement` fields
- **Files:** `assets/includes/functions_creator.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/custom/css/style.css`

---

## Step 13 — Leaderboard Page
**Status:** [x] Completed

Auto-generate:

* top creators
* top inviters
* top TRDC earners

**Implementation:**
- Created `/leaderboard` page with 3 tabs: Top Creators (by engagement), Top Inviters (by referrals), Top Earners (by TRDC wallet)
- Medal icons (gold/silver/bronze) for top 3 in each category
- Route added in `index.php`, `.htaccess`, `nginx.conf`
- Sidebar link added in left sidebar navigation
- **Files:** `sources/leaderboard.php` (NEW), `themes/wondertag/layout/leaderboard/content.phtml` (NEW), `index.php`, `ajax_loading.php`, `.htaccess`, `nginx.conf`, `themes/wondertag/layout/sidebar/left-sidebar.phtml`, `themes/wondertag/custom/css/style.css`

---

## Step 14 — Creator Rank Badges
**Status:** [x] Completed

Auto-assign ranks based on:

* engagement
* activity
* referrals

**Implementation:**
- `Wo_GetCreatorRank()` function calculates rank from composite score (engagement + posts*2 + invites*10 + followers*3)
- 4 tiers: Rising Star (green), Contributor (blue), Influencer (purple), Champion (gold)
- Rank badge displayed next to Creator badge in dashboard header
- **Files:** `assets/includes/functions_creator.php`, `sources/creator_dashboard.php`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/custom/css/style.css`

---

# 🟣 PHASE 6 — MOMENTUM POLISH

(Only after everything works)

---

## Step 15 — Ghost Activity Timing
**Status:** [x] Completed

* Randomize engagement delay
* Avoid instant reactions

**Implementation:**
- Already implemented in Topic 7 (Ghost Activity Layer)
- Configurable min/max delay (default 300s-360s minimum), randomized per reaction
- New user welcome: shorter delay for first-time posters
- Admin panel controls at Admin Panel > Ghost Activity
- **Files:** `assets/includes/functions_ghost.php`, `xhr/ghost_activity.php`, `admin-panel/pages/ghost-activity/content.phtml`

---

## Step 16 — Announcement Banner System
**Status:** [x] Completed

Reusable banner controlled from admin panel.

**Implementation:**
- Admin panel page for banner management (enabled/disabled, text, URL, background color, text color)
- Live preview in admin panel
- Site-wide banner displayed after header with sessionStorage-based dismiss
- XHR handler with admin-only access, URL validation, color sanitization
- 5 config rows in `Wo_Config`: `announcement_banner_enabled`, `_text`, `_url`, `_bg`, `_color`
- **Files:** `admin-panel/pages/announcement-banner/content.phtml` (NEW), `xhr/announcement_banner.php` (NEW), `themes/wondertag/layout/container.phtml`, `admin-panel/autoload.php`, `themes/wondertag/custom/css/style.css`

---

## Step 17 — Invite Button Exposure
**Status:** [x] Completed

Add shortcut:
Sidebar + profile + creator dashboard.

**Implementation:**
- Left sidebar: Added "Invite & Earn" link in navigation
- Creator dashboard: Added "Invite & Earn" button in header actions
- Right sidebar: Added compact Invite & Earn widget between creators and trending hashtags
- All buttons link to existing affiliate/invite page
- **Files:** `themes/wondertag/layout/sidebar/left-sidebar.phtml`, `themes/wondertag/layout/creator_dashboard/content.phtml`, `themes/wondertag/layout/sidebar/content.phtml`, `themes/wondertag/custom/css/style.css`

---

# ⚫ FINAL QA CHECKLIST (MANDATORY)

Coder must test:

* signup with referral ✔
* logout/login ✔
* TRDC reward credited ✔
* boosted post appears higher ✔
* leaderboard updates ✔
* new user gets engagement ✔

---

# 📅 REALISTIC BUILD FLOW

| Week   | Work                  |
| ------ | --------------------- |
| Week 1 | Stability fixes       |
| Week 2 | Referral core         |
| Week 3 | TRDC integration      |
| Week 4 | Feed & creator boosts |
| Week 5 | Leaderboards & ranks  |
| Week 6 | Polish & testing      |

---

# 🚨 VERY IMPORTANT RULE

Coder must **not**:

* redesign UI globally
* change database structure unnecessarily
* rebuild affiliate system

Only extend existing modules.

---
---

# BITCHAT — ADDITIONAL TASKS (Post-Step 17)

> Tasks performed after all 17 steps were completed.

---

## Task A1: Persistent Login — Always Stay Logged In
**Status:** [x] Completed
**Priority:** High
**Requirement:** All users (current + future) stay logged in until manual logout.

**Investigation:**
- Login system already uses 10-year persistent cookies with rolling refresh across all login paths (standard, social, Google)
- `Wo_IsLogged()` already refreshes cookie on every request

**Fix Applied:**
- `.user.ini`: Changed `session.gc_maxlifetime` from `14400` (4 hours) to `2592000` (30 days) to align with `assets/init.php` session config
- **Files:** `.user.ini`

---

## Task A2: Messenger Always On
**Status:** [x] Completed (No Code Change Needed)
**Priority:** High
**Requirement:** Messenger always on when users are online.

**Investigation:**
- `chatSystem=1` (enabled), `chat_request=all` (no approval needed), `user_lastseen=1` (online tracking active), `showlastseen` default=1
- All settings already correctly configured in admin panel / database

---

## Task A3: Notifications Always On by Default
**Status:** [x] Completed
**Priority:** High
**Requirement:** All notification types enabled by default for all users (current + future).

**Fixes Applied:**
1. **Bug fix** in `functions_one.php:2870`: `= !1` was an assignment (setting value to false) instead of comparison `!= 1` — fixed to `!= 1`
2. **Default fallback**: Changed empty `notification_settings` fallback from `array()` (all off) to all-enabled defaults:
   - `e_liked=1, e_shared=1, e_wondered=0, e_commented=1, e_followed=1, e_accepted=1, e_mentioned=1, e_joined_group=1, e_liked_page=1, e_visited=1, e_profile_wall_post=1, e_memory=1`
3. DB column default already had all notifications enabled for new registrations
- **Files:** `assets/includes/functions_one.php`

---

## Task A4: Remove Post Cost in TRDC from Publisher
**Status:** [x] Completed
**Priority:** Medium
**Requirement:** Remove "Post Cost in TRDC (min 10 TRDC)" input and "Buy TRDC Now" dropdown from post composer UI.

**Fix Applied:**
- Removed the TRDC post cost input field and Buy TRDC Now dropdown from publisher-box.phtml
- **Files:** `themes/wondertag/layout/story/publisher-box.phtml`

---

## Task A5: AI Credits System Verification
**Status:** [x] Completed (No Code Change Needed)
**Priority:** Medium
**Requirement:** Verify AI credits system for post/image generation works correctly.

**Investigation:**
- Fully built-in WoWonder feature, configurable via Admin Panel > AI Settings
- Current config: `credit_price=100`, `generated_image_price=100`, `generated_word_price=10`
- Both credit systems enabled for all users
- No code changes needed — all manageable from admin panel

---

## Task A6: Database Tables & Config Sync
**Status:** [x] Completed
**Priority:** Critical
**Requirement:** Ensure all tables and config created in code exist on the live server.

**Fixes Applied:**
1. Created missing `Wo_Ghost_Queue` table (id, post_id, actor_user_id, action_type, action_data, execute_at, executed_at, status)
2. Created missing `Wo_TRDC_Rewards` table (id, user_id, milestone_key, amount, reason, created_at)
3. Inserted 5 `announcement_banner_*` config rows into `Wo_Config`
4. Changed `affiliate_type` from `0` to `1` (enabled invite & earn system)
5. Flushed Redis cache
- **Files:** Database changes only (no code files modified)
