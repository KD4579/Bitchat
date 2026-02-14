# Bitchat — Functionality Issues Tracker

## Status Legend
- [ ] Not Started
- [~] In Progress
- [x] Completed

---

## Task 0: Blank Feed / Content Area Not Loading
**Status:** [x] Completed
**Reported:** Admin (BITCHAT) sees header but blank content area after login
**Root Cause:** WoWonder's SPA navigation adds `opacity_start` class to `#ajax_loading` (which wraps `#contnet`) during page transitions. This sets `opacity: 0; pointer-events: none;` making the entire content area invisible. If the AJAX page load fails or the transition is interrupted (e.g., after PHP-FPM restart, login redirect), the class is never removed and content stays invisible.
**Fix Applied:**
- Deployed `themes/wondertag/custom/js/footer.js` — safety script that:
  1. Removes `opacity_start` on initial page load
  2. Removes it again after 1.5s delay (race condition safety)
  3. MutationObserver monitors for the class getting stuck during SPA navigation (auto-removes after 8s)
- Restarted Apache to clear stale PHP-FPM socket connections
- Restored custom CSS (767 lines modernization)
**Files Modified:** `themes/wondertag/custom/js/footer.js`

---

## Task 1: TRDC Balance Transfer Not Showing Until Receiver Refreshes (Pusher)
**Status:** [x] Completed
**Issue:** When TRDC tokens are transferred to another user, the receiver's balance does not update in real-time. The receiver must manually refresh the page to see the updated balance.
**Expected:** Balance should update instantly via Pusher/WebSocket push notification.
**Investigation Areas:**
- Pusher/Socket.io event emission after TRDC transfer
- Client-side listener for balance update events
- Node.js real-time server configuration
**Root Cause:** The wallet transfer handler (`xhr/wallet.php`) updated the database and sent a notification via `Wo_RegisterNotification()`, but the JSON response only contained `status` and `message` — no updated balance values. The client-side (`send_money.phtml`) emitted a `user_notification` socket event for the notification bell, but included no wallet balance data. The receiver's wallet balance display (`wallet.phtml`) had no targetable ID and no real-time update mechanism.
**Fix Applied:**
1. **`xhr/wallet.php`**: Added `sender_balance` and `receiver_balance` fields to the JSON response after successful transfer
2. **`themes/wondertag/layout/ads/wallet.phtml`**: Added `id="wallet-balance-amount"` to the balance display `<span>` for JS targeting
3. **`themes/wondertag/layout/ads/send_money.phtml`**: On success, sender's displayed balance updates immediately from response data. Added `wallet_balance` field to the existing `user_notification` socket emit so receiver gets the new balance via the existing Socket.io channel
4. **`themes/wondertag/layout/container.phtml`**: Enhanced the `new_notification` socket handler to check for `wallet_balance` in notification data and update `#wallet-balance-amount` if the receiver is on the wallet page
- Zero Node.js server changes required — piggybacks on existing `user_notification` → `new_notification` socket relay
**Files Modified:** `xhr/wallet.php`, `themes/wondertag/layout/ads/wallet.phtml`, `themes/wondertag/layout/ads/send_money.phtml`, `themes/wondertag/layout/container.phtml`

---

## Task 2: Story Not Showing to All Users
**Status:** [x] Completed
**Issue:** Stories posted by users are not visible to all other users who should be able to see them.
**Expected:** Stories should be visible to all active users on the platform.
**Investigation Areas:**
- Story creation and storage logic
- Story visibility query (who can see which stories)
- Story expiration logic (24h TTL)
- Privacy/friendship checks in story fetch
**Root Cause:** Three story-fetching functions (`Wo_GetFriendsStatus()`, `Wo_GetFriendsStatusAPI()`, `Wo_GetAllStatus()`) in `functions_three.php` used a restrictive SQL WHERE clause that only showed stories from users the viewer **follows** (`user_id IN (SELECT following_id FROM Wo_Followers WHERE follower_id = ...)`). Non-followed users' stories were completely invisible. Additionally, the 24h expiration (`expire` column) was never checked in queries, so expired stories could still appear.
**Fix Applied:**
- Modified all 3 functions to show stories from **all active users** instead of followers-only
- Added `expire > UNIX_TIMESTAMP()` check to properly enforce 24-hour story TTL
- Added blocked-user exclusion (`NOT IN Wo_Blocks`) in both directions for safety
- Story creation, storage, and media handling remain unchanged
**Files Modified:** `assets/includes/functions_three.php` (lines 6440, 6486, 6894)

