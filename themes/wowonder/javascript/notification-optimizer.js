/**
 * Intelligent Notification Polling with Exponential Backoff
 * Reduces server load by dynamically adjusting polling frequency
 *
 * Features:
 * - Starts at 10 seconds (vs original 5 seconds)
 * - Increases to 30 seconds when idle
 * - Returns to 10 seconds when user is active
 * - Pauses when tab is hidden (Page Visibility API)
 * - Immediately checks when tab becomes visible
 */

(function() {
    'use strict';

    // Configuration
    const POLLING_CONFIG = {
        MIN_INTERVAL: 10000,      // 10 seconds when active
        MAX_INTERVAL: 30000,      // 30 seconds when idle
        IDLE_THRESHOLD: 60000,    // 1 minute of no activity = idle
        BACKOFF_MULTIPLIER: 1.5,  // Gradual increase
    };

    let currentInterval = POLLING_CONFIG.MIN_INTERVAL;
    let lastActivity = Date.now();
    let lastNotificationCount = 0;
    let pollingTimer = null;
    let isTabVisible = true;

    /**
     * Enhanced interval updates with adaptive polling
     */
    function Wo_SmartIntervalUpdates(force_update = 0) {
        // Don't poll if tab is hidden
        if (!isTabVisible && force_update === 0) {
            console.log('[NotificationOptimizer] Tab hidden, skipping poll');
            return;
        }

        // Call original update function
        if (typeof window.Wo_intervalUpdates === 'function') {
            window.Wo_intervalUpdates(force_update, 1); // Pass loop=1 to prevent original timer
        }

        // Adjust interval based on activity
        const timeSinceActivity = Date.now() - lastActivity;
        const isIdle = timeSinceActivity > POLLING_CONFIG.IDLE_THRESHOLD;

        if (isIdle && currentInterval < POLLING_CONFIG.MAX_INTERVAL) {
            // Gradually increase interval when idle
            currentInterval = Math.min(
                currentInterval * POLLING_CONFIG.BACKOFF_MULTIPLIER,
                POLLING_CONFIG.MAX_INTERVAL
            );
            console.log(`[NotificationOptimizer] Idle detected, interval increased to ${currentInterval/1000}s`);
        } else if (!isIdle && currentInterval > POLLING_CONFIG.MIN_INTERVAL) {
            // Reset to min interval when active
            currentInterval = POLLING_CONFIG.MIN_INTERVAL;
            console.log(`[NotificationOptimizer] Activity detected, interval reset to ${currentInterval/1000}s`);
        }

        // Schedule next poll
        clearTimeout(pollingTimer);
        pollingTimer = setTimeout(function() {
            Wo_SmartIntervalUpdates(0);
        }, currentInterval);
    }

    /**
     * Track user activity to optimize polling
     */
    function trackUserActivity() {
        lastActivity = Date.now();

        // If we're in slow poll mode, immediately check for updates
        if (currentInterval > POLLING_CONFIG.MIN_INTERVAL) {
            currentInterval = POLLING_CONFIG.MIN_INTERVAL;
            clearTimeout(pollingTimer);
            Wo_SmartIntervalUpdates(0);
        }
    }

    /**
     * Handle tab visibility changes
     */
    function handleVisibilityChange() {
        if (document.hidden) {
            isTabVisible = false;
            clearTimeout(pollingTimer);
            console.log('[NotificationOptimizer] Tab hidden, polling paused');
        } else {
            isTabVisible = true;
            console.log('[NotificationOptimizer] Tab visible, checking immediately');
            // Immediately check when tab becomes visible
            Wo_SmartIntervalUpdates(1); // force_update=1
        }
    }

    /**
     * Initialize optimizer
     */
    function init() {
        // Override original Wo_intervalUpdates if it exists
        if (typeof window.Wo_intervalUpdates === 'function') {
            // Store original function
            window.Wo_intervalUpdates_original = window.Wo_intervalUpdates;

            // Clear any existing interval timers from original implementation
            if (typeof window.intervalUpdates !== 'undefined') {
                clearTimeout(window.intervalUpdates);
            }

            console.log('[NotificationOptimizer] Initialized - Polling every ' + (currentInterval/1000) + 's');

            // Start smart polling
            Wo_SmartIntervalUpdates(0);

            // Track user activity
            const activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart'];
            let activityThrottle;

            activityEvents.forEach(function(event) {
                document.addEventListener(event, function() {
                    // Throttle activity tracking to once per second
                    if (!activityThrottle) {
                        trackUserActivity();
                        activityThrottle = setTimeout(function() {
                            activityThrottle = null;
                        }, 1000);
                    }
                }, { passive: true });
            });

            // Handle tab visibility
            document.addEventListener('visibilitychange', handleVisibilityChange);

            // Handle window focus/blur
            window.addEventListener('focus', function() {
                if (isTabVisible) {
                    Wo_SmartIntervalUpdates(1);
                }
            });

            console.log('[NotificationOptimizer] Activity tracking enabled');
        } else {
            console.warn('[NotificationOptimizer] Wo_intervalUpdates function not found');
        }
    }

    /**
     * Public API
     */
    window.NotificationOptimizer = {
        getCurrentInterval: function() {
            return currentInterval;
        },
        isTabVisible: function() {
            return isTabVisible;
        },
        forceUpdate: function() {
            Wo_SmartIntervalUpdates(1);
        },
        getStats: function() {
            return {
                currentInterval: currentInterval / 1000 + 's',
                lastActivity: new Date(lastActivity).toLocaleTimeString(),
                isIdle: (Date.now() - lastActivity) > POLLING_CONFIG.IDLE_THRESHOLD,
                tabVisible: isTabVisible
            };
        }
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

/**
 * Usage in browser console for debugging:
 *
 * NotificationOptimizer.getStats()
 * // Returns current polling state
 *
 * NotificationOptimizer.forceUpdate()
 * // Immediately check for notifications
 *
 * NotificationOptimizer.getCurrentInterval()
 * // Get current polling interval in milliseconds
 */
