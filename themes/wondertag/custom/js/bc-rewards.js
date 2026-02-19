/**
 * Bitchat TRDC Dopamine Feedback System (GE-2)
 *
 * Displays celebratory toast notifications when users earn TRDC tokens.
 * Creates instant gratification feedback loop for engagement actions.
 */

(function() {
    'use strict';

    const BC_REWARDS = {
        debug: false,
        toastQueue: [],
        isShowingToast: false,
        container: null,

        // TRDC reward amounts for different actions
        rewards: {
            'post': 50,
            'comment': 10,
            'like_received': 5,
            'share': 15,
            'profile_view': 2,
            'first_post': 100,
            'daily_login': 20,
            'verify_email': 50,
            'complete_profile': 75
        },

        // Celebration messages for different reward types
        messages: {
            'post': ['Post published!', 'Great content!', 'Keep it up!', 'Awesome post!'],
            'comment': ['Comment added!', 'Nice insight!', 'Great contribution!'],
            'like_received': ['Someone liked your content!', 'You\'re getting popular!', 'Nice work!'],
            'share': ['Content shared!', 'Spreading the word!'],
            'profile_view': ['Profile view earned!'],
            'first_post': ['🎉 First Post Achievement!', 'Welcome to Bitchat!', 'You\'re a creator now!'],
            'daily_login': ['Daily streak bonus!', 'Welcome back!'],
            'verify_email': ['Email verified!', 'Account secured!'],
            'complete_profile': ['Profile completed!', 'Looking good!']
        },

        log: function(message) {
            if (this.debug) console.log('[BC Rewards]', message);
        },

        /**
         * Initialize reward system
         */
        init: function() {
            // Create toast container
            this.createContainer();

            // Listen for TRDC earning events
            this.attachEventListeners();

            this.log('TRDC Reward system initialized');
        },

        /**
         * Create toast container in DOM
         */
        createContainer: function() {
            if (document.getElementById('bc-reward-container')) {
                this.container = document.getElementById('bc-reward-container');
                return;
            }

            const container = document.createElement('div');
            container.id = 'bc-reward-container';
            container.className = 'bc-reward-container';
            document.body.appendChild(container);
            this.container = container;
        },

        /**
         * Attach event listeners for TRDC earning events
         */
        attachEventListeners: function() {
            const self = this;

            // Listen for custom TRDC earning events
            document.addEventListener('bc:trdc:earned', function(e) {
                const { amount, type, message } = e.detail;
                self.showReward(amount, type, message);
            });

            // Listen for post publish success
            $(document).on('post:published', function(e, data) {
                if (data && data.trdc_earned) {
                    self.showReward(data.trdc_earned, 'post');
                }
            });

            // Listen for comment success
            $(document).on('comment:added', function(e, data) {
                if (data && data.trdc_earned) {
                    self.showReward(data.trdc_earned, 'comment');
                }
            });

            this.log('Event listeners attached');
        },

        /**
         * Show reward toast notification
         * @param {number} amount - TRDC amount earned
         * @param {string} type - Reward type (post, comment, like_received, etc.)
         * @param {string} customMessage - Optional custom message
         */
        showReward: function(amount, type, customMessage) {
            amount = parseInt(amount) || 0;
            if (amount <= 0) return;

            // Get random celebration message
            const messages = this.messages[type] || ['TRDC earned!'];
            const message = customMessage || messages[Math.floor(Math.random() * messages.length)];

            // Create toast object
            const toast = {
                amount: amount,
                type: type,
                message: message,
                timestamp: Date.now()
            };

            // Add to queue
            this.toastQueue.push(toast);

            // Show if not already showing
            if (!this.isShowingToast) {
                this.showNextToast();
            }

            this.log('Reward queued: +' + amount + ' TRDC (' + type + ')');
        },

        /**
         * Show next toast in queue
         */
        showNextToast: function() {
            if (this.toastQueue.length === 0) {
                this.isShowingToast = false;
                return;
            }

            this.isShowingToast = true;
            const toast = this.toastQueue.shift();

            // Create toast element
            const toastEl = this.createToastElement(toast);
            this.container.appendChild(toastEl);

            // Animate in
            setTimeout(() => {
                toastEl.classList.add('bc-reward-show');
            }, 10);

            // Animate out after 3 seconds
            setTimeout(() => {
                toastEl.classList.remove('bc-reward-show');
                toastEl.classList.add('bc-reward-hide');

                // Remove from DOM after animation
                setTimeout(() => {
                    if (toastEl.parentNode) {
                        toastEl.parentNode.removeChild(toastEl);
                    }
                    // Show next toast
                    this.showNextToast();
                }, 300);
            }, 3000);

            // Update total TRDC in UI (if element exists)
            this.updateTRDCBalance(toast.amount);

            this.log('Toast shown: +' + toast.amount + ' TRDC');
        },

        /**
         * Create toast DOM element
         * @param {Object} toast - Toast data
         * @returns {HTMLElement}
         */
        createToastElement: function(toast) {
            const div = document.createElement('div');
            div.className = 'bc-reward-toast bc-reward-' + toast.type;

            // Icon based on reward size
            let icon = '💰';
            if (toast.amount >= 100) icon = '🎉';
            else if (toast.amount >= 50) icon = '⭐';
            else if (toast.amount >= 20) icon = '🌟';

            div.innerHTML = `
                <div class="bc-reward-icon">${icon}</div>
                <div class="bc-reward-content">
                    <div class="bc-reward-amount">+${toast.amount} TRDC</div>
                    <div class="bc-reward-message">${toast.message}</div>
                </div>
                <div class="bc-reward-confetti"></div>
            `;

            return div;
        },

        /**
         * Update TRDC balance display in UI
         * @param {number} amount - Amount to add
         */
        updateTRDCBalance: function(amount) {
            // Update sidebar TRDC balance if it exists
            const balanceEl = document.querySelector('.bc-trdc-balance');
            if (balanceEl) {
                const currentBalance = parseInt(balanceEl.textContent) || 0;
                const newBalance = currentBalance + amount;
                balanceEl.textContent = newBalance + ' TRDC';

                // Animate the balance update
                balanceEl.classList.add('bc-balance-updated');
                setTimeout(() => {
                    balanceEl.classList.remove('bc-balance-updated');
                }, 600);
            }

            // Dispatch custom event for other components
            const event = new CustomEvent('bc:balance:updated', {
                detail: { amount: amount }
            });
            document.dispatchEvent(event);
        },

        /**
         * Manually trigger a reward (for testing or manual events)
         * @param {number} amount - TRDC amount
         * @param {string} type - Reward type
         * @param {string} message - Custom message
         */
        triggerReward: function(amount, type, message) {
            this.showReward(amount, type, message);
        },

        /**
         * Get total TRDC earned in this session
         * @returns {number}
         */
        getSessionEarnings: function() {
            // Track session earnings (optional)
            if (!this.sessionEarnings) {
                this.sessionEarnings = 0;
            }
            return this.sessionEarnings;
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        BC_REWARDS.init();
    });

    // Expose to global scope
    window.BC_REWARDS = BC_REWARDS;

})();


/**
 * USAGE EXAMPLES:
 *
 * 1. Trigger reward from PHP AJAX response:
 * In your AJAX success handler:
 * if (data.trdc_earned) {
 *     BC_REWARDS.showReward(data.trdc_earned, 'post', 'Post published!');
 * }
 *
 * 2. Trigger custom event:
 * document.dispatchEvent(new CustomEvent('bc:trdc:earned', {
 *     detail: { amount: 50, type: 'post', message: 'Great post!' }
 * }));
 *
 * 3. Manual trigger (for testing):
 * BC_REWARDS.triggerReward(100, 'first_post', 'Congrats on your first post!');
 *
 * 4. Listen for balance updates:
 * document.addEventListener('bc:balance:updated', function(e) {
 *     console.log('TRDC added:', e.detail.amount);
 * });
 */
