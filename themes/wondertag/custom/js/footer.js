/*
 * Bitchat — Safety net for content visibility, feed loading, and dark mode.
 *
 * 1. Removes stuck "opacity_start" class that hides the content area.
 * 2. Detects stuck skeleton loaders and directly loads the feed via AJAX
 *    (independent of the page's own loadposts function, works even if
 *    the user's browser has cached an older version of the page).
 * 3. Syncs .bc-dark-mode class on <html> for CSS custom properties
 *    (fallback for browsers without :has() support).
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

    var target = document.getElementById('ajax_loading');
    if (target) {
        var opacityObserver = new MutationObserver(function(mutations) {
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
        opacityObserver.observe(target, { attributes: true });
    }

    // --- Part 2: Detect stuck feed and load directly ---
    // Self-sufficient: makes its own AJAX call, does NOT depend on
    // the page's loadposts() function (which may be cached/old/broken).
    function rescueFeed() {
        var postsEl = document.getElementById('posts-laoded');
        if (!postsEl) return;

        // Check if real content exists (posts, empty state, etc.)
        var hasRealContent = postsEl.querySelector(
            '.post-container, .wo_post_sec, .post-card, .no_posts, .empty_state, [data-post-id], #posts'
        );

        if (hasRealContent) return; // Feed loaded fine, nothing to do

        // Feed is stuck — determine the AJAX URL
        var ajaxUrl = '';
        if (typeof Wo_Ajax_Requests_File === 'function') {
            ajaxUrl = Wo_Ajax_Requests_File();
        } else {
            // Fallback: construct URL from current page location
            var loc = window.location;
            ajaxUrl = loc.protocol + '//' + loc.host + '/requests.php';
        }

        // Direct AJAX call to load feed
        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajaxUrl + '?f=load_posts&_=' + Date.now(), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 20000;
        xhr.onload = function() {
            if (xhr.status === 200 && xhr.responseText && xhr.responseText.trim().length > 50) {
                postsEl.innerHTML = xhr.responseText;
                // Execute any inline scripts in the response
                var scripts = postsEl.querySelectorAll('script');
                for (var i = 0; i < scripts.length; i++) {
                    try {
                        var s = document.createElement('script');
                        s.textContent = scripts[i].textContent;
                        scripts[i].parentNode.replaceChild(s, scripts[i]);
                    } catch(e) {}
                }
            } else {
                postsEl.innerHTML = '<div style="text-align:center;padding:20px;">' +
                    '<p>Could not load feed.</p>' +
                    '<button onclick="location.reload()" style="padding:8px 20px;border-radius:20px;border:1px solid #ccc;background:#fff;cursor:pointer;">Refresh Page</button>' +
                    '</div>';
            }
        };
        xhr.onerror = function() {
            postsEl.innerHTML = '<div style="text-align:center;padding:20px;">' +
                '<p>Connection error. Please check your network.</p>' +
                '<button onclick="location.reload()" style="padding:8px 20px;border-radius:20px;border:1px solid #ccc;background:#fff;cursor:pointer;">Refresh Page</button>' +
                '</div>';
        };
        xhr.send();
    }

    // Check at 6s and 12s for stuck feed
    setTimeout(rescueFeed, 6000);
    setTimeout(rescueFeed, 12000);

    // --- Part 3: Dark mode class sync (fallback for browsers without :has()) ---
    function syncDarkMode() {
        var nightCss = document.getElementById('night-mode-css');
        if (nightCss) {
            document.documentElement.classList.add('bc-dark-mode');
        } else {
            document.documentElement.classList.remove('bc-dark-mode');
        }
    }

    syncDarkMode();

    var head = document.querySelector('head');
    if (head) {
        var darkObserver = new MutationObserver(syncDarkMode);
        darkObserver.observe(head, { childList: true });
    }
})();
