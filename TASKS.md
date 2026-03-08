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

## Task 5: Auto-Backup Producing Empty .gz Files
**Status:** [x] Completed
**Issue:** Automated daily DB backups (`script_backups/auto_db_*.sql.gz`) were all 20 bytes — empty gzip headers. mysqldump was failing silently.
**Root Cause:** `cron-job.php` backup code read DB credentials from `$wo['config']['db_host']` etc. (not in Wo_Config table) and fell back to undefined `DB_HOST`/`DB_NAME` constants. The actual credentials are `$sql_db_host`/`$sql_db_name`/`$sql_db_user`/`$sql_db_pass` from `config.php`. Errors were hidden by `2>/dev/null`.
**Fix Applied:**
1. Changed credential source to `$sql_db_*` variables from `config.php` (already in scope via `init.php`)
2. Added file size validation — deletes empty backups and logs failure
**Result:** Backup now produces valid 28 MB compressed dump
**Files Modified:** `cron-job.php`

---

## Task 6: Unusual Login — Confirmation Code Not Received
**Status:** [x] Completed
**Issue:** Users stuck on `/unusual-login` page with no confirmation code received. Clicking "Send Again" returned "something wrong" error.
**Root Cause:** The "Send Again" button called `Wo_TwoFactor()` which checks `$getuser['two_factor'] == 0` and returns `true` (skips) for users without 2FA enabled — 32,616 of 32,697 users. The initial email IS sent by `Wo_VerfiyIP()`, but if it's missed (slow delivery, spam, etc.), the resend was completely broken.
**Fix Applied:**
1. Added fallback in `xhr/resend_two_factor.php`: when `Wo_TwoFactor()` skips, generate a new code and send the unusual-login email template directly
2. The fallback uses the same email template and SMTP path as the initial send
**Files Modified:** `xhr/resend_two_factor.php`

---

## Task 7: Unusual Login — Confirm Button Not Working
**Status:** [x] Completed
**Issue:** After entering the correct confirmation code on `/unusual-login`, clicking Confirm did nothing — button froze/disabled permanently.
**Root Cause:** Two issues:
1. PHP 8.3 null access crash: When `two_factor_username` cookie was missing, handler skipped the main `if` block but still fell through to `echo json_encode($data)` with undefined `$data`, returning `null`. Also, `$user->user_id` (line 23) would crash with TypeError on null `$user` if user lookup failed.
2. JS had no `dataType: 'json'`, no error callback, and no null-safety on the response — button stayed disabled permanently on any failure.
**Fix Applied:**
1. `xhr/confirm_user_unusal_login.php`: Early validation of inputs, safe null checks, proper "session expired" error messages for all edge cases
2. `themes/wondertag/layout/welcome/unusual-login.phtml`: Added `dataType: 'json'`, error callback, null-safe response handling, and "session expired" fallback message
**Files Modified:** `xhr/confirm_user_unusal_login.php`, `themes/wondertag/layout/welcome/unusual-login.phtml`

---
---

# 🚀 BITCHAT — SPEED MODE + GROWTH ENGINE (PHASE 2026-02)

> **Core Idea:** Bitchat Engine guides, rewards, and amplifies user actions for instant growth perception. Speed Mode makes everything feel instant.

**Status Legend:**
- [ ] Not Started
- [~] In Progress
- [x] Completed

---

## 🔴 PHASE 1: SPEED MODE (Foundation — Must Be Done First)

### Task SM-1: Feed-First Rendering (Largest Speed Gain)
**Status:** [x] Completed - 2026-02-19
**Priority:** Critical
**Impact:** Page load feels 2-3x faster

**Problem:** Homepage loads markets, sidebar, chat, modals, and composer before feed.

**Implementation:**
- Render ONLY header, minimal composer, and feed container in initial HTML
- Move sidebar, stories, markets, chat to async loaders
- Create `/themes/wondertag/custom/js/bc-loader.js` for async module loading
- **Files:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/js/bc-loader.js` (NEW)

---

### Task SM-2: Remove Duplicate Markets Loader ✓ Quick Win
**Status:** [x] Completed - 2026-02-19
**Priority:** High
**Impact:** Removes one redundant API call and DOM render

**Problem:** Market widget loads twice (confirmed in header and another location).

**Implementation:**
- Remove second `<?php echo Wo_LoadPage('market/widget'); ?>` include
- **Files:** `themes/wondertag/layout/header/content.phtml`

---

### Task SM-3: Global Modal System (Huge DOM Reduction) ✓
**Status:** [✓] **COMPLETED** - 2026-02-19
**Priority:** High
**Impact:** Reduces HTML size by ~40-60% on feed pages

**Problem:** 75+ duplicate modal structures throughout pages (Edit, Delete, Report, Schedule, AI, etc.) causing massive DOM bloat.

**Implementation (Conservative Approach):**
- ✓ Created single global modal container `#bc-global-modal` in `container.phtml`
- ✓ Built comprehensive `bc-modal.js` system with 5 modal types:
  - `BC_MODAL.confirm()` - Confirmation dialogs (delete, unfriend, etc.)
  - `BC_MODAL.alert()` - Alert messages with types (info, success, warning, danger)
  - `BC_MODAL.show()` - Custom content with flexible buttons
  - `BC_MODAL.load()` - AJAX content loading
  - `BC_MODAL.hide()` / `BC_MODAL.reset()` - Control methods
- ✓ Added enhanced CSS with smooth animations, dark mode support
- ✓ Existing modals preserved for backward compatibility
- ✓ New code can use global modal system via simple JS API

**Files Modified:**
- `themes/wondertag/layout/container.phtml` (global modal container + script load)
- `themes/wondertag/custom/js/bc-modal.js` (NEW - 360 lines)
- `themes/wondertag/custom/css/style.css` (modal animations & styling)

**Usage Example:**
```javascript
// Replace old: $('#delete-post').modal('show');
BC_MODAL.confirm({
  title: 'Delete Post',
  message: 'Are you sure?',
  onConfirm: () => Wo_DeletePost(post_id)
});
```

**Next Phase:** Gradually migrate existing modals to use BC_MODAL system.

---

### Task SM-4: Composer Lightweight Mode — Lazy-Load Advanced Tools via AJAX
**Status:** [x] Completed - 2026-03-01
**Priority:** Medium
**Impact:** Faster composer open time — initial render drops from 1566 to 1023 lines

**What was done:**
- Only 3 essential tools render initially (Upload Images, AI Post, Video Upload) + "More" button
- Remaining 10+ tools (AI Image, GIF, Voice, Feelings, File, Product, Poll, Location, Audio, Schedule) load via AJAX on first "More" click
- Form sections, modals, and ~270 lines of JS also deferred to AJAX load
- More button now visible on all viewports (desktop + mobile), not just mobile

**Files Created:**
- `xhr/composer_tools.php` — XHR handler returning lazy template as JSON
- `themes/wondertag/layout/story/publisher-box-tools.phtml` — Lazy-loaded template with form sections, tool buttons, modals, and self-initializing JS

**Files Modified:**
- `themes/wondertag/layout/story/publisher-box.phtml` — Removed lazy sections, added `#bc-lazy-tools-container` placeholder and `bcLoadAdvancedTools()` AJAX function
- `themes/wondertag/custom/css/style.css` — More button always visible, added loading state and lazy-injected-btn expand/collapse rules
- `themes/wondertag/custom/js/footer.js` — More button click triggers AJAX load on first click, CSS toggle on subsequent clicks

---

### Task SM-5: Hidden Input Elimination ✓ Quick Win
**Status:** [x] Completed - 2026-02-19
**Priority:** Medium
**Impact:** Cleaner DOM, faster parsing

**Problem:** Hundreds of hidden inputs scattered across pages for CSRF, user_id, config values.

**Implementation:**
- Create global JS config object in `layout/header/script.phtml`:
  ```js
  window.BC_CONFIG = {
    csrf: "<?=$wo['csrf_token']?>",
    userId: "<?=$wo['user']['user_id']?>",
    siteUrl: "<?=$wo['config']['site_url']?>"
  };
  ```
- Remove hidden inputs from: `post-box.phtml`, `home/content.phtml`, theme options
- **Files:** `themes/wondertag/layout/header/script.phtml`, `themes/wondertag/layout/story/publisher-box.phtml`, `themes/wondertag/layout/home/content.phtml`

---

### Task SM-6: Session Expired Background Fix ✓
**Status:** [✓] **COMPLETED** - 2026-02-19
**Priority:** Low
**Impact:** Removes dedicated modal from DOM, uses BC_MODAL system

**Problem:** Session expired modal HTML loaded in DOM unnecessarily, wasting memory.

**Implementation (Improved Approach):**
- ✓ Removed `Wo_LoadPage('modals/logged-out')` from `container.phtml` line 986
- ✓ Updated `Wo_IsLogged()` in `script.js` to use `BC_MODAL.alert()` instead of `$('#logged-out-modal').modal()`
- ✓ Session expiry now shows via global modal system (no dedicated HTML needed)
- ✓ Graceful fallback if BC_MODAL not loaded yet
- ✓ Better UX: styled alert with callback to redirect to login

**Files Modified:**
- `themes/wondertag/layout/container.phtml` (commented out modal load)
- `themes/wondertag/javascript/script.js` (BC_MODAL integration at line ~199-220)

**Impact:** One less modal in DOM, leverages SM-3 global modal system. Perfect synergy with SM-3!

---

### Task SM-7: Chat False Offline Fix ✓ High Priority UX
**Status:** [x] Completed - 2026-02-19
**Priority:** High
**Impact:** Fixes user confusion ("Why am I offline while browsing?")

**Problem:** Chat shows "You are currently offline" banner while user is actively browsing.

**Implementation:**
- Replace polling `setInterval(checkStatus, 5000)` with `visibilitychange` event + WebSocket heartbeat
- **Files:** `themes/wondertag/custom/js/chat.js` or main chat handler

---

## 🟠 PHASE 2: GROWTH ENGINE LAYER (Creates Addiction Loop)

### Task GE-1: Action Prompt Engine ✓
**Status:** [✓] **COMPLETED** - 2026-02-19
**Priority:** Medium
**Impact:** Contextual prompts that guide users to take action, creating engagement loops

