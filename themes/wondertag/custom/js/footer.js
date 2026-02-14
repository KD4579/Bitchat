/*
 * Bitchat — Safety: prevent stuck invisible content area.
 *
 * WoWonder's SPA navigation adds "opacity_start" to #ajax_loading
 * (which wraps #contnet) to fade out content during page transitions.
 * If the AJAX load fails, "opacity_start" is never removed and the
 * entire content area stays invisible (opacity: 0, pointer-events: none).
 *
 * This safety net removes the class after page load and also monitors
 * for it getting stuck during SPA navigation.
 */
(function() {
    // On initial page load, ensure content is visible
    function ensureContentVisible() {
        var el = document.getElementById('ajax_loading');
        if (el && el.classList.contains('opacity_start')) {
            el.classList.remove('opacity_start');
        }
    }

    // Run after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureContentVisible);
    } else {
        ensureContentVisible();
    }

    // Safety: also run after a short delay to catch race conditions
    setTimeout(ensureContentVisible, 1500);

    // Monitor: if opacity_start persists for more than 8 seconds during
    // SPA navigation, force-remove it (AJAX probably failed)
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
})();
