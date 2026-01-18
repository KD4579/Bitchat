/**
 * Lazy Modal Loading Module for Bitchat
 * Defers modal HTML loading until user action to improve initial page load
 */

(function(window) {
    'use strict';

    var LazyModals = {
        // Cache for loaded modals
        cache: {},

        // Loading state
        loading: {},

        // Modal container
        container: null,

        /**
         * Initialize lazy modal system
         */
        init: function() {
            // Create container for lazy-loaded modals
            this.container = document.getElementById('lazy-modal-container');
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.id = 'lazy-modal-container';
                document.body.appendChild(this.container);
            }

            // Bind trigger events
            this.bindTriggers();

            console.log('LazyModals: Initialized');
        },

        /**
         * Bind click events for modal triggers
         */
        bindTriggers: function() {
            var self = this;

            // Use event delegation for lazy modal triggers
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('[data-lazy-modal]');
                if (trigger) {
                    e.preventDefault();
                    var modalId = trigger.getAttribute('data-lazy-modal');
                    var modalUrl = trigger.getAttribute('data-modal-url') || null;
                    self.show(modalId, modalUrl);
                }
            });
        },

        /**
         * Show a modal, loading it first if necessary
         * @param {string} modalId Modal identifier
         * @param {string} url Optional URL to load modal from
         */
        show: function(modalId, url) {
            var self = this;

            // Check if modal is already in DOM
            var existingModal = document.getElementById(modalId);
            if (existingModal) {
                this.openModal(existingModal);
                return;
            }

            // Check cache
            if (this.cache[modalId]) {
                this.insertAndShow(modalId, this.cache[modalId]);
                return;
            }

            // Prevent duplicate loading
            if (this.loading[modalId]) {
                return;
            }

            // Show loading indicator
            this.showLoader();

            // Load modal from server
            this.loading[modalId] = true;

            var endpoint = url || Wo_Ajax_Requests_File() + '?f=get_modal&modal_id=' + encodeURIComponent(modalId);

            fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                self.cache[modalId] = html;
                self.insertAndShow(modalId, html);
                self.loading[modalId] = false;
                self.hideLoader();
            })
            .catch(function(error) {
                console.error('LazyModals: Error loading modal', error);
                self.loading[modalId] = false;
                self.hideLoader();
            });
        },

        /**
         * Insert modal HTML and show it
         */
        insertAndShow: function(modalId, html) {
            // Insert HTML
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            this.container.appendChild(wrapper);

            // Find and open the modal
            var modal = wrapper.querySelector('.modal') || document.getElementById(modalId);
            if (modal) {
                this.openModal(modal);
                this.initModalScripts(modal);
            }
        },

        /**
         * Open a modal using Bootstrap or custom method
         */
        openModal: function(modal) {
            // Try Bootstrap modal first
            if (window.jQuery && jQuery.fn.modal) {
                jQuery(modal).modal('show');
            } else {
                // Fallback to custom show
                modal.classList.add('in');
                modal.style.display = 'block';
                document.body.classList.add('modal-open');
            }
        },

        /**
         * Initialize any scripts within the modal
         */
        initModalScripts: function(modal) {
            // Execute any inline scripts
            var scripts = modal.querySelectorAll('script');
            scripts.forEach(function(script) {
                var newScript = document.createElement('script');
                if (script.src) {
                    newScript.src = script.src;
                } else {
                    newScript.textContent = script.textContent;
                }
                document.body.appendChild(newScript);
            });

            // Trigger jQuery events if available
            if (window.jQuery) {
                jQuery(document).trigger('modal:loaded', [modal]);
            }
        },

        /**
         * Show loading indicator
         */
        showLoader: function() {
            var loader = document.querySelector('.lb-preloader');
            if (loader) {
                loader.style.display = 'flex';
            }
        },

        /**
         * Hide loading indicator
         */
        hideLoader: function() {
            var loader = document.querySelector('.lb-preloader');
            if (loader) {
                loader.style.display = 'none';
            }
        },

        /**
         * Preload a modal without showing it
         * @param {string} modalId Modal identifier
         * @param {string} url Optional URL to load modal from
         */
        preload: function(modalId, url) {
            var self = this;

            if (this.cache[modalId] || this.loading[modalId]) {
                return;
            }

            this.loading[modalId] = true;

            var endpoint = url || Wo_Ajax_Requests_File() + '?f=get_modal&modal_id=' + encodeURIComponent(modalId);

            fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                self.cache[modalId] = html;
                self.loading[modalId] = false;
            })
            .catch(function(error) {
                console.error('LazyModals: Preload error', error);
                self.loading[modalId] = false;
            });
        },

        /**
         * Clear cache for a specific modal or all modals
         * @param {string} modalId Optional modal ID to clear
         */
        clearCache: function(modalId) {
            if (modalId) {
                delete this.cache[modalId];
            } else {
                this.cache = {};
            }
        },

        /**
         * Remove a modal from DOM (cleanup)
         * @param {string} modalId Modal identifier
         */
        destroy: function(modalId) {
            var modal = document.getElementById(modalId);
            if (modal) {
                // Close modal first
                if (window.jQuery && jQuery.fn.modal) {
                    jQuery(modal).modal('hide');
                }
                // Remove from DOM after animation
                setTimeout(function() {
                    var wrapper = modal.closest('#lazy-modal-container > div');
                    if (wrapper) {
                        wrapper.remove();
                    }
                }, 300);
            }
            // Clear from cache
            delete this.cache[modalId];
        }
    };

    // Export to window
    window.BitchatLazyModals = LazyModals;

    // Auto-initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        LazyModals.init();
    });

})(window);
