/**
 * Infinite Scroll Module for Bitchat
 * Handles paginated loading of posts with caching support
 */

(function(window) {
    'use strict';

    var InfiniteScroll = {
        // Configuration
        config: {
            postsPerPage: 10,
            loadThreshold: 300, // pixels from bottom to trigger load
            debounceDelay: 200,
            maxRetries: 3
        },

        // State
        state: {
            page: 1,
            loading: false,
            hasMore: true,
            lastPostId: 0,
            retryCount: 0,
            initialized: false
        },

        // DOM Elements
        elements: {
            container: null,
            loader: null,
            endMessage: null
        },

        /**
         * Initialize infinite scroll
         * @param {Object} options Configuration options
         */
        init: function(options) {
            if (this.state.initialized) return;

            // Merge options
            if (options) {
                Object.assign(this.config, options);
            }

            // Find container
            this.elements.container = document.querySelector('#posts-container, .posts-container, #the_drag, .Ede');
            if (!this.elements.container) {
                console.warn('InfiniteScroll: No posts container found');
                return;
            }

            // Create loader element
            this.createLoader();

            // Bind scroll event with debounce
            this.bindEvents();

            this.state.initialized = true;
            console.log('InfiniteScroll: Initialized');
        },

        /**
         * Create loading indicator
         */
        createLoader: function() {
            // Create loader
            this.elements.loader = document.createElement('div');
            this.elements.loader.className = 'infinite-scroll-loader hidden';
            this.elements.loader.innerHTML = '<div class="infinite-scroll-spinner"></div>';

            // Create end message
            this.elements.endMessage = document.createElement('div');
            this.elements.endMessage.className = 'end-of-feed hidden';
            this.elements.endMessage.innerHTML = '<i class="fa fa-check-circle"></i>You\'ve seen all posts';

            // Append to container parent
            var parent = this.elements.container.parentNode;
            parent.appendChild(this.elements.loader);
            parent.appendChild(this.elements.endMessage);
        },

        /**
         * Bind scroll and resize events
         */
        bindEvents: function() {
            var self = this;
            var debounceTimer = null;

            var handleScroll = function() {
                if (debounceTimer) clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    self.checkScroll();
                }, self.config.debounceDelay);
            };

            window.addEventListener('scroll', handleScroll, { passive: true });
            window.addEventListener('resize', handleScroll, { passive: true });
        },

        /**
         * Check if we should load more posts
         */
        checkScroll: function() {
            if (this.state.loading || !this.state.hasMore) return;

            var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            var windowHeight = window.innerHeight;
            var docHeight = document.documentElement.scrollHeight;

            // Check if near bottom
            if (scrollTop + windowHeight >= docHeight - this.config.loadThreshold) {
                this.loadMore();
            }
        },

        /**
         * Load more posts
         */
        loadMore: function() {
            if (this.state.loading || !this.state.hasMore) return;

            var self = this;
            this.state.loading = true;
            this.showLoader();

            // Build request data
            var data = new FormData();
            data.append('f', 'get_posts_paginated');
            data.append('page', this.state.page + 1);
            data.append('limit', this.config.postsPerPage);

            if (this.state.lastPostId > 0) {
                data.append('after_post_id', this.state.lastPostId);
            }

            // Add filter parameters if available
            if (window.Wo_Posts_Filter) {
                data.append('filter_by', window.Wo_Posts_Filter);
            }
            if (window.Wo_Publisher_Id) {
                data.append('user_id', window.Wo_Publisher_Id);
            }
            if (window.Wo_Page_Id) {
                data.append('page_id', window.Wo_Page_Id);
            }
            if (window.Wo_Group_Id) {
                data.append('group_id', window.Wo_Group_Id);
            }

            // Fetch posts
            fetch(Wo_Ajax_Requests_File(), {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                self.handleResponse(result);
            })
            .catch(function(error) {
                self.handleError(error);
            });
        },

        /**
         * Handle successful response
         */
        handleResponse: function(result) {
            this.state.loading = false;
            this.state.retryCount = 0;
            this.hideLoader();

            if (result.status === 200) {
                // Append posts HTML
                if (result.posts_html && result.posts_html.length > 0) {
                    this.appendPosts(result.posts_html);
                    this.state.page++;
                    this.state.lastPostId = result.last_post_id || 0;
                }

                // Update hasMore
                this.state.hasMore = result.has_more || false;

                // Show end message if no more posts
                if (!this.state.hasMore) {
                    this.showEndMessage();
                }

                // Trigger event for other scripts
                this.triggerEvent('postsLoaded', {
                    count: result.count || 0,
                    page: this.state.page,
                    hasMore: this.state.hasMore
                });
            }
        },

        /**
         * Handle fetch error
         */
        handleError: function(error) {
            console.error('InfiniteScroll: Error loading posts', error);
            this.state.loading = false;
            this.hideLoader();

            // Retry logic
            this.state.retryCount++;
            if (this.state.retryCount < this.config.maxRetries) {
                var self = this;
                setTimeout(function() {
                    self.loadMore();
                }, 2000 * this.state.retryCount);
            }
        },

        /**
         * Append posts HTML to container
         */
        appendPosts: function(html) {
            // Create temp container
            var temp = document.createElement('div');
            temp.innerHTML = html;

            // Append each post with animation
            var posts = temp.children;
            var container = this.elements.container;

            while (posts.length > 0) {
                var post = posts[0];
                post.style.opacity = '0';
                post.style.transform = 'translateY(20px)';
                container.appendChild(post);

                // Animate in
                requestAnimationFrame(function(el) {
                    return function() {
                        el.style.transition = 'opacity 0.3s, transform 0.3s';
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                    };
                }(post));
            }

            // Reinitialize any necessary plugins
            this.reinitializePlugins();
        },

        /**
         * Reinitialize plugins after adding new posts
         */
        reinitializePlugins: function() {
            // Reinitialize lightbox
            if (typeof Wo_Lightbox_Init === 'function') {
                Wo_Lightbox_Init();
            }

            // Reinitialize reactions
            if (typeof Wo_Reactions_Init === 'function') {
                Wo_Reactions_Init();
            }

            // Reinitialize video players
            if (typeof Wo_Video_Init === 'function') {
                Wo_Video_Init();
            }

            // Trigger jQuery events if available
            if (window.jQuery) {
                jQuery(document).trigger('posts:loaded');
            }
        },

        /**
         * Show loading indicator
         */
        showLoader: function() {
            if (this.elements.loader) {
                this.elements.loader.classList.remove('hidden');
            }
        },

        /**
         * Hide loading indicator
         */
        hideLoader: function() {
            if (this.elements.loader) {
                this.elements.loader.classList.add('hidden');
            }
        },

        /**
         * Show end of feed message
         */
        showEndMessage: function() {
            if (this.elements.endMessage) {
                this.elements.endMessage.classList.remove('hidden');
            }
        },

        /**
         * Trigger custom event
         */
        triggerEvent: function(name, detail) {
            var event = new CustomEvent('infiniteScroll:' + name, {
                detail: detail,
                bubbles: true
            });
            document.dispatchEvent(event);
        },

        /**
         * Reset state (for page navigation)
         */
        reset: function() {
            this.state.page = 1;
            this.state.loading = false;
            this.state.hasMore = true;
            this.state.lastPostId = 0;
            this.state.retryCount = 0;

            if (this.elements.endMessage) {
                this.elements.endMessage.classList.add('hidden');
            }
        },

        /**
         * Generate skeleton loader HTML
         */
        getSkeletonHTML: function(count) {
            count = count || 3;
            var html = '';

            for (var i = 0; i < count; i++) {
                html += '<div class="post-skeleton">' +
                    '<div class="post-skeleton-header">' +
                        '<div class="post-skeleton-avatar skeleton"></div>' +
                        '<div class="post-skeleton-info">' +
                            '<div class="post-skeleton-name skeleton"></div>' +
                            '<div class="post-skeleton-time skeleton"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="post-skeleton-content">' +
                        '<div class="post-skeleton-text skeleton"></div>' +
                        '<div class="post-skeleton-text skeleton"></div>' +
                        '<div class="post-skeleton-text skeleton"></div>' +
                    '</div>' +
                    '<div class="post-skeleton-image skeleton"></div>' +
                    '<div class="post-skeleton-actions">' +
                        '<div class="post-skeleton-action skeleton"></div>' +
                        '<div class="post-skeleton-action skeleton"></div>' +
                        '<div class="post-skeleton-action skeleton"></div>' +
                    '</div>' +
                '</div>';
            }

            return html;
        }
    };

    // Export to window
    window.BitchatInfiniteScroll = InfiniteScroll;

    // Auto-initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize on pages with posts
        if (document.querySelector('#posts-container, .posts-container, #the_drag, .Ede')) {
            InfiniteScroll.init();
        }
    });

})(window);
