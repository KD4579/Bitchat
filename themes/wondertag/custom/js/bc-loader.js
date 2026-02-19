/**
 * Bitchat Feed-First Optimizer (SM-1: Conservative Approach)
 *
 * Lazy-loads non-critical content to prioritize feed rendering.
 * Uses Intersection Observer for viewport-aware loading.
 */

(function() {
    'use strict';

    const BC_FEED_OPTIMIZER = {
        debug: false,

        log: function(message) {
            if (this.debug) console.log('[BC Feed Optimizer]', message);
        },

        /**
         * Lazy-load stories when they come into viewport
         */
        lazyLoadStories: function() {
            const storiesContainer = document.querySelector('.tag_stories_on_home');
            if (!storiesContainer) return;

            // Check if already loaded
            if (storiesContainer.dataset.loaded === 'true') return;

            // Use IntersectionObserver for lazy loading
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            // Stories are in viewport, ensure they're visible
                            storiesContainer.dataset.loaded = 'true';
                            observer.disconnect();
                            BC_FEED_OPTIMIZER.log('Stories loaded');
                        }
                    });
                }, {
                    rootMargin: '50px' // Load slightly before entering viewport
                });

                observer.observe(storiesContainer);
            } else {
                // Fallback for older browsers
                storiesContainer.dataset.loaded = 'true';
            }
        },

        /**
         * Defer chat initialization slightly
         */
        deferChat: function() {
            // Chat already loads via WebSocket in container.phtml
            // This function reserved for future chat optimizations
            this.log('Chat deferred');
        },

        /**
         * Optimize image loading in feed
         */
        lazyLoadImages: function() {
            const images = document.querySelectorAll('img[data-src]');
            if (images.length === 0) return;

            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            imageObserver.unobserve(img);
                        }
                    });
                });

                images.forEach(function(img) {
                    imageObserver.observe(img);
                });

                this.log('Lazy image loading enabled for ' + images.length + ' images');
            }
        },

        /**
         * Initialize all optimizations
         */
        init: function() {
            const self = this;

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    self.lazyLoadStories();
                    self.lazyLoadImages();
                    setTimeout(function() {
                        self.deferChat();
                    }, 1000);
                });
            } else {
                // DOM already loaded
                self.lazyLoadStories();
                self.lazyLoadImages();
                setTimeout(function() {
                    self.deferChat();
                }, 1000);
            }

            this.log('Feed-first optimizer initialized');
        }
    };

    // Auto-initialize
    BC_FEED_OPTIMIZER.init();

    // Expose to global scope
    window.BC_FEED_OPTIMIZER = BC_FEED_OPTIMIZER;

})();