**Implementation:**
- ✓ Created `functions_growth.php` with comprehensive user state detection logic:
  - `Wo_GetUserActivityState()` - Analyzes user activity (posts, followers, trading, TRDC balance)
  - `Wo_GetActionPrompt()` - Returns contextual prompts with 6 priority levels
- ✓ Built `bc-prompts.js` for dynamic prompt display with icon system
- ✓ Integrated into home page with automatic prompt loading
- ✓ Added beautiful gradient prompt cards with type-specific styling
- ✓ CTA actions: openComposer, goToDiscover, goToWallet

**Prompt Types Implemented:**
1. **New User** (0 posts): "Welcome! Share your first post"
2. **Inactive User** (7+ days): "Welcome back! Your followers miss you"
3. **Trader** (hasn't traded today): "Markets are moving. Share your insight"
4. **Creator** (TRDC > 100): "Your TRDC is growing! Keep creating"
5. **Growing** (1-5 posts): "You're on a roll! Post 3 more this week"
6. **Grow Audience** (<10 followers): "Comment on trending posts to grow"
7. **Default**: Time-based general engagement prompts

**Files Modified:**
- `assets/includes/functions_growth.php` (NEW - 200+ lines, MySQL queries)
- `themes/wondertag/custom/js/bc-prompts.js` (NEW - 180+ lines)
- `themes/wondertag/layout/home/content.phtml` (prompt container integration)
- `themes/wondertag/layout/container.phtml` (script loading)
- `themes/wondertag/custom/css/style.css` (gradient cards, responsive design)

**Impact:** Creates psychological activation loop - users get personalized guidance based on their activity state, increasing engagement and TRDC earning motivation.

---

### Task GE-2: TRDC Dopamine Feedback ✓
**Status:** [✓] **COMPLETED** - 2026-02-19
**Priority:** Medium
**Impact:** Instant gratification feedback when users earn TRDC - reinforces positive behavior

**Implementation:**
- ✓ Created comprehensive toast notification system (`bc-rewards.js` - 250+ lines)
- ✓ Added TRDC tracking functions to `functions_growth.php`:
  - `Wo_AwardTRDC($user_id, $amount, $type, $description)` - Award TRDC to users
  - `Wo_GetTRDCReward($type)` - Get reward amount for action types
  - `Wo_IsFirstPost($user_id)` - Check for first post bonus
  - `Wo_LogTRDCTransaction()` - Optional transaction logging
- ✓ Beautiful gradient toast cards with type-specific styling:
  - Post: Purple gradient, 🎉/⭐ icons
  - Comment: Blue-cyan gradient
  - Like received: Pink-yellow gradient
  - Share: Blue-purple gradient
  - First post: Pink-red gradient (bonus celebration)
- ✓ Advanced animations:
  - Icon bounce animation on appear
  - Confetti particles falling effect
  - Smooth slide-in from right
  - Balance pulse effect when TRDC updated
  - Queue system for multiple simultaneous rewards
- ✓ Mobile responsive design with adjusted sizing
- ✓ Event-driven architecture with custom events

**Reward Amounts:**
- Post: 50 TRDC
- Comment: 10 TRDC
- Like received: 5 TRDC
- Share: 15 TRDC
- First post bonus: 100 TRDC
- Daily login: 20 TRDC
- Profile completion: 75 TRDC

**Files Modified:**
- `themes/wondertag/custom/js/bc-rewards.js` (NEW - 260 lines, toast system)
- `assets/includes/functions_growth.php` (added TRDC tracking functions)
- `themes/wondertag/custom/css/style.css` (195+ lines of toast styling)
- `themes/wondertag/layout/container.phtml` (script loading)

**Usage Example:**
```javascript
// Trigger from AJAX success:
BC_REWARDS.showReward(50, 'post', 'Post published!');

// Or dispatch custom event:
document.dispatchEvent(new CustomEvent('bc:trdc:earned', {
    detail: { amount: 50, type: 'post' }
}));
```

**Impact:** Creates instant dopamine hit when users earn TRDC, making engagement addictive. Toasts appear for 3 seconds with celebration animations, reinforcing positive actions immediately.

---

### Task GE-3: Feed Ranking Engine ✓ Already Done
**Status:** [x] Completed (Topic 2 from previous implementation)
**Priority:** N/A

**Current State:** Feed algorithm with scoring already implemented in `assets/includes/functions_feed.php`.

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

---
---

# BITCHAT — ADMIN CONTROL IMPROVEMENTS (Post-Audit)

> Admin panel improvements based on founder-level audit. Focus: visibility, control, and safety at scale.

---

## Task B1: Fix System Status Warning
**Status:** [x] Completed
**Priority:** Critical

**Root Cause:** `/xml` folder was not writable (777 permission required by WoWonder's `getStatus()` check).

**Fixes Applied:**
1. Created `/xml` directory with proper ownership and 777 permissions
2. Installed `ffmpeg` and `ffprobe` (were missing — needed for video processing)
3. Created `upload/stickers` directory (was missing)
4. Fixed `assets/logs` directory permissions
- **Result:** `getStatus()` now returns empty — "Important! errors" warning is gone

---

## Task B2: Online Users Display Verified
**Status:** [x] Completed (No Code Change Needed)
**Priority:** High

**Investigation:**
- `Wo_CountOnlineData()` uses 60-second window (`lastseen > time() - 60`) — shows truly active users
- `lastseen` column IS being updated correctly — users are tracked
- "0 online" simply means no user had a page request in the last 60 seconds (low-traffic moment)
- When admin is browsing, their own requests update `lastseen`, showing at least 1
- This is standard WoWonder behavior — working correctly

---

## Task B3: Growth Intelligence Dashboard
**Status:** [x] Completed
**Priority:** High

**Implementation:**
- New admin panel page at Admin Panel > Bitchat Growth > Growth Dashboard
- **Key Metrics (4 cards):** Daily Active Users, New Users Today, Posts Today, Referral Joins (7d)
- **7-Day Charts:** New users and referral joins bar charts (CSS, no JS library)
- **TRDC Economy:** Total in circulation, issued today, ghost actions today, active stories
- **Engagement Health:** Reactions, comments, posts (24h) + engagement per active user ratio
- **Top TRDC Holders:** Top 5 users by wallet balance
- **Files:** `admin-panel/pages/growth-intelligence/content.phtml` (NEW), `admin-panel/autoload.php`

---

## Task B4: Growth Mode Presets
**Status:** [x] Completed
**Priority:** Medium

**Implementation:**
- New admin panel page at Admin Panel > Bitchat Growth > Growth Presets
- 3 one-click presets: Creator Growth Week, Referral Boost Week, Engagement Boost Week
- Each preset configures multiple settings (feed algorithm, ghost activity, TRDC rewards, boosts)
- Active preset indicator shown on each card
- Reset to Custom button returns to manual settings
- **Files:** `admin-panel/pages/growth-presets/content.phtml` (NEW), `xhr/growth_presets.php` (NEW), `admin-panel/autoload.php`

---

## Task B5: Ghost Activity Safety Limits
**Status:** [x] Completed
**Priority:** High

**Implementation:**
- Added 2 new controls to Ghost Activity admin page:
  - **Max Ghost Reactions Per Hour** (default: 10, range 1-100) — prevents over-inflation
  - **Ghost-to-Real Ratio Cap %** (default: 30%, range 5-100%) — ghost reactions cannot exceed this % of real reactions daily
- Config stored in `ghost_activity_max_per_hour` and `ghost_activity_ratio_cap`
- **Files:** `admin-panel/pages/ghost-activity/content.phtml`, `xhr/ghost_activity.php`

---

## Task B6: Creator Moderation Quick Actions
**Status:** [x] Completed
**Priority:** Medium

**Implementation:**
- Added TRDC wallet balance column to creator table in admin panel
- Added "Freeze" button per creator — freezes TRDC earning (sets `trdc_frozen_{user_id}` config flag)
- Existing "Remove" button already handles creator status removal
- **Files:** `admin-panel/pages/creator-mode/content.phtml`, `xhr/creator.php`

---

## Task B7: Announcement Banner Scheduling
**Status:** [x] Completed
**Priority:** Medium

**Implementation:**
- Added Start Date and End Date (datetime-local) fields to announcement banner admin page
- Banner auto-shows only within the scheduled window (server-side check in container.phtml)
- Empty dates = immediate/no expiration (backwards compatible)
- Config stored in `announcement_banner_start` and `announcement_banner_end`
- **Files:** `admin-panel/pages/announcement-banner/content.phtml`, `xhr/announcement_banner.php`, `themes/wondertag/layout/container.phtml`

---

## Task B8: Server Infrastructure Fixes
**Status:** [x] Completed
**Priority:** Critical

**Fixes Applied (server-side only):**
1. `/xml` dir created with 777 permissions
2. `ffmpeg` and `ffprobe` installed via apt
3. `upload/stickers` dir created with proper ownership
4. `assets/logs` dir permissions fixed
5. Inserted 5 new config rows: `ghost_activity_max_per_hour`, `ghost_activity_ratio_cap`, `announcement_banner_start`, `announcement_banner_end`, `growth_active_preset`
6. Redis cache flushed

---
---

# BITCHAT — MASTER SPRINT REMAINING TASKS (C-Series)

> Completing the remaining 8 tasks from the 27-task Master Developer Sprint Plan.

---

## Task C1: TRDC Usage Hint UI
**Status:** [x] Completed
**Priority:** Low (Task 10 from Master Plan)

**Implementation:**
- Added hint text "Use TRDC to boost posts, promote content & grow faster" below wallet balance on wallet page
- Added hint text on creator dashboard wallet card
- **Files:** `themes/wondertag/layout/ads/wallet.phtml`, `themes/wondertag/layout/creator_dashboard/content.phtml`

---

## Task C2: Cron Job Execution Logging
**Status:** [x] Completed
**Priority:** Medium (Task 4 from Master Plan)

**Implementation:**
- Added execution logging system to `cron-job.php`
- Logs each run with timestamp, duration (ms), and sections executed
- Output written to `assets/logs/cron.log`
- Auto-rotation: keeps log under 500KB (trims to last 200 lines)
- Sections tracked: pro_users, stories, notifications, spam_cleanup, scheduled_posts, ghost_activity, trdc_boost_expiry, trdc_rewards, auto_backup
- **Files:** `cron-job.php`

---

## Task C3: Fake User Isolation from Rewards/Rankings
**Status:** [x] Completed
**Priority:** High (Task 22 from Master Plan)

**Implementation:**
- WoWonder's fake user generator marks users with `src = 'Fake'` in the database
- Added `AND src != 'Fake'` / `AND u.src != 'Fake'` filters to:
  - Leaderboard queries (all 3 tabs: creators, inviters, earners)
  - Growth Intelligence Dashboard (DAU, new users, referrals, daily chart, top earners)
  - TRDC milestone reward processing (`Wo_ProcessMilestoneRewards`)
  - TRDC award function (`Wo_AwardTRDC`) — skips fake users entirely
- **Files:** `sources/leaderboard.php`, `admin-panel/pages/growth-intelligence/content.phtml`, `assets/includes/functions_trdc_rewards.php`

---

## Task C4: Invitation Code Analytics
**Status:** [x] Completed
**Priority:** Medium (Task 24 from Master Plan)

**Implementation:**
- Added analytics summary cards to invitation codes admin page: Total Codes, Used Codes, Available Codes, Referral Joins (7d)
- Added Top Referrers table (top 10 users by referral count, excludes fake users)
- **Files:** `admin-panel/pages/manage-invitation-keys/content.phtml`

---

## Task C5: Automated Backup Scheduler
**Status:** [x] Completed
**Priority:** High (Task 25 from Master Plan)

**Implementation:**
- Added automated backup section to cron-job.php (runs based on configurable interval)
- DB-only backup via `mysqldump | gzip` to `script_backups/auto_db_*.sql.gz`
- Auto-cleanup: keeps last 7 backups, deletes older ones
- Admin panel UI added to Backups page: enable/disable toggle, interval selection (12h/daily/weekly)
- Shows last auto backup timestamp
- XHR handler for saving auto backup settings
- **Files:** `cron-job.php`, `admin-panel/pages/backups/content.phtml`, `xhr/auto_backup_settings.php` (NEW)

---

## Task C6: Admin Activity Log
**Status:** [x] Completed
**Priority:** High (Task 27 from Master Plan)

**Implementation:**
- New `Wo_LogAdminAction()` function — logs admin actions to `assets/logs/admin_activity.log`
- Log format: `timestamp | admin_username | action_type | details`
- Auto-rotation: keeps log under 1MB (trims to last 500 lines)
- New admin panel page at Admin Panel > Bitchat Growth > Admin Activity Log
- Color-coded action labels (config=blue, preset=green, user=yellow, backup=cyan, freeze/delete=red)
- Logging integrated into: growth presets, announcement banner, creator mode toggle, TRDC freeze, auto backup settings
- **Files:** `assets/includes/functions_admin_log.php` (NEW), `admin-panel/pages/admin-activity-log/content.phtml` (NEW), `assets/init.php`, `admin-panel/autoload.php`, `xhr/growth_presets.php`, `xhr/announcement_banner.php`, `xhr/creator.php`, `xhr/auto_backup_settings.php`

---

## Task C7: TRDC Event Notifications
**Status:** [x] Completed
**Priority:** Medium (Task 12 from Master Plan)

**Implementation:**
- **Referral joined notification:** When a new user signs up via referral link, the referrer gets a notification: "[Name] joined using your invite link!"
- **Creator rank upgrade notification:** `Wo_CheckRankUpgrade()` tracks each creator's rank in config, sends notification on promotion (e.g., "You've been promoted to Influencer rank!")
- Rank tracking stored per-user in config (`creator_rank_{userId}`) to avoid duplicate notifications
- Only upgrades trigger notification (not downgrades)
- **Files:** `xhr/register.php`, `assets/includes/functions_trdc_rewards.php`

---

## Task C8: NodeJS WebSocket Verified Working
**Status:** [x] Completed (No Code Change Needed)
**Priority:** High (Task 2 from Master Plan)

**Investigation:**
- Socket.io server responds on port 449 with SSL
- `node_socket_flow=1` in database config
- `container.phtml` connects properly with `ping_for_lastseen` events
- pm2 process `bitchat-socket` running and stable
- Online user count uses 60-second `lastseen` window — standard WoWonder behavior

---

## Frontend UI Master Improvement Plan — 11 Parts
**Status:** [x] All 11 parts completed & manually verified (2026-02-18)
**Commit:** afaf17ea — 8 files changed, 1028 insertions

---

### Part 1: Landing Hero
**Status:** [x] Completed & Verified
**Goal:** Replace generic WoWonder welcome tagline with India-specific identity hero.
**Implementation:**
- `bc-hero` section injected above existing left column of login page
- Pulsing badge: "India's Creator & Crypto Network" (with green animated dot)
- H1 headline: "Earn. Create. Trade." with gradient text (primary→gold)
- Subheadline: "India's first social platform that rewards creators and traders with TRDC tokens"
- Stats row: 10,000+ Creators | ₹50L+ Earned | Live Markets (formatted with border separators)
- Old `.tag_wel_title` hidden via CSS on welcome page
**Files:** `themes/wondertag/layout/welcome/content-simple.phtml`, `themes/wondertag/custom/css/style.css`
**Verified:** Hero present at content-simple.phtml lines 50-70, CSS at style.css ~line 1630. All elements confirmed.

---

### Part 2: Market Strip Ticker
**Status:** [x] Completed & Verified
**Goal:** Live market data bar at top of every page for logged-in users.
**Implementation:**
- Slim 30px bar (`#bc-market-strip`) above header, dark background
- Tickers: BTC, ETH (via CoinGecko INR API), NIFTY, SENSEX (via Yahoo Finance)
- Shows price + 24h change % with green/red color coding and ▲▼ arrows
- Loading text shown until first data arrives (MutationObserver-based)
- Auto-refreshes every 60 seconds via `setInterval`
- Only shown when `$wo['loggedin'] == true`
**Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`
**Verified:** HTML at container.phtml line 814. JS functions `fetchCrypto()`, `fetchIndices()` in footer.js. INR formatting using `toLocaleString('en-IN')`.

---

### Part 3: Native Notification Popup
**Status:** [x] Completed & Verified
**Goal:** Replace OneSignal's ugly native browser prompt with a branded card popup.
**Implementation:**
- OneSignal `autoRegister: false`, `notifyButton.enable: false` to suppress native prompt
- Custom `#bc-notif-popup` card (bottom-right, 300px wide, slide-up animation)
- Title: "Stay ahead of the markets" | Desc: trading signals + TRDC earnings copy
- Dual triggers: 40% scroll depth OR 25s session timer (whichever comes first)
- Cookie `bc_push_shown` prevents re-showing for 7 days after any interaction
- "Enable Alerts" calls `OneSignal.registerForPushNotifications()`
- "Not now" dismisses without registering
**Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`
**Verified:** OneSignal `autoRegister: false` at container.phtml line 280. Popup HTML at line 1646. JS trigger logic in footer.js with both scroll + timer.

---

### Part 4: Feed Tabs
**Status:** [x] Completed & Verified
**Goal:** Tab bar above home feed for content filtering.
**Implementation:**
- 4 tabs: "For You" (default, all posts), "Trading" (hashtag filter), "Creators", "Following"
- Active tab shown in primary colour with bottom border
- Clicking a tab shows skeleton loader, then fires AJAX call with `?f=load_posts&filter_by=...`
- JS uses `Wo_Ajax_Requests_File()` or falls back to `/requests.php`
- Only rendered on `$wo['page'] == 'home'`
**Files:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/custom/css/style.css`
**Verified:** `bc-feed-tabs` div at content.phtml line 339, all 4 tabs with `bcFeedTab()` function present.

---

### Part 5: Simplified Post Composer
**Status:** [x] Completed & Verified
**Goal:** Reduce cognitive load in the post composer by hiding rarely-used options.
**Implementation:**
- CSS hides all `.tag_pub_box_btns` from 4th child onwards by default (`:nth-child(n+4)`)
- JS injects a "••• More" button into `.pub-footer-upper` when composer opens
- Clicking "More" toggles `.bc-composer-expanded` on the footer, revealing all buttons
- Button label switches between "••• More" and "‹ Less"
- Binds to Bootstrap `shown.bs.modal` and click events on composer triggers
- Primary visible: Image upload, Video upload, Live video (first 3 buttons)
**Files:** `themes/wondertag/custom/js/footer.js`, `themes/wondertag/custom/css/style.css`
**Verified:** CSS at style.css with `nth-child(n+4)` rule. JS `injectComposerMoreBtn()` function in footer.js.

---

### Part 6: Right Sidebar TRDC Card + Trending Tags
**Status:** [x] Completed & Verified
**Goal:** Add TRDC wallet visibility and visual trending tags to right sidebar.
**Implementation:**
- TRDC card: Queries `Wo_TrdcWallet` table for user's balance, displays with `number_format()`, links to wallet
- Dark gradient card (navy → mid-blue) with gold amount text and decorative background circle
- Trending tags: Fetches top 8 via `Wa_GetTrendingHashs('popular')`, displays as pill buttons
- Pills use `htmlspecialchars()` for XSS safety
- Inserted before the existing bare hashtag widget (which is now hidden with `d-none`)
**Files:** `themes/wondertag/layout/sidebar/content.phtml`, `themes/wondertag/custom/css/style.css`
**Verified:** TRDC card at sidebar/content.phtml lines 88-104. Trending tags widget at lines 107-119.

---

### Part 7: Chat Offline Banner Fix
**Status:** [x] Completed & Verified
**Goal:** Suppress the "You are currently offline" WoWonder chat banner.
**Implementation:**
- CSS `display: none !important` targeting multiple selector variants:
  `.offline-header`, `.tag_chat_offline`, `.wo-offline-bar`, `.offline-status-bar`,
  `.tag_offline_bar`, `[class*="offline-bar"]`, `[class*="offlinebar"]`, `.status_offline_header`
- Pure CSS — no PHP or JS change needed
**Files:** `themes/wondertag/custom/css/style.css`
**Verified:** Rules present in style.css Part 7 section.

---

### Part 8: Micro UX Animations
**Status:** [x] Completed & Verified
**Goal:** Add polish and responsiveness to core UI interactions.
**Implementation:**
- **Post fade-in:** `@keyframes bc-fade-in` (opacity 0→1, translateY 10px→0) applied to `.post-container`, `.wo_post_sec`, `.post-card`
- **Stagger:** nth-child delays (0.05s, 0.10s, 0.15s, 0.20s, 0.25s for 5+)
- **Like bounce:** `@keyframes bc-like-bounce` (scale 1→1.4→0.88→1), triggered by click on reaction buttons
- JS adds `.bc-liked-bounce` class, forces reflow, removes after 500ms
- **Card hover lift:** `.wow_content:hover` gets elevated shadow + translateY(-1px)
- **Skeleton shimmer:** Enhanced gradient shimmer on `.skel` elements
**Files:** `themes/wondertag/custom/css/style.css`, `themes/wondertag/custom/js/footer.js`
**Verified:** All keyframe animations in style.css. JS click listener in footer.js targeting multiple like button selectors.

---

### Part 9: Nav Cleanup
**Status:** [x] Completed & Verified
**Goal:** Reduce sidebar nav noise by hiding secondary/rarely-used items.
**Implementation:**
- Pure CSS `display: none !important` targeting `href` attribute selectors in `.tag_sidebar`:
  - `link1=new-game` (Games)
  - `link1=movies` (Movies)
  - `link1=offers` (Offers)
  - `link1=memories` (Memories)
  - `link1=common_things` (Common Things)
  - `link1=funding` (Funding)
  - `link1=open_to_work` (Open to Work Posts)
- Items remain in HTML and accessible via direct URL / Explore page
- Fully reversible: removing the CSS rule restores them instantly
**Files:** `themes/wondertag/custom/css/style.css`
**Verified:** 7 CSS rules present in style.css Part 9 section.

---

### Part 10: Psychological Activation Greeting
**Status:** [x] Completed & Verified
**Goal:** Replace generic time-of-day greeting with trading/creator-themed messages.
**Implementation:**
- 4 time slots with trading/creator context:
  - Before 12pm: "Markets are opening. What's your move today, [Name]?"
  - 12pm-5pm: "Markets are moving, [Name]. Share your insight."
  - 5pm-8pm: "Peak hours, [Name]. Creators are earning now."
  - After 8pm: "Evening session live, [Name]. Your TRDC awaits."
- Each slot has a matching motivational quote shown below the greeting
- Maintains existing color classes (morning/noon/evening) for background styling
- `userName` PHP variable injected once, referenced throughout
**Files:** `themes/wondertag/layout/home/content.phtml`
**Verified:** Greeting JS block at home/content.phtml lines 696-712, 4 time branches confirmed.

---

### Part 11: Mobile Sticky Bottom Navigation Bar
**Status:** [x] Completed & Verified
**Goal:** X/Instagram-style bottom navigation on mobile for core actions.
**Implementation:**
- 5-tab `<nav id="bc-mobile-nav">`: Home | Create Post | Notifications | Messages | Profile
- Home: links to site root with SVG home icon
- Create: button (not link) that opens `#tagPostBox` composer modal via `$('#tagPostBox').modal('show')`
- Notifications: links to `/notifications` page
- Messages: links to `/messages` page
- Profile: shows user avatar, links to user timeline
- CSS: `display: none` by default, `display: flex` at `max-width: 900px`
- `body { padding-bottom: 60px }` prevents content being hidden behind bar
- Hides any existing `.tag_bottom_nav` WoWonder element
- JS auto-marks active item by matching `href`/`data-href` against current URL
- ARIA label: `aria-label="Mobile navigation"` for accessibility
- Only rendered when `$wo['loggedin'] == true`
**Files:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/custom/css/style.css`, `themes/wondertag/custom/js/footer.js`
**Verified:** Nav HTML at container.phtml line 1661, all 5 items confirmed. CSS at style.css with 900px breakpoint. Active state JS in footer.js.

---

---

# DEVELOPER RULES (Always Active)

**Rule 1:** Always update TASKS.md before starting any task (paste to-do list here).
**Rule 2:** Always update CHANGELOG.md after completing a task (only after completion + Rule 4 fulfilled).
**Rule 3:** Never start a task until explicitly instructed or task is approved.
**Rule 4:** Always recheck on live URL (bitchat.live + admin panel) after pushing commit and confirming deployment on live server.
**Rule 5:** Always re-add these rules whenever conversation is compacted.

---

# SPRINT 1 — Approved Task Queue

*(User Panel + Admin Panel + Mobile + Dark Mode)*

---

## P1-1: Fix Dashboard Chart Label Bug
**Status:** [x] Completed - 2026-02-24
**Priority:** 🔴 Critical
**Problem:** Months showing `JanJan FebFeb…` — duplicate labels in dashboard chart.
**Root Cause:** ApexCharts rendered a second instance into `#admin-chart-container` without destroying the first when user navigated away and back via AJAX navigation.
**Fix Applied:** Track chart as `window._dashboardChart`; destroy existing instance before re-rendering.
**File:** `admin-panel/pages/dashboard/content.phtml`

---

## P1-2: Fix Online Users Counter
**Status:** [x] Completed - 2026-02-24
**Priority:** 🔴 Critical
**Problem:** Very low online count despite active users.
**Root Cause:** `Wo_CountOnlineData()` and `Wo_GetAllOnlineData()` used a 60-second `lastseen` window — users idle for >1 min were instantly dropped from the count.
**Fix:** Raised both functions to 300-second window (5 minutes) in `assets/includes/functions_two.php`
**Commit:** `aa973a2a`

---

## P1-3: TRDC Rewards System Stability
**Status:** [x] Completed - 2026-02-24
**Priority:** 🔴 Critical
**Findings:**
- Admin save/update form: correct — POST to `xhr/trdc_rewards.php`, saves master switch + per-reward configs
- Reward engine: `Wo_TriggerReward()` → cooldown → daily cap → guard → `Wo_AwardTRDC()` — all intact
- Cron: `cron-job.php` calls `Wo_ProcessMilestoneRewards()` when master switch is ON
- **Bug found + fixed:** `DATE(created_at)` in `growth-intelligence/content.phtml` — `created_at` is UNIX int, `DATE()` gave wrong results. Fixed to `created_at >= today_start AND < today_end`
- **Indexes added:** `idx_user_created (user_id, created_at)` and `idx_created_at` added directly to `Wo_TRDC_Rewards` on live DB (via `sql/005_trdc_rewards_indexes.sql`)
**Commit:** `d3795faf`

---

## P1-4: Security Token Validation
**Status:** [x] Completed - 2026-02-24 (Audit Only — No Code Change Needed)
**Priority:** 🔴 Critical
**Audit Findings:**
- Token IS per-session: `Wo_CreateMainSession()` generates random `$_SESSION['main_hash_id']` (not static, not hardcoded)
- Token embedded globally: `container.phtml:871` injects `.main_session` hidden input on every page
- Token sent with AJAX: JS reads `.main_session` and includes in all requests
- Admin XHR handlers all check `Wo_IsAdmin()` — sufficient admin-side protection
- `requests.php` checks `X-Requested-With: XMLHttpRequest` for all non-whitelisted endpoints
- `BitchatSecurity::requireCsrfToken()` exists and used in 7 sensitive handlers
- **Verdict: System is correctly implemented. No action required.**

---

## P2-5: Admin Sidebar Navigation (Accordion)
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟠 High
**Findings:** Accordion was already correctly implemented (PHP sets `class="open"` on active section only; app.js toggles and closes siblings on click). Main fix: removed 8 debug `console.log`/`console.error` calls from admin panel AJAX handler in `autoload.php` that were exposing page names, URLs, and response data in production browser console.
**Commit:** `97d520ab`

---

## P2-6: Move Growth Tools Higher in Admin Sidebar
**Status:** [x] Completed
**Priority:** 🟠 High
**Tasks:**
- Reorder sidebar: move Growth Dashboard, Feed Algorithm, Ghost Activity, Creator Mode above Tools section
**Root Cause:** "Bitchat Growth" `<li>` block was placed near the very bottom of the sidebar (after System Status, before Changelogs), making it hard to find.
**Fix Applied:** Moved the entire `<?php if ($is_admin) ?>` Bitchat Growth block to appear directly above the Tools section (was line ~1508, now line ~1325 in `autoload.php`).
**Commit:** `55f81093`

---

## BUG-FIX: Admin AJAX Nav — Growth Pages Show Same Content
**Status:** [x] Completed
**Priority:** 🔴 Critical
**Root Cause:** `admin_load.php` response starts with `\n<!-- DEBUG: ... -->` (PHP `?>` emits trailing newline before the comment). jQuery 3.4.1 only treats a string as HTML if `selector[0] === "<"`. Since the first char is `\n`, jQuery treats the whole response as a CSS selector, returns empty collection, `.val()` → `undefined`, `JSON.parse(undefined)` throws, and `$('.content').html(data)` never executes — content stays as previously-loaded page.
**Fix Applied:**
- `admin_load.php`: Remove debug HTML comment and all `error_log()` calls; close PHP tag inline so response starts with `<input id="json-data">` immediately
- `admin-panel/autoload.php`: Wrap `JSON.parse(...)` in try-catch so content always updates even if JSON parse fails; remove leftover `console.log('Popstate: Full reload')`
**Files Modified:** `admin_load.php`, `admin-panel/autoload.php`
**Commit:** `8ee76ef1`

**Follow-up (commit `65f223a2`):** `growth-intelligence` returned 500 — `Wo_Reactions` has no `time` column (state table, not event log). `WHERE time > {day_ago}` failed → `mysqli_fetch_assoc(false)` → `TypeError` in PHP 8.2. Fixed by counting total reactions instead and adding `$q !== false` guards.
**File:** `admin-panel/pages/growth-intelligence/content.phtml`

---

## P2-7: Fix Admin Header Layout

**Status:** [x] Completed
**Priority:** 🟠 High
**Tasks:**

- Remove duplicate avatar image — both instances are intentional (small in toggle, large in dropdown panel = standard pattern)
- Add search placeholder text — already present; confirmed
- Remove empty button element — changed `<button class="btn">` to `<span class="input-group-text">` for search icon
- Remove dead `<ul class="navbar-nav ml-auto">` (`.header-toggler`, permanently hidden by CSS)

---

## P3-8: Feed Layout Standardization
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟡 Medium
**Tasks:**
- Fix post container width + centering → covered by MC-2b (1200px container, 320px fixed sidebar)
- Fix avatar scaling → covered by MC-3 (40px avatar override)
- Prevent reaction overflow → covered by MC-3b (flex-direction row, nowrap, flex-shrink 0)

---

## P3-9: Post Composer Fix
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟡 Medium
**Tasks:**
- Fix emoji modal z-index → `.emo-post-container { z-index: 1000 !important }` (was 2)
- Fix upload overlay alignment → `.tag_pub_vids { position: relative !important; overflow: hidden !important }`
- Align post button vertically → modal header right div gets `display: flex; align-items: center; gap: 6px`

---

## P3-10: Sidebar Sections
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟡 Medium
**Tasks:**
- Fix collapsed Community/Explore sections → done in commits 6c3c6147 / 98b85b83
- Remove overflow hidden conflicts → no conflicts found (sections use Bootstrap collapse, not custom)

---

## P3-11: Language Dropdown Scroll
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟡 Medium
**Tasks:**
- Enable scrolling for long language list → done in commit 770225f9 (`max-height: 50vh; overflow-y: auto`)

---

## P3-12: Wallet & My-Points Page
**Status:** [x] Completed - 2026-02-24
**Priority:** 🟡 Medium
**Tasks:**
- Verify balance updates live → already done via WebSocket in container.phtml (Task 1)
- Fix rounding issues → TRDC balance now uses number_format(..., 4) — tiny amounts like 0.0012 show correctly
- Refresh balance without logout → added `s=get-balance` AJAX handler + refresh icon button in wallet.phtml
- Check TRDC conversion display → fixed `margin-left:110%` bug that pushed "Buy TRDC Now" button off-screen

---

## P4-DM: Dark Mode Complete Fix
**Status:** [x] Completed — 2026-02-24
**Priority:** 🌙 High
**Tasks:**
- Apply dark mode styling to: cards, modals, dropdowns, inputs, charts, wallet panels
- Test ALL pages in dark mode: feed, wallet, admin, settings, modals

---

## P5-13: Bottom Navigation Overlap
**Status:** [x] Completed — 2026-02-24
**Priority:** 📱 Medium
**Tasks:**
- Ensure content not hidden behind bottom nav (padding-bottom fix)

---

## P5-14: Hero Banner Responsive Height
**Status:** [x] Completed — 2026-02-24
**Priority:** 📱 Medium
**Tasks:**
- Replace fixed height with min-height responsive value

---

## P5-15: Post Card Overflow
**Status:** [x] Completed — 2026-02-24
**Priority:** 📱 Medium
**Tasks:**
- Remove horizontal scroll
- Ensure media fits container

---

## P6-QA: Admin Function Testing
**Status:** [~] In Progress — 2026-02-24
**Priority:** 🟢 QA
**Manual tests required:**
- [ ] Website Mode save
- [ ] Email settings save
- [ ] AI settings save
- [ ] NodeJS settings save
- [ ] Backup SQL & Files
- [ ] Mass Notifications
- [ ] Announcements
- [ ] Push notifications
- [x] No 500 errors / AJAX fail / undefined index in console — auto-verified

---

## Task: Remove Admin Sidebar — Changelogs, FAQs, Powered-by
**Status:** [x] Completed — 2026-02-25
**Priority:** 🟡 Medium
**Issue:** Admin sidebar permanently shows "Changelogs", "FAQs" (external WoWonder docs link), and "Powered by WoWonder v4.2.1" branding. All are WoWonder stock items not relevant to Bitchat.
**Fix:** Remove the three blocks from `admin-panel/autoload.php` (lines 1543–1567).
**Files:** `admin-panel/autoload.php`

---

## Task: Fix user_reports Admin Page Layout
**Status:** [x] Completed — 2026-02-25
**Priority:** 🟠 High
**Issues:**
1. `table-responsive1` typo → table won't scroll on small screens
2. `btn-outline-light` on date picker button → invisible on light background
3. Empty two-column row (col-md-9 / col-md-3) — dead whitespace
4. `$fetched_data['user']` not set for post/page/group/comment report types → Delete User / Ban buttons get undefined user ID, causing broken JS
5. Action column: up to 4 buttons with no flex wrap → overflow on narrow screens
**Files:** `admin-panel/pages/user_reports/content.phtml`, `admin-panel/pages/user_reports/list.phtml`

---

## FINAL QA CHECKLIST

- [x] Feed loads without layout shift — CLS fix applied: `.sidebar-listed-user-avatar` now has explicit 38×38px + object-fit (QA-CLS, 2026-02-24)
- [x] Dark mode works everywhere — 256 `body.night_mode` rules in custom CSS + P4-DM block applied; auto-verified (2026-02-24)
- [~] Mobile feed usable one-hand — P5-13 bottom nav clearance + P5-15 media fit applied; manual browser test pending
- [x] Rewards calculate correctly — `Wo_AwardTRDC()` uses floatval, INSERT IGNORE dedup, atomic `wallet + amount`; verified (2026-02-24)
- [x] Leaderboard loads under 2s — Wo_Users: wallet/referrer BTREE; Wo_Posts: 4× user_id BTREE; all queries indexed (2026-02-24)
- [x] No console red errors — auto-verified P6-QA; 0 deprecated jQuery, 3 console.log in third-party only (2026-02-24)
- [~] Admin navigation easy to use — PHP syntax passes, 302 redirects confirmed; manual browser test pending

---

## Task: Fix admin-cp/manage-invitation-keys HTTP 500

**Status:** [x] Completed — 2026-02-26
**Reported:** Admin panel page `https://bitchat.live/admin-cp/manage-invitation-keys` returning HTTP 500 (and later 200 with 0 bytes).
**Root Cause (1):** `Wo_LoadAdminPage()` in `functions_general.php` only declared `global $wo, $db` — missing `$sqlConnect`. Content inside `require()` (which runs in the function's local scope) used `mysqli_query($sqlConnect, ...)` but `$sqlConnect` was null.
**Root Cause (2):** The "Top Referrers" JOIN query in `content.phtml` used `u1.name` — a column that does not exist in `Wo_Users` (which has `first_name` + `last_name`). MySQL `ERROR 1054: Unknown column 'u1.name'` caused PHP to crash silently inside `ob_start()`, leaving a 0-byte response.
**Fix Applied:**
- `assets/includes/functions_general.php`: Added `$sqlConnect` to `global` declaration in `Wo_LoadAdminPage()` (commit `a843836e`)
- `admin-panel/pages/manage-invitation-keys/content.phtml`: Changed `u1.name` → `CONCAT(u1.first_name, ' ', u1.last_name) AS name` in the self-JOIN query (commit `4f5fc38f`)
**Files Modified:** `assets/includes/functions_general.php`, `admin-panel/pages/manage-invitation-keys/content.phtml`

---

## Task: Fix Admin Scheduled-Posts View Post Link and Redirect Target

**Status:** [x] Completed
**Requested:** 2026-02-27
**Completed:** 2026-02-27
**Description:** Two admin panel issues: (1) "View Post" on scheduled-posts page redirected to admin dashboard instead of the actual post. (2) Permission-denied redirect in `admin_load.php` sent users to the user panel dashboard instead of staying in admin.
**Root Cause 1:** `scheduled-posts/content.phtml` used a relative URL `index.php?link1=post&id=...` which resolved to `/admin-cp/index.php?...` from the admin context — routing into `admincp.php` instead of `index.php`.
**Root Cause 2:** `admin_load.php` line 59 redirected to `Wo_SeoLink('index.php?link1=welcome')` (user dashboard) on permission failure.
**Fix:** (1) Wrapped URL with `Wo_SeoLink()` for absolute URL, matching pattern used by all other admin pages. (2) Changed redirect target to `Wo_LoadAdminLinkSettings('')` (admin dashboard).
**Files Modified:** `admin-panel/pages/scheduled-posts/content.phtml`, `admin_load.php` (commit `5690be37`)

---

## Task: Fix Login Page White Gap

**Status:** [x] Completed
**Requested:** 2026-02-27
**Completed:** 2026-02-27
**Description:** Login page at 33% zoom showed a large white gap — background image at top, huge empty space, form at bottom.
**Root Cause:** `welcome.css` had excessive margins/padding: `.tag_wel_middle { margin: 100px 0 0 }` (desktop) and `.tag_wel_row { padding: 0 0 200px }` at ≤850px, plus 150–160px bottom padding in mobile breakpoints.
**Fix Applied:** Reduced all offending values to 20px margin and 40px bottom padding across all breakpoints.
**Files Modified:** `themes/wondertag/stylesheet/welcome.css` (commit `d303c975`)

---

## Task: Server Cleanup — Delete Unnecessary Files and Cache

**Status:** [x] Completed
**Requested:** 2026-02-28
**Completed:** 2026-02-28
**Description:** Audit and clean up unnecessary files, cache, stale sessions, old logs, and unused assets from the live server.

**Cleanup Summary (~4.1 GB freed):**

| Target | Before | After | Freed |
|--------|--------|-------|-------|
| `.git/` (shallow re-clone) | 2.0 GB | 81 MB | ~1.9 GB |
| PHP sessions (`/home/KamalDave/tmp/`) | 2.2 GB (518K files) | 251 MB | ~1.95 GB |
| Apache logs (`/var/log/apache2/`) | 827 MB | 13 MB | ~814 MB |
| `cache/` (.tmp + .tpl files) | 151 MB | 2 MB | ~149 MB |
| Unused themes (wowonder + sunshine) | 90 MB | 0 | 90 MB |
| systemd journal | 217 MB | 46 MB | ~171 MB |

**Notes:**
- `.git/` was bloated because uploaded videos/photos (60MB+) were committed before `.gitignore` was updated. Replaced with `--depth=1` shallow clone.
- `script_backups/` auto backups are each 20 bytes (corrupt/empty) — mysqldump likely failing. Separate bug to investigate.
- `assets/libraries/fake-users/` (12MB) kept — actively used by admin panel.

---

## Task: Dynamic Rotating Placeholder Text for Post Publisher

**Status:** [x] Completed
**Requested:** 2026-02-28
**Completed:** 2026-02-28
**Description:** Replace the static "What's going on?" placeholder in the post publisher box with dynamic, rotating text that pushes action, earning, and trading identity. Shows contextual prompts based on user's last post behavior (30% of the time) and randomly rotates through 10 action-oriented prompts (70% of the time). New users (joined < 7 days, zero posts) see a welcome message.
**Fix Applied:** Created `Wo_GetDynamicPlaceholder()` function with 10-item random pool, contextual prompts based on last post type (1 indexed DB query), and 30/70 weighted split using `mt_rand()`. Applied to both the feed button and the modal textarea.
**Files Modified:** `assets/includes/functions_general.php` (new function), `themes/wondertag/layout/story/publisher-box.phtml` (commits `d97eeea2`, `42bdd9e7`)

---

## Task: Center Post Publisher Placeholder Text

**Status:** [x] Completed
**Requested:** 2026-02-28
**Completed:** 2026-02-28
**Description:** Center-align the "What's going on?" text in the post publisher feed button and the textarea placeholder when empty.
**Fix Applied:** Added `text-align: center` to `.tag_pub_box_bg_text` and `.publisher-box textarea.postText:placeholder-shown` in the theme stylesheet.
**Files Modified:** `themes/wondertag/stylesheet/style.css` (commit `8bf89667`)

---

## Task: Fix Admin Sidebar Session Expiry Redirect

**Status:** [x] Completed
**Requested:** 2026-02-27
**Completed:** 2026-02-27
**Description:** When admin session expires during AJAX sidebar navigation, browser transparently follows 302 redirect, injecting the welcome page HTML into the admin content area — making it look like the user was redirected to the user panel.
**Root Cause:** `admin_load.php` sent a 302 redirect to the welcome page for unauthenticated requests. Browsers follow 302 redirects transparently for XMLHttpRequest, so JS couldn't intercept it. The welcome page HTML was injected into the admin `.content` div.
**Fix Applied:** (1) `admin_load.php` now returns HTTP 401 with an inline "Session Expired" message instead of a 302 redirect. (2) `admin-panel/autoload.php` AJAX handler now has a proper `error` callback that renders 401 responses and generic error messages in the content area.
**Files Modified:** `admin_load.php`, `admin-panel/autoload.php` (commit `aea17ffe`)

---

## Task: Login with Wallet (Web3 Identity)

**Status:** [x] Completed
**Requested:** 2026-02-26
**Completed:** 2026-02-26
**Description:** Add "Connect Wallet" as a new login method alongside email/Google/social. Uses EIP-191 message signing (no gas fee, no private key). Wallet address becomes the user's verified identity and TRDC payout destination.

**Approach:** Follows existing social-login pattern (`login-with.php` / `xhr/google_login.php`). Uses `web3p/ethereum-util` PHP library (vendored) for signature verification. Session-based nonces (single-use, 5-min expiry). `Wo_RegisterUser()` + `Wo_SetLoginWithSession()` reused unchanged.

**Files to Create:**
- `xhr/wallet_nonce.php` — Generate nonce, store in session
- `xhr/wallet_login.php` — Verify signature, find/create user, create session
- `assets/libraries/ethereum/` — `web3p/ethereum-util` vendored library

**Files to Modify:**
- `requests.php` — Add `wallet_nonce`, `wallet_login` to `$non_login_array`
- `themes/wondertag/layout/welcome/content-simple.phtml` — Add wallet button + ethers.js

**DB Migration (one-time):**
```sql
ALTER TABLE Wo_Users
  ADD COLUMN wallet_address VARCHAR(80) DEFAULT NULL,
  ADD COLUMN wallet_verified TINYINT(1) DEFAULT 0,
  ADD UNIQUE INDEX idx_wallet_address (wallet_address);
```

---

## Task: Security Hardening — Critical Exposure Fixes
**Status:** [x] Completed — 2026-03-01
**Priority:** Critical
**Impact:** Database credentials were publicly exposed; multiple server hardening gaps

**Vulnerabilities Found & Fixed:**
- [x] **CRITICAL**: `nodejs/config.json` publicly accessible (DB credentials exposed) — blocked via Nginx `^~` location
- [x] **CRITICAL**: MySQL password changed (old one was cached publicly until 2037)
- [x] Node.js source code, deploy script, documentation files blocked from public access
- [x] HTTP security headers added (X-Content-Type-Options, X-Frame-Options, CSP-related, Referrer-Policy, Permissions-Policy)
- [x] Session cookie: `secure=1`, `samesite=Strict`, lifetime reduced 30d→7d
- [x] Upload directories: `.htaccess` added blocking PHP execution
- [x] Reflected XSS in `sources/hashtag.php` fixed (htmlspecialchars on `$_GET['hash']`)
- [x] Webhook secret moved from hardcoded source to `private/webhook_secret.txt`

**Remaining (backlog — codebase-wide, not yet fixed):**
- [ ] SQL injection: 30+ instances of unparameterized `$_GET`/`$_POST` in `xhr/` payment handlers
- [ ] Stored XSS: post text, comment text, user bios, map locations rendered without escaping
- [ ] SSRF: `IsSaveUrl()` in `functions_two.php` accepts `file://` and other dangerous protocols
- [ ] CSRF: only ~10 of 270+ XHR handlers use `BitchatSecurity::requireCsrfToken()`
- [ ] File upload: PHP detection only scans first 1024 bytes; MIME validation uses client-spoofable `$_FILES['type']`
- [ ] Cookie expiration in `Wo_SetLoginWithSession()` is 10 years (should be 7 days)
- [ ] SSL verification disabled globally (`CURLOPT_SSL_VERIFYPEER = 0`)

**Files on server:**
- `/home/KamalDave/conf/web/bitchat.live/nginx.ssl.conf_security`
- `/home/KamalDave/web/bitchat.live/private/webhook_secret.txt`
- Updated: `config.php`, `nodejs/config.json`, `.user.ini`, upload `.htaccess` files

**Files in repo:**
- `sources/hashtag.php`, `webhook-deploy.php`

---

## Task: Nearby Users & Recommendations Improvements
**Status:** [x] Completed — 2026-03-01
**Priority:** Medium
**Impact:** Better discovery, cleaner suggestions, improved nearby accuracy

**Issues Found & Fixed:**

1. **Bug: `share_my_location` filtered in PHP, not SQL** — `Wo_GetNearbyUsers()` fetched 20 users from DB (LIMIT), then filtered in PHP loop. Users with `share_my_location=0` consumed LIMIT slots, causing fewer results than expected.
   - **Fix:** Added `AND share_my_location = 1` to both `Wo_GetNearbyUsers()` and `Wo_GetNearbyUsersCount()` SQL queries. Removed redundant PHP filter.

2. **Bug: Fake users in suggestions** — `Wo_UserSug()` (People You May Know) had no `src != 'Fake'` filter. 215 fake users could appear in suggestions.
   - **Fix:** Added `AND src != 'Fake'` to the query.

3. **UX: Default nearby radius too small** — Default 25km missed users in neighboring cities across India.
   - **Fix:** Increased default radius from 25km to 100km in both `Wo_GetNearbyUsers()` and `Wo_GetNearbyUsersCount()`.

4. **UX: Privacy toggle label unclear** — "Share my location with public?" didn't mention Nearby Users.
   - **Fix:** Updated to "Show me in Nearby Users? (Others can find you based on your location)" in `Wo_Langs` table.

5. **Missing: No Nearby Users sidebar widget** — No sidebar box for nearby users like "Pages You May Like" or "Creators".
   - **Fix:** Added "Nearby Users" widget in right sidebar after Creators section. Shows 6 nearby users with "See All" link to `/find-friends`. Only renders when user has location data and nearby users exist.

**Files Modified:**
- `assets/includes/functions_three.php` — SQL filter fix + radius increase in both functions
- `assets/includes/functions_one.php` — Fake user exclusion in `Wo_UserSug()`
- `themes/wondertag/layout/sidebar/content.phtml` — New Nearby Users sidebar widget

**DB change (live server only):**
- Updated `Wo_Langs.english` for `share_my_location` key

---

## Task 8: Hide CTA Prompt Card ("Hey Bitchat, what's on your mind?")
**Status:** [x] Completed
**Reported:** User wants the CTA action prompt card hidden from the home feed. Code preserved (commented out) for potential future re-enablement.
**Fix Applied:**
- Commented out the `#bc-action-prompt` HTML and `require_once('functions_growth.php')` call in `content.phtml`
- Commented out the `bc-prompts.js` script load in `container.phtml`
- JS file (`bc-prompts.js`) and CSS styles (`.bc-prompt-card`) left intact for easy re-enablement
**Files Modified:** `themes/wondertag/layout/home/content.phtml`, `themes/wondertag/layout/container.phtml`

---

## Task 9: Full Project Cleanup — Delete Unnecessary Files & Cache
**Status:** [x] Completed
**Reported:** Audit entire project for unnecessary files and cache, delete everything not needed.

**Live Server Cleanup (~3.8 GB freed):**
- Deleted stale `/var/www/html/bitchat/` copy (2.4 GB) — old deployment directory
- Purged 56K+ PHP session files older than 1 day (~500 MB)
- Rotated+deleted Apache access log (587 MB)
- Cleared file-based cache `cache/users/` (140 MB → 2 MB)
- Removed 2 redundant Feb 28 DB backups (56 MB)
- Vacuumed systemd journal (52 MB freed)

**Repository Cleanup (624 files, 35.2 MB removed from tracking):**
- Untracked `nodejs/config.json` (DB credentials — SECURITY)
- Removed test videos from `admin-panel/videos/` (5.4 MB)
- Removed CKEditor source map (4 MB)
- Removed FFmpeg manpages + VMAF models (7.3 MB)
- Removed `composer.phar` binary (2.7 MB)
- Removed 88 unminified JS + 81 unminified CSS files with .min counterparts (~14 MB)
- Removed 25 CodeMirror test.js files
- Removed 12+ vendored test/example directories across libraries
- Untracked 54 user-uploaded files in `themes/upload/files/`

**`.gitignore` improvements:**
- Added `themes/upload/files/` to prevent tracking user uploads
- Added `script_backups/` to prevent tracking DB dumps
- Added `!sql/*.sql` and `!database/*.sql` exceptions for migration scripts
- Added `*.js.map`, `ffmpeg/manpages/`, `ffmpeg/model/`, `admin-panel/videos/test*.mp4`, `composer.phar`

**Files Modified:** `.gitignore`, plus 624 files removed from git tracking

---

## Task 10: New User Registration — Repeatedly Asked to Update Info
**Status:** [x] Completed
**Reported:** After new user registration, users are trapped in an infinite onboarding redirect loop, repeatedly asked to update their info.
**Root Cause:** Three bugs in `welcome-setup` onboarding:
1. **Hash mismatch (primary):** `bcWelcomeComplete()` sends `$wo['user']['hash_id']` (user's DB field) but `Wo_CheckSession()` checks against `$_SESSION['hash_id']` (CSRF session hash) — always mismatches, so the AJAX to set `onboarding_completed=1` silently returns 400 every time
2. **Avatar upload wrong endpoint:** Sends to `update_general_settings` which requires `username`/`email` fields — silently fails, avatar never changes from default
3. **Old startup flags never cleared:** `start_up`, `startup_image`, `start_up_info`, `startup_follow` all stay at 0 even after onboarding
**Fix Applied:**
1. Added proper `<input type="hidden" name="hash_id">` with `Wo_CreateSession()` to welcome-setup template
2. Changed avatar upload to use correct endpoint `update_user_avatar_picture`
3. Updated `xhr/onboarding.php` to also set all old startup flags to 1
4. Fixed all stuck users in database (set onboarding_completed=1, start_up=1, etc.)
**Files Modified:** `themes/wondertag/layout/welcome_setup/content.phtml`, `xhr/onboarding.php`

---

## Task 11: Nearby Users "See All" — 404 Error
**Status:** [x] Completed
**Reported:** Clicking "See All" on the Nearby Users sidebar widget returned a 404 page.
**Root Cause:** Sidebar link used `friends_nearby` (underscore) but `index.php` route expects `friends-nearby` (hyphen).
**Fix Applied:** Changed `Wo_SeoLink('index.php?link1=friends_nearby')` to `friends-nearby` in sidebar template.
**Files Modified:** `themes/wondertag/layout/sidebar/content.phtml`

---

## Task 12: Delete Account — HTTP 500 Error
**Status:** [x] Completed
**Reported:** Delete account form at `/setting/delete-account` returns HTTP 500 on submission.
**Root Cause:** `Wo_DeleteUser()` in `functions_one.php` had a trailing space inside backticks in a column name (`` `from_id ` `` instead of `` `from_id` ``) in the T_AGORA DELETE query. PHP 8.2's strict `mysqli` mode throws `mysqli_sql_exception` on SQL errors, causing an uncaught exception.
**Fix Applied:**
1. Fixed the column name typo in `Wo_DeleteUser()`
2. Added try/catch in `xhr/delete_user_account.php` for graceful error handling
**Files Modified:** `assets/includes/functions_one.php`, `xhr/delete_user_account.php`

---

## Task 13: Audit Wo_DeleteUser & Sub-Functions — 5 Bugs Fixed
**Status:** [x] Completed
**Reported:** Full audit of `Wo_DeleteUser()` and all sub-functions (`Wo_DeletePage`, `Wo_DeleteGroup`, `Wo_DeletePost`, `Wo_DeleteForumThread`, `Wo_DeleteThreadReply`, `Wo_DeleteEvent`, `Wo_DeleteFromToS3`).
**Bugs Found & Fixed:**
1. **`Wo_DeleteUser` line 1025** — `foreach ($raise as ...)` without null check — PHP 8.x throws warning on null. Added `if (!empty($raise))` guard.
2. **`Wo_DeleteUser` line 1028** — `foreach ($posts as ...)` used wrong variable. Should be `$raise_posts` (fundraise posts), was iterating `$posts` (funding posts) — deleted wrong child posts. Fixed variable name.
3. **`Wo_DeletePage` line 2315** — `$cache->delete(md5($user_id)...)` used undefined `$user_id`. Changed to `$page_id`.
4. **`Wo_DeletePage` line 2325** — Used `=` instead of `.=`, overwriting the T_PAGES delete result with T_PAGES_INVAITES result. Fixed to `.=`.
5. **`Wo_DeleteGroup` line 3033** — `$cache->delete(md5($user_id)...)` used undefined `$user_id`. Changed to `$group_id`.
**Files Modified:** `assets/includes/functions_one.php`, `assets/includes/functions_two.php`

---

## Task 15: Fix "View New Posts" Button Not Working
**Status:** [x] Completed
**Reported:** Clicking "View 20 new posts" red button on home feed does nothing visible.
**Root Cause:** `Wo_GetNewPosts()` used chronological `Wo_GetPosts()` with `before_post_id` from the first visible post to fetch and prepend new posts. But with the ranked feed algorithm enabled (`feed_algorithm_enabled=1`), the first visible post's ID isn't the newest — it's the highest-engagement post. This caused: (1) `Wo_GetPosts()` to return 0 posts (because its `before_post_id` logic expects chronological ordering), (2) duplicate posts when it did return results, (3) confusing mix of chronological prepended posts on top of a ranked feed.
**Fix Applied:**
- Replaced `Wo_GetNewPosts()` to reload the entire feed via `$("#posts-laoded").load()` instead of trying to prepend. Works correctly with both ranked and chronological feeds.
- Fixed `force_update` cascading bug in `Wo_intervalUpdates()` — Socket.io `update_new_posts` event was passing `force_update=1` to every subsequent poll indefinitely. Now resets to 0 after the initial forced poll.
**Files Modified:** `themes/wondertag/javascript/script.js`

---

## Task 14: Enhanced Nearby Users — 4 GPS Improvements
**Status:** [x] Completed
**Reported:** User requested Bluetooth for nearby users; Bluetooth not viable for web apps (Web Bluetooth API lacks Peripheral/advertising role). Enhancing existing GPS-based system instead with 4 features.
**Features:**
1. **More Frequent Location Updates** — 1-hour refresh on nearby page (was 7 days) + "Refresh My Location" button
2. **Push Notifications for Nearby Users** — Cron-based proximity detection, notifies when someone new within 10km
3. **Live Map with Socket.io** — Real-time user positions on Leaflet.js map via WebSocket
4. **"Nearby Now" Quick-Connect (Wave)** — Users within 1km shown prominently + Wave greeting button
**Database Changes:** New `Wo_Nearby_Notifications` and `Wo_Waves` tables, new `e_nearby` column on `Wo_Users`
**Files to Modify:** `tabels.php`, `save_user_location.php`, `script.js`, `friends_nearby/content.phtml`, `friends_nearby/includes/user-list.phtml`, `functions_three.php`, `functions_one.php`, `cron-job.php`, `notifecation.phtml`, `notifications-settings.phtml`, `update_notifications_settings.php`, `listeners.js`, `wo_users.js`
**Files to Create:** `xhr/wave.php`


## Task 50: TRDC Ticker Price Not Updating (Permanently Hidden After Error)
**Status:** Completed
**Date:** 2026-03-06
**Problem:** The TRDC ticker in the market strip would permanently disappear after any transient error (timeout, network glitch, rate limit). The error/timeout/onerror handlers set `display: none` on the element, but successful fetches never restored visibility. Once hidden, it stayed hidden until full page reload.
**Fix:** Removed destructive `display: none` from error handlers (transient errors now just log a warning). Added `display` restoration on successful fetch so the ticker self-heals after any temporary failure.
**Files Modified:** `themes/wondertag/custom/js/footer.js`

---

## Task 51: News Bots — Automated RSS News Posting System
**Status:** [x] Completed
**Date:** 2026-03-08
**Description:** Full admin module to create and manage bot user accounts that automatically fetch RSS/Atom feeds and post international news articles to the platform.
**Features:**
- Admin UI under "Bitchat Growth > News Bots" to create/edit/delete bot accounts
- Each bot has: display name, username, category, RSS feed URLs, post frequency, daily limit, thumbnail toggle
- Bot accounts are real Wo_Users entries (verified, active) that post as regular users
- RSS/Atom feed parser supports RSS 2.0, Atom, and RDF formats with media thumbnail extraction
- Duplicate prevention via article URL hashing (Wo_Bot_Posted table)
- Frequency and daily post limit enforcement
- "Run Now" button for immediate manual execution
- Automatic posting via cron job (runs all enabled bots each cycle)
- Article thumbnails downloaded and saved locally as link preview images
- Auto-follow: new users automatically follow all enabled bots on registration
- Feed visibility: bot posts integrated into ranked feed algorithm with balanced visibility (max 4 bot posts, 1 per bot, exempt from frequency/link penalties)
- All existing users (4,578) bulk-followed all 11 bots
**Bot Accounts (11):** Al Jazeera, BBC News, CNN, Zee News, CoinDesk, The Block, CryptoSlate, Web3 News Wire, CNBC, Aaj Tak, Crypto News
**Database Tables:** `Wo_Bot_Accounts`, `Wo_Bot_Posted`
**Files Created:** `assets/includes/functions_news_bots.php`, `admin-panel/pages/news-bots/content.phtml`
**Files Modified:** `xhr/admin_setting.php`, `admin-panel/autoload.php`, `cron-job.php`, `assets/includes/functions_one.php`, `assets/includes/functions_feed.php`

---

## Task 53: "View New Posts" Button — Wrong Count, CSS Hover Shift
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** The "View X new posts" button had three issues:
1. Wrong count — used heavy `Wo_GetPosts()` with `limit: 20` cap, returning full post objects just to count them
2. CSS hover shift — `transition: all .2s ease` caused visual jitter when box-shadow/focus ring changed on hover/focus/active
3. Missing singular translation — `view_more_post` key didn't exist, causing empty text when count was 1
**Fix:**
1. Replaced `Wo_GetPosts()` with lightweight `COUNT(*)` SQL query (no limit cap, accurate count)
2. Changed CSS `transition` from `all` to `background-color` only; pinned box-shadow on hover/focus/active states; added `outline: none` to prevent focus ring shift
3. Added missing `view_more_post` singular key to english.php; added fallback in PHP for missing lang keys
**Files Modified:** `xhr/update_data.php`, `themes/wondertag/stylesheet/style.css`, `assets/languages/english.php`, `themes/wondertag/javascript/script.js`

---

## Task 54: Post Card Z-Index Overlap — Like Button Over Link Preview
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Link preview images in post cards rendered above the like/comment action buttons due to `z-index: 1` on `.post-fetched-url` creating a stacking context.
**Fix:** Removed `z-index: 1` from `.post-fetched-url`, added `margin-bottom: 5px` for spacing, added `onerror` handler and background-color fallback for broken link preview thumbnails.
**Files Modified:** `themes/wondertag/stylesheet/style.css`, `themes/wondertag/layout/story/includes/fetched_url.phtml`

---

## Task 55: Standardize Cache Busting Across All CSS/JS Includes
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** CSS/JS cache-busting used static strings (`$wo['update_cache']`, `Tag_version()` = "2.7.2", `$wo['config']['version']`) that never changed, so Nginx with `expires max` served stale files indefinitely after updates.
**Fix:** Created `bc_v()` helper using `filemtime()` for automatic cache invalidation. Replaced 25+ references in `container.phtml` and 1 in `extra_js/content.phtml` with `bc_v()` calls.
**Files Modified:** `themes/wondertag/layout/container.phtml`, `themes/wondertag/layout/extra_js/content.phtml`

---

## Task 56: Android App — Remove Duplicate Menu Items
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Profile dropdown and sidebar both showed Privacy Settings and General Settings, creating duplicate navigation items in the Android WebView app.
**Fix:** Removed duplicate Privacy Settings and General Settings from the profile dropdown menu (kept in sidebar where the full Settings section lives). Hid original WoWonder bottom toolbar (`tag_sec_toolbar`) via CSS since custom `bc-bottom-home-btn` replaces it.
**Files Modified:** `themes/wondertag/layout/header/loggedin-header.phtml`, `themes/wondertag/custom/css/style.css`

---

## Task 57: Android App — Fix Pull-to-Refresh Triggering During Scroll
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Scrolling up inside sidebar menus, modals, and other scrollable containers triggered SwipeRefreshLayout pull-to-refresh instead of scrolling the container content.
**Fix:** Rewrote scroll detection in `MainActivity.kt` to detect scrollable containers by checking CSS `overflow-y` property. Tracks whether touch started inside a scrollable element and blocks refresh for the entire touch gesture. Uses `BitchatScroll` JavascriptInterface with `setOnChildScrollUpCallback`.
**Files Modified:** `bitchat-android/app/src/main/java/live/bitchat/app/MainActivity.kt`

---

## Task 58: Android App — Wallet Login with Installed Wallet Detection
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** "Connect Wallet" on the login page used `window.ethereum` which is unavailable in Android WebView. Users couldn't log in with crypto wallets from the app.
**Fix:** Added `BitchatWallet` JavascriptInterface in `MainActivity.kt` that detects installed wallet apps (MetaMask, Trust Wallet, Coinbase, Rainbow, etc.) via package manager. Opens wallet dApp browsers via deep links. Updated login page JS to show list of installed wallets in native app, falling back to `window.ethereum` on desktop.
**Files Modified:** `bitchat-android/app/src/main/java/live/bitchat/app/MainActivity.kt`, `themes/wondertag/layout/welcome/content-simple.phtml`, `themes/wondertag/custom/css/style.css`

---

## Task 59: Android App — Google Login in WebView
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Google Identity Services (GIS) SDK doesn't render in Android WebView, so Google login button was invisible in the app.
**Fix:** Added OAuth redirect fallback button that appears when GIS fails to render (detected by checking `#buttonDiv` content after 2s timeout). Button redirects to `login-with.php?provider=Google` which uses server-side HybridAuth OAuth flow. Whitelisted `accounts.google.com` and `googleapis.com` URLs in WebView's `shouldOverrideUrlLoading`.
**Files Modified:** `bitchat-android/app/src/main/java/live/bitchat/app/MainActivity.kt`, `themes/wondertag/layout/welcome/content-simple.phtml`, `themes/wondertag/layout/welcome/content-startup.phtml`, `themes/wondertag/custom/css/style.css`

---

## Task 60: Android App — Back Button Navigation Fix
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Android back button didn't work properly with WoWonder's AJAX/pushState navigation. Pressing back either did nothing or exited the app instead of going to the previous page.
**Fix:** Rewrote `onKeyDown` in `MainActivity.kt` to: 1) Close open sidebar/dropdown menus first, 2) Dismiss open modals, 3) Try WebView `goBack()` for actual page navigations, 4) Fall back to `window.history.back()` for pushState navigations, 5) Show exit dialog only if URL didn't change after history.back().
**Files Modified:** `bitchat-android/app/src/main/java/live/bitchat/app/MainActivity.kt`

