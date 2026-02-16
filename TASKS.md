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
