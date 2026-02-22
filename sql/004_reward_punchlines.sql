-- ============================================================
-- Bitchat TRDC Reward Engine — SQL Migration 004
-- Adds punchline column for toast/banner notifications
-- ============================================================

ALTER TABLE `Wo_Rewards_Config` ADD COLUMN `punchline` VARCHAR(255) DEFAULT '' AFTER `description`;

-- Seed punchlines for all 19 reward types
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Post your thoughts. Earn real tokens.' WHERE `reward_key` = 'post_create';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your opinion has value.' WHERE `reward_key` = 'comment_create';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Show up daily. Watch rewards grow.' WHERE `reward_key` = 'daily_login';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your content is loved. Get rewarded for it.' WHERE `reward_key` = 'like_received';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Spread the word. Get paid for it.' WHERE `reward_key` = 'post_share';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your first post just earned you TRDC. Welcome aboard.' WHERE `reward_key` = 'first_post';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your invite worked. You both win.' WHERE `reward_key` = 'referral_signup';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your post hit 100 reactions. Here is your bonus.' WHERE `reward_key` = 'milestone_100';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'You are going viral. 500 reactions unlocked.' WHERE `reward_key` = 'milestone_500';
UPDATE `Wo_Rewards_Config` SET `punchline` = '1,000 reactions. You are officially a Bitchat legend.' WHERE `reward_key` = 'milestone_1000';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Your first video is live. Bonus unlocked.' WHERE `reward_key` = 'first_video';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Verified and rewarded. Your email just earned you TRDC.' WHERE `reward_key` = 'verify_email';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Profile complete. Your reward is ready.' WHERE `reward_key` = 'complete_profile';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Memories uploaded. Album bonus earned.' WHERE `reward_key` = 'first_album';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Published your first article. TRDC credited.' WHERE `reward_key` = 'first_article';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Event created. Bonus unlocked for going live.' WHERE `reward_key` = 'first_event';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Funding started. Earn while you build.' WHERE `reward_key` = 'first_funding';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Group created. Community builder bonus earned.' WHERE `reward_key` = 'first_group';
UPDATE `Wo_Rewards_Config` SET `punchline` = 'Page launched. Your brand just got rewarded.' WHERE `reward_key` = 'first_page';
