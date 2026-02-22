/**
 * Bitchat Action Prompt Engine (GE-1)
 *
 * Displays contextual prompts to guide users based on their activity state.
 * Creates engagement loops by suggesting relevant next actions.
 */

(function() {
    'use strict';

    const BC_PROMPTS = {
        debug: false,
        currentPrompt: null,
        $container: null,

        log: function(message) {
            if (this.debug) console.log('[BC Prompts]', message);
        },

        /**
         * Icon mapping for different prompt types
         */
        icons: {
            'rocket': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
            'comeback': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>',
            'chart': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'star': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'fire': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'users': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'edit': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
        },

        /**
         * Initialize prompt system
         */
        init: function() {
            this.$container = $('#bc-action-prompt');

            if (this.$container.length === 0) {
                this.log('Prompt container not found');
                return;
            }

            // Load prompt data from container or fetch via AJAX
            const promptData = this.$container.data('prompt');
            if (promptData) {
                this.show(promptData);
            }

            this.log('Action Prompt Engine initialized');
        },

        /**
         * Show an action prompt
         * @param {Object} prompt - Prompt data from PHP
         */
        show: function(prompt) {
            // Validate prompt data before rendering
            if (!prompt || typeof prompt !== 'object' || !prompt.title || !prompt.message || !prompt.cta_text) {
                this.log('Invalid prompt data, skipping render');
                return;
            }

            this.currentPrompt = prompt;

            const icon = this.icons[prompt.icon] || this.icons['edit'];
            const typeClass = 'bc-prompt-' + prompt.type;

            const html = `
                <div class="bc-prompt-card ${typeClass}">
                    <div class="bc-prompt-icon">${icon}</div>
                    <div class="bc-prompt-content">
                        <h3 class="bc-prompt-title">${prompt.title}</h3>
                        <p class="bc-prompt-message">${prompt.message}</p>
                    </div>
                    <button type="button" class="bc-prompt-cta" data-action="${prompt.cta_action}">
                        ${prompt.cta_text}
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            `;

            this.$container.html(html).addClass('bc-prompt-visible');

            // Attach CTA handler
            this.$container.find('.bc-prompt-cta').on('click', () => {
                this.handleAction(prompt.cta_action, prompt.type);
            });

            this.log('Prompt shown: ' + prompt.type);
        },

        /**
         * Handle prompt CTA actions
         * @param {string} action - Action type
         * @param {string} promptType - Prompt type for analytics
         */
        handleAction: function(action, promptType) {
            this.log('Action triggered: ' + action + ' from ' + promptType);

            var siteUrl = (window.BC_CONFIG && window.BC_CONFIG.siteUrl) ? window.BC_CONFIG.siteUrl : '';

            switch(action) {
                case 'openComposer':
                    // Open post composer modal (only if not already open)
                    var $tpb = $('#tagPostBox');
                    if ($tpb.length && !$tpb.hasClass('show') && !$tpb.hasClass('in')) {
                        $tpb.modal('show');
                    }
                    break;

                case 'goToDiscover':
                    window.location.href = siteUrl + '/discover';
                    break;

                case 'goToWallet':
                    window.location.href = siteUrl + '/wallet';
                    break;

                default:
                    this.log('Unknown action: ' + action);
            }

            // Track engagement (optional analytics)
            this.trackPromptEngagement(promptType, action);

            // Hide prompt after action (optional)
            // this.hide();
        },

        /**
         * Hide the prompt
         */
        hide: function() {
            this.$container.removeClass('bc-prompt-visible').html('');
            this.currentPrompt = null;
            this.log('Prompt hidden');
        },

        /**
         * Track prompt engagement for analytics
         * @param {string} promptType - Type of prompt
         * @param {string} action - Action taken
         */
        trackPromptEngagement: function(promptType, action) {
            // Send tracking data via AJAX (optional)
            if (typeof Wo_Ajax_Requests_File === 'function') {
                $.post(Wo_Ajax_Requests_File() + '?f=track_prompt', {
                    prompt_type: promptType,
                    action: action,
                    timestamp: Date.now()
                }, function(data) {
                    // Handle response if needed
                }).fail(function() {
                    // Silent fail - tracking is optional
                });
            }
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        BC_PROMPTS.init();
    });

    // Expose to global scope
    window.BC_PROMPTS = BC_PROMPTS;

})();