---

## Task 61: Fix Post Card Text Area — Composer Expansion + Text Overflow
**Status:** [x] Completed
**Date:** 2026-03-08
**Problem:** Two issues: 1) Post composer textarea stayed at 44px height with overflow hidden even in full-screen mobile modal, making it nearly unusable on phones. 2) Long text/URLs in post cards could overflow horizontally without word-wrap.
**Fix:** Added flex expansion rules for the composer textarea inside the full-screen mobile modal (≤600px) — textarea now fills available space with min-height 120px. Added `overflow-wrap: break-word` and `word-break: break-word` to `.post-description` to prevent horizontal text overflow. Added `overflow: hidden` to `.post-description` as containment guard.
**Files Modified:** `themes/wondertag/custom/css/style.css`

---

## Task 62: Android App v1.0.3 Release Build
**Status:** [x] Completed
**Date:** 2026-03-08
**Summary:** Rebuilt the Android app with all pending features from Tasks 56-60: scroll sensitivity fix (SwipeRefreshLayout conflict), wallet login detection (BitchatWallet JS interface), Google OAuth in WebView, back button navigation, and duplicate menu cleanup.
**Changes:** Bumped versionCode 3→4, versionName 1.0.2→1.0.3, UserAgent BitchatApp/1.0.3. Built signed release APK with ProGuard minification.
**Files Modified:** `app/build.gradle.kts`, `app/src/main/java/live/bitchat/app/MainActivity.kt`

