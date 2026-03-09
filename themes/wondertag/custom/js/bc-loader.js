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
            // 1. Handle data-src images with IntersectionObserver
            var dataSrcImages = document.querySelectorAll('img[data-src]');
            if (dataSrcImages.length > 0 && 'IntersectionObserver' in window) {
                var imageObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var img = entry.target;
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            imageObserver.unobserve(img);
                        }
                    });
                });
                dataSrcImages.forEach(function(img) { imageObserver.observe(img); });
            }

            // 2. Add native lazy loading to all feed/post images
            this._applyNativeLazy(document);

            // 3. Watch for dynamically loaded posts (infinite scroll, AJAX)
            if ('MutationObserver' in window) {
                var self = this;
                var feedObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) self._applyNativeLazy(node);
                        });
                    });
                });
                var feedContainer = document.getElementById('posts') || document.querySelector('.tag_posts');
                if (feedContainer) {
                    feedObserver.observe(feedContainer, { childList: true, subtree: true });
                }
            }

            this.log('Lazy image loading enabled');
        },

        _applyNativeLazy: function(root) {
            // Apply loading="lazy" to post/feed images that don't already have it
            var imgs = root.querySelectorAll('.tag_post_full_img img, .tag_post_full_albm img, .tag_post_full_stick img, .post-map img, .shared_post img, .wo_shared_doc_file img');
            var count = 0;
            imgs.forEach(function(img) {
                if (!img.getAttribute('loading')) {
                    img.setAttribute('loading', 'lazy');
                    count++;
                }
            });
            if (count > 0 && this.debug) this.log('Applied native lazy to ' + count + ' images');
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
