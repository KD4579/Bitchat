/**
 * Bitchat Gamification Badges (GE-3)
 *
 * Achievement badge system that celebrates user milestones.
 * Integrates with GE-2 reward toasts for badge unlock celebrations.
 */

(function() {
    'use strict';

    const BC_BADGES = {
        debug: false,
        userBadges: [],

        log: function(message) {
            if (this.debug) console.log('[BC Badges]', message);
        },

        /**
         * Initialize badge system
         */
        init: function() {
            // Check if user is logged in
            if (!window.BC_CONFIG || !window.BC_CONFIG.loggedIn) {
                return;
            }

            // Load user's current badges
            this.loadUserBadges();

            // Listen for badge unlock events
            this.attachEventListeners();

            this.log('Badge system initialized');
        },

        /**
         * Load user's current badges
         */
        loadUserBadges: function() {
            // Badges are loaded via PHP and displayed in profile/sidebar
            // This function can fetch updated badges via AJAX if needed
            const badgeElements = document.querySelectorAll('[data-badge-key]');
            badgeElements.forEach(el => {
                this.userBadges.push(el.dataset.badgeKey);
            });

            this.log('Loaded ' + this.userBadges.length + ' badges');
        },

        /**
         * Attach event listeners
         */
        attachEventListeners: function() {
            const self = this;

            // Listen for badge unlock events
            document.addEventListener('bc:badge:unlocked', function(e) {
                const { badge } = e.detail;
                self.celebrateBadgeUnlock(badge);
            });
        },

        /**
         * Celebrate badge unlock with toast and modal
         * @param {Object} badge - Badge object {key, name, description, icon, color}
         */
        celebrateBadgeUnlock: function(badge) {
            // Show reward toast (integrates with GE-2)
            if (typeof BC_REWARDS !== 'undefined') {
                BC_REWARDS.showReward(0, 'badge_unlock', badge.icon + ' Badge Unlocked: ' + badge.name + '!');
            }

            // Show detailed badge modal (integrates with SM-3)
            setTimeout(() => {
                if (typeof BC_MODAL !== 'undefined') {
                    const content = `
                        <div class="bc-badge-unlock-modal">
                            <div class="bc-badge-unlock-icon" style="background: ${badge.color}">
                                ${badge.icon}
                            </div>
                            <h3 class="bc-badge-unlock-title">Badge Unlocked!</h3>
                            <h2 class="bc-badge-unlock-name">${badge.name}</h2>
                            <p class="bc-badge-unlock-desc">${badge.description}</p>
                        </div>
                    `;

                    BC_MODAL.show({
                        title: 'Achievement Unlocked!',
                        content: content,
                        size: 'md',
                        buttons: [
                            {
                                text: 'Awesome!',
                                class: 'btn-main',
                                onClick: () => BC_MODAL.hide()
                            }
                        ]
                    });
                }
            }, 3500); // Show modal after toast

            // Add to user's badge list
            if (!this.userBadges.includes(badge.key)) {
                this.userBadges.push(badge.key);
            }

            this.log('Badge unlocked: ' + badge.key);
        },

        /**
         * Check if user has earned a specific badge
         * @param {string} badgeKey - Badge key to check
         * @returns {boolean}
         */
        hasBadge: function(badgeKey) {
            return this.userBadges.includes(badgeKey);
        },

        /**
         * Manually trigger badge unlock (for testing or manual awards)
         * @param {Object} badge - Badge object
         */
        unlockBadge: function(badge) {
            if (this.hasBadge(badge.key)) {
                this.log('User already has badge: ' + badge.key);
                return;
            }

            this.celebrateBadgeUnlock(badge);
        },

        /**
         * Render badge HTML
         * @param {Object} badge - Badge object
         * @param {boolean} small - Small size flag
         * @returns {string} HTML string
         */
        renderBadge: function(badge, small = false) {
            const sizeClass = small ? 'bc-badge-small' : '';
            return `
                <div class="bc-badge ${sizeClass}"
                     data-badge-key="${badge.key}"
                     title="${badge.name}: ${badge.description}"
                     style="background: ${badge.color}">
                    <span class="bc-badge-icon">${badge.icon}</span>
                    ${!small ? `<span class="bc-badge-name">${badge.name}</span>` : ''}
                </div>
            `;
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        BC_BADGES.init();
    });

    // Expose to global scope
    window.BC_BADGES = BC_BADGES;

})();


/**
 * USAGE EXAMPLES:
 *
 * 1. Trigger badge unlock from backend:
 * // In PHP after user earns badge:
 * echo "<script>
 *     document.dispatchEvent(new CustomEvent('bc:badge:unlocked', {
 *         detail: {
 *             badge: {
 *                 key: 'first_post',
 *                 name: 'First Post',
 *                 description: 'Published your first post',
 *                 icon: '🎉',
 *                 color: '#f093fb'
 *             }
 *         }
 *     }));
 * </script>";
 *
 * 2. Manually unlock badge (testing):
 * BC_BADGES.unlockBadge({
 *     key: 'market_master',
 *     name: 'Market Master',
 *     description: 'Posted 25+ trading insights',
 *     icon: '📈',
 *     color: '#4facfe'
 * });
 *
 * 3. Check if user has badge:
 * if (BC_BADGES.hasBadge('verified')) {
 *     console.log('User is verified');
 * }
 *
 * 4. Render badge HTML:
 * const badgeHTML = BC_BADGES.renderBadge(badge, false);
 * $('#badge-container').append(badgeHTML);
 */