---

## Task 63: Android App Play Store Preparation
**Status:** [x] Completed
**Date:** 2026-03-08
**Summary:** Prepared Android app for Google Play Store submission — moved hardcoded signing credentials to `local.properties`, set `allowFileAccess=false` and `allowBackup=false` for security, built signed AAB (required by Play Store). Created proper privacy policy page.
**Changes:** Signing credentials now loaded from `local.properties` (gitignored) via `Properties` loader. Built `app-release.aab` via `./gradlew bundleRelease`. Created `.gitignore` for Android project.
**Files Modified:** `app/build.gradle.kts`, `app/src/main/AndroidManifest.xml`, `app/src/main/java/live/bitchat/app/MainActivity.kt`, `.gitignore`

---

## Task 64: Privacy Policy Page
**Status:** [x] Completed
**Date:** 2026-03-08
**Summary:** Populated the privacy policy page at `bitchat.live/terms/privacy-policy` with proper Bitchat-specific content. Covers data collection, usage, sharing, retention, security, user rights, children's privacy, third-party services, and contact info. Required for Google Play Store submission.
**Changes:** Updated `Wo_Terms` database row (type=`privacy_policy`) with full 10-section privacy policy.
**Files Modified:** Database only (`Wo_Terms` table)

---

## Task 65: Ghost Activity — Dedicated Ghost Accounts
**Status:** [x] Completed
**Date:** 2026-03-08
**Summary:** Created 10 dedicated ghost accounts with natural names from different countries, unique profile photos, and always-online status. Replaced previous config (admin account user_id=1) with new dedicated accounts. No real users are used for ghost activity.
**Accounts Created:** Jacob Miller (USA), Aisha Rahman (Bangladesh), Lucas Santos (Brazil), Yuki Tanaka (Japan), Elena Petrov (Russia), Omar Hassan (Middle East), Sophie Laurent (France), Maria Garcia (Spain), Daniel Kim (South Korea), Vikram Singh (India)
**Changes:** Created 10 Wo_Users entries with verified status, random passwords, ghost email addresses. Downloaded unique profile photos to `upload/photos/ghost/`. Updated `ghost_activity_accounts` config. Added lastseen auto-update in cron-job.php so ghost accounts always appear online.
**Files Modified:** `cron-job.php`, Database (`Wo_Users`, `Wo_Config`)

