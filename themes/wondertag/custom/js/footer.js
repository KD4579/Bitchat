/*
 * Bitchat — Safety net for content visibility and feed loading.
 *
 * 1. Removes stuck "opacity_start" class that hides the content area.
 * 2. Detects stuck skeleton loaders (feed failed to load via AJAX)
 *    and retriggers loadposts() if available.
 */
(function() {
    // --- Part 1: Prevent stuck invisible content area ---
    function ensureContentVisible() {
        var el = document.getElementById('ajax_loading');
        if (el && el.classList.contains('opacity_start')) {
            el.classList.remove('opacity_start');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureContentVisible);
    } else {
        ensureContentVisible();
    }

    setTimeout(ensureContentVisible, 1500);

    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (m.type === 'attributes' && m.attributeName === 'class') {
                var el = m.target;
                if (el.classList.contains('opacity_start')) {
                    setTimeout(function() {
                        if (el.classList.contains('opacity_start')) {
                            el.classList.remove('opacity_start');
                        }
                    }, 8000);
                }
            }
        });
    });

    var target = document.getElementById('ajax_loading');
    if (target) {
        observer.observe(target, { attributes: true });
    }

    // --- Part 2: Detect stuck feed skeleton loaders ---
    // If after 10 seconds #posts-laoded still contains only skeleton
    // placeholders (no real post content), retry loading the feed.
    setTimeout(function() {
        var postsEl = document.getElementById('posts-laoded');
        if (!postsEl) return;

        var hasRealContent = postsEl.querySelector('.post-container, .wo_post_sec, .post-card, .no_posts, .empty_state, [data-post-id]');
        var hasSkeleton = postsEl.querySelector('.tag_post_skel, .skel');

        if (!hasRealContent && hasSkeleton) {
            // Feed never loaded — retry
            if (typeof loadposts === 'function') {
                loadposts(0);
            }
        }
    }, 10000);
})();
