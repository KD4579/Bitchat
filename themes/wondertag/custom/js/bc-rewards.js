/**
 * Bitchat TRDC Dopamine Feedback System (GE-2)
 *
 * Displays celebratory toast notifications when users earn TRDC tokens.
 * Consumes server-pushed reward toasts from:
 *   1. Page-load: window.__BC_PENDING_TOASTS (set by PHP in container.phtml)
 *   2. AJAX responses: data.reward_toasts array from XHR handlers
 *   3. Custom JS events: bc:trdc:earned
 */

(function() {
    'use strict';

    var BC_REWARDS = {
        debug: false,
        toastQueue: [],
        isShowingToast: false,
        container: null,
        sessionEarnings: 0,

        log: function(message) {
            if (this.debug) console.log('[BC Rewards]', message);
        },

        /**
         * Initialize reward system
         */
        init: function() {
            this.createContainer();
            this.attachEventListeners();
            this.processPageLoadToasts();
            this.interceptAjax();
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

            var container = document.createElement('div');
            container.id = 'bc-reward-container';
            container.className = 'bc-reward-container';
            document.body.appendChild(container);
            this.container = container;
        },

        /**
         * Attach event listeners for custom TRDC earning events
         */
        attachEventListeners: function() {
            var self = this;

            // Listen for custom TRDC earning events
            document.addEventListener('bc:trdc:earned', function(e) {
                var d = e.detail || {};
                self.showReward(d.amount, d.type, d.message, d.punchline);
            });

            this.log('Event listeners attached');
        },

        /**
         * Process page-load toasts from PHP session (set as inline JS in container.phtml)
         */
        processPageLoadToasts: function() {
            if (window.__BC_PENDING_TOASTS && window.__BC_PENDING_TOASTS.length > 0) {
                var self = this;
                var toasts = window.__BC_PENDING_TOASTS;
                window.__BC_PENDING_TOASTS = [];

                // Show with slight delay for page to settle
                setTimeout(function() {
                    for (var i = 0; i < toasts.length; i++) {
                        var t = toasts[i];
                        self.showReward(t.amount, t.type, t.title, t.punchline);
                    }
                }, 800);

                this.log('Processed ' + toasts.length + ' page-load toasts');
            }
        },

        /**
         * Intercept jQuery AJAX responses to catch reward_toasts from any XHR handler
         */
        interceptAjax: function() {
            var self = this;

            if (typeof $ !== 'undefined' && $.ajaxSetup) {
                $(document).ajaxSuccess(function(event, xhr, settings) {
                    try {
                        var contentType = xhr.getResponseHeader('Content-Type') || '';
                        if (contentType.indexOf('json') === -1) return;

                        var data = typeof xhr.responseJSON !== 'undefined'
                            ? xhr.responseJSON
                            : JSON.parse(xhr.responseText);

                        if (data && data.reward_toasts && data.reward_toasts.length > 0) {
                            for (var i = 0; i < data.reward_toasts.length; i++) {
                                var t = data.reward_toasts[i];
                                self.showReward(t.amount, t.type, t.title, t.punchline);
                            }
                            self.log('Processed ' + data.reward_toasts.length + ' AJAX toasts');
                        }
                    } catch (e) {
                        // Silently ignore parse errors
                    }
                });
            }
        },

        /**
         * Show reward toast notification
         * @param {number} amount - TRDC amount earned
         * @param {string} type - Reward type key (post_create, comment_create, etc.)
         * @param {string} title - Reward title (e.g. "Post Created")
         * @param {string} punchline - Motivational punchline from DB
         */
        showReward: function(amount, type, title, punchline) {
            amount = parseFloat(amount) || 0;
            if (amount <= 0) return;

            var toast = {
                amount: amount,
                type: type || 'general',
                title: title || 'TRDC Earned',
                punchline: punchline || '',
                timestamp: Date.now()
            };

            this.toastQueue.push(toast);
            this.sessionEarnings += amount;

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
            var toast = this.toastQueue.shift();
            var self = this;

            var toastEl = this.createToastElement(toast);
            this.container.appendChild(toastEl);

            // Animate in
            setTimeout(function() {
                toastEl.classList.add('bc-reward-show');
            }, 10);

            // Animate out after 4 seconds
            setTimeout(function() {
                toastEl.classList.remove('bc-reward-show');
                toastEl.classList.add('bc-reward-hide');

                setTimeout(function() {
                    if (toastEl.parentNode) {
                        toastEl.parentNode.removeChild(toastEl);
                    }
                    self.showNextToast();
                }, 400);
            }, 4000);

            this.updateTRDCBalance(toast.amount);
            this.log('Toast shown: +' + toast.amount + ' TRDC');
        },

        /**
         * Create toast DOM element with punchline
         * @param {Object} toast - Toast data
         * @returns {HTMLElement}
         */
        createToastElement: function(toast) {
            var div = document.createElement('div');
            div.className = 'bc-reward-toast bc-reward-' + toast.type;

            // TRDC coin SVG icon
            var coinSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 512 512">'
                + '<circle cx="256" cy="256" r="256" fill="#1a1a2e"/>'
                + '<path d="M351.7 160H280V110h0v50h-110.7v50H232v142h48V210h71.7v-50z" fill="#fcd535"/>'
                + '<ellipse cx="256" cy="210" rx="128" ry="40" fill="none" stroke="#fcd500" stroke-width="20"/>'
                + '</svg>';

            // Format amount: show decimals only if needed
            var amountStr = toast.amount % 1 === 0
                ? toast.amount.toString()
                : toast.amount.toFixed(2);

            // Build toast HTML
            var html = '<div class="bc-reward-coin">' + coinSvg + '</div>'
                + '<div class="bc-reward-body">'
                + '<div class="bc-reward-amount">+' + amountStr + ' TRDC</div>';

            if (toast.punchline) {
                html += '<div class="bc-reward-punchline">' + this.escapeHtml(toast.punchline) + '</div>';
            } else if (toast.title) {
                html += '<div class="bc-reward-punchline">' + this.escapeHtml(toast.title) + '</div>';
            }

            html += '</div>'
                + '<button class="bc-reward-close" aria-label="Close">&times;</button>';

            div.innerHTML = html;

            // Close button handler
            var closeBtn = div.querySelector('.bc-reward-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    div.classList.remove('bc-reward-show');
                    div.classList.add('bc-reward-hide');
                    setTimeout(function() {
                        if (div.parentNode) div.parentNode.removeChild(div);
                    }, 400);
                });
            }

            return div;
        },

        /**
         * Escape HTML to prevent XSS in toast content
         */
        escapeHtml: function(str) {
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        },

        /**
         * Update TRDC balance display in UI
         * @param {number} amount - Amount to add
         */
        updateTRDCBalance: function(amount) {
            var balanceEl = document.querySelector('.bc-trdc-balance');
            if (balanceEl) {
                var currentBalance = parseFloat(balanceEl.textContent) || 0;
                var newBalance = currentBalance + amount;
                balanceEl.textContent = newBalance.toFixed(2) + ' TRDC';

                balanceEl.classList.add('bc-balance-updated');
                setTimeout(function() {
                    balanceEl.classList.remove('bc-balance-updated');
                }, 600);
            }

            document.dispatchEvent(new CustomEvent('bc:balance:updated', {
                detail: { amount: amount }
            }));
        },

        /**
         * Manually trigger a reward (for testing)
         */
        triggerReward: function(amount, type, title, punchline) {
            this.showReward(amount, type, title, punchline);
        },

        /**
         * Get total TRDC earned in this session
         */
        getSessionEarnings: function() {
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