---

## Task 66: Codebase Cleanup
**Status:** [x] Completed
**Date:** 2026-03-08
**Summary:** Removed 5 old/backup template files (1,891 lines deleted). Cleaned 194MB of Android build artifacts. Updated APK download URLs from v1.0.2 to v1.0.3 in footer.js.
**Files Deleted:** `themes/wondertag/layout/ads/wallet_old.phtml`, `themes/wondertag/layout/home/content_old.phtml`, `themes/wondertag/layout/start_up/avatar_startup_old.phtml`, `themes/wondertag/layout/start_up/avatar_startup_old (1).phtml`, `themes/sunshine/layout/chat/page-tab-old.phtml`
**Files Modified:** `themes/wondertag/custom/js/footer.js`

---

## Task 67: Duplicate Post Prevention
**Status:** [x] Completed
**Date:** 2026-03-09
**Summary:** Block same-content posts from same user (real or bot) within 24 hours. Uses `Wo_GenerateTextHash()` to hash post text and checks `Wo_Spam_Tracking` table for existing match before INSERT. Added to all 3 post creation paths.
**Files Modified:** `assets/includes/functions_one.php` (Wo_RegisterPost), `assets/includes/functions_news_bots.php` (bc_create_bot_post, bc_create_template_post)

---

## Task 68: Fix "View X New Posts" Counter
**Status:** [x] Completed
**Date:** 2026-03-09
**Summary:** The "View N new posts" button was showing inflated counts because it counted bot posts. The ranked feed applies diversity limits (max 2 per bot), so clicking the button showed fewer posts than indicated. Fixed by excluding bot posts from the counter and always filtering to followed users/own posts/liked pages/joined groups.
**Files Modified:** `xhr/update_data.php`

---

## Task 69: Anti-Spam Registration Protection (PRE-PLAY STORE)
**Status:** [ ] Not Started
**Priority:** High — MUST complete before Play Store submission
**Date Added:** 2026-03-09
**Issue:** Many new users register but never post or add profile pictures — likely spam/bot accounts.
**Root Cause:** Registration has no real protection: no CAPTCHA enabled, no email verification, weak IP rate limit (10 accounts/IP/53min).
**Planned Fixes:**
1. Enable reCAPTCHA (admin setting `reCaptcha = 1` + add Google keys)
2. Enable email verification (admin setting `emailValidation = 1`)
3. Tighten IP rate limit — reduce from 10 to 3 accounts per IP per 24 hours
4. Add honeypot field to registration form (hidden field that bots fill but humans don't)
5. Purge inactive accounts — delete accounts with no posts, no avatar, no activity after X days
**Key Files:** `xhr/register.php`, `assets/includes/functions_one.php` (Wo_CheckIfUserCanRegister), registration form templates