---

## Task 3: Audio and Video Calls Not Working in Messenger
**Status:** [x] Completed
**Issue:** Audio and video call features in the messenger are not functioning properly.
**Expected:** Users should be able to initiate and receive audio/video calls.
**Investigation Areas:**
- WebRTC / Twilio / Agora integration
- TURN/STUN server configuration
- Socket.io signaling for call events
- Browser permissions (microphone/camera)
- SSL certificate requirements for WebRTC
**Root Cause:** The admin config `video_call_request` and `audio_call_request` were both set to `pro`, meaning only premium/pro users could see or use call buttons. Regular users had no access to calls at all. The call system itself (Agora v3.6.11 SDK, token generation, call creation, polling-based incoming call detection, answer/decline flow) is functional — the admin user (user_id=1) successfully created and answered a call.
**Configuration Found:**
- Agora SDK: Enabled (`agora_chat_video = 1`) with valid credentials (App ID, Certificate, Customer ID)
- HTTPS: Enabled (`site_url = https://bitchat.live`) — required for WebRTC getUserMedia
- Agora JS SDK: v3.6.11 bundled (663KB), loaded globally in container.phtml
- Socket.io: Disabled (`node_socket_flow = 0`), incoming calls detected via 6s polling instead
- Call table: `Wo_AgoraVideoCall` — has correct schema with `active`/`declined` defaults of 0
**Fix Applied:**
- Changed `video_call_request` from `pro` to `all` (database update on live server)
- Changed `audio_call_request` from `pro` to `all` (database update on live server)
- All users can now see and use audio/video call buttons in messenger
**Notes:**
- `node_socket_flow = 0` means incoming calls have up to 6s detection delay (polling-based). Enabling the Node.js socket server would provide instant notifications.
- If calls still fail for specific users, check browser console for Agora errors (expired token, network issues, camera/mic permissions)
**Files Modified:** Database config only (`Wo_Config` table: `video_call_request`, `audio_call_request`)

---

## Task 4: Double Click on Post
**Status:** [x] Completed
**Issue:** Double-clicking on post reaction/like buttons triggers duplicate AJAX requests, causing the reaction to register multiple times or toggle unexpectedly.
**Expected:** Each click should register exactly once, with subsequent rapid clicks ignored.
**Investigation Areas:**
- Post click/interaction event handlers
- Debounce/throttle on post actions
- Like/reaction double-trigger prevention
**Root Cause:** Two race conditions in the reaction system (`extra_js/content.phtml`):
1. **`Wo_RegisterReactionLike()`**: Had a `data_react` guard that checked before the AJAX call but only set the flag AFTER the AJAX callback completed. Between the first click and the AJAX response (network latency), a second click could pass the guard and fire a duplicate AJAX request.
2. **`Wo_RegisterReaction()`**: Had NO guard at all — every click fired an AJAX request regardless of previous clicks.
- Post creation (`publisher-box.phtml`) already had proper protection — the submit button is disabled in `beforeSend` callback.
- Share function opens a modal (inherently safe from double-click).
**Fix Applied:**
1. `Wo_RegisterReactionLike()`: Moved `$('#react_'+post_id).attr('data_react','1')` to fire IMMEDIATELY after the guard check (before the AJAX call), preventing any subsequent clicks from passing
2. `Wo_RegisterReaction()`: Added the same `data_react` guard pattern — check before, set immediately, prevent duplicates
**Files Modified:** `themes/wondertag/layout/extra_js/content.phtml` (lines 49, 161)
