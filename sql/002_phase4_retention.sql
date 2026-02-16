-- Phase 4: Retention & Discovery SQL Schema Changes
-- Post View Tracking & Welcome Flow

-- Add post_views column to track impressions
ALTER TABLE Wo_Posts
ADD COLUMN post_views INT UNSIGNED NOT NULL DEFAULT 0
AFTER postShare;

-- Add index for better performance when querying top viewed posts
CREATE INDEX idx_post_views ON Wo_Posts(post_views DESC);

-- Add onboarding_completed column for new user welcome flow
ALTER TABLE Wo_Users
ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0
AFTER verified;

-- Add index for faster onboarding query
CREATE INDEX idx_onboarding_completed ON Wo_Users(onboarding_completed);
