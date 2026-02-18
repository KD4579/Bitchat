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

/* ==========================================================================
   UI MASTER PLAN — JavaScript (Parts 2, 3, 8, 11)
   ========================================================================== */
(function() {

/* ---- Part 2: Market Strip Ticker ---- */
function updateTicker(el, label, price, change, isUp) {
    if (!el) return;
    var arrow = isUp ? '▲' : '▼';
    var cls = isUp ? 'bc-ticker-up' : 'bc-ticker-down';
    if (change === 0) { arrow = '–'; cls = 'bc-ticker-flat'; }
    el.innerHTML =
        '<span class="bc-ticker-label">' + label + '</span>' +
        '<span class="bc-ticker-price">' + price + '</span>' +
        '<span class="bc-ticker-change ' + cls + '">' + arrow + ' ' + Math.abs(change).toFixed(2) + '%</span>';
}

function fetchCrypto() {
    var strip = document.getElementById('bc-market-strip');
    if (!strip) return;
    var btcEl = document.getElementById('bc-tick-btc');
    var ethEl = document.getElementById('bc-tick-eth');
    if (!btcEl || !ethEl) return;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=inr&include_24hr_change=true', true);
    xhr.timeout = 10000;
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.bitcoin) {
                    var btcP = '₹' + Math.round(d.bitcoin.inr).toLocaleString('en-IN');
                    var btcC = d.bitcoin.inr_24h_change || 0;
                    updateTicker(btcEl, 'BTC', btcP, btcC, btcC >= 0);
                }
                if (d.ethereum) {
                    var ethP = '₹' + Math.round(d.ethereum.inr).toLocaleString('en-IN');
                    var ethC = d.ethereum.inr_24h_change || 0;
                    updateTicker(ethEl, 'ETH', ethP, ethC, ethC >= 0);
                }
            } catch(e) {}
        }
    };
    xhr.send();
}

function fetchIndices() {
    var niftyEl = document.getElementById('bc-tick-nifty');
    var sensexEl = document.getElementById('bc-tick-sensex');
    if (!niftyEl && !sensexEl) return;

    // NIFTY
    if (niftyEl) {
        var xn = new XMLHttpRequest();
        xn.open('GET', 'https://query1.finance.yahoo.com/v8/finance/chart/%5ENSEI?interval=1d&range=2d', true);
        xn.timeout = 8000;
        xn.onload = function() {
            try {
                var d = JSON.parse(xn.responseText);
                var meta = d.chart.result[0].meta;
                var price = meta.regularMarketPrice;
                var prev  = meta.chartPreviousClose;
                var chg   = prev ? ((price - prev) / prev * 100) : 0;
                updateTicker(niftyEl, 'NIFTY', price.toFixed(2), chg, chg >= 0);
            } catch(e) {}
        };
        xn.send();
    }
    // SENSEX
    if (sensexEl) {
        var xs = new XMLHttpRequest();
        xs.open('GET', 'https://query1.finance.yahoo.com/v8/finance/chart/%5EBSESN?interval=1d&range=2d', true);
        xs.timeout = 8000;
        xs.onload = function() {
            try {
                var d = JSON.parse(xs.responseText);
                var meta = d.chart.result[0].meta;
                var price = meta.regularMarketPrice;
                var prev  = meta.chartPreviousClose;
                var chg   = prev ? ((price - prev) / prev * 100) : 0;
                updateTicker(sensexEl, 'SENSEX', price.toFixed(2), chg, chg >= 0);
            } catch(e) {}
        };
        xs.send();
    }
}

var marketStrip = document.getElementById('bc-market-strip');
if (marketStrip) {
    fetchCrypto();
    fetchIndices();
    setInterval(fetchCrypto, 60000);
    setInterval(fetchIndices, 60000);
}

/* ---- Part 3: Native Notification Popup ---- */
(function() {
    var popup = document.getElementById('bc-notif-popup');
    if (!popup) return;

    function getCookie(name) {
        var m = document.cookie.match('(?:^|;)\\s*' + name + '=([^;]*)');
        return m ? m[1] : null;
    }
    function setCookie(name, val, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + val + ';expires=' + d.toUTCString() + ';path=/';
    }

    if (getCookie('bc_push_shown')) return;

    var shown = false;
    function showPopup() {
        if (shown) return;
        shown = true;
        popup.classList.add('bc-notif-visible');
    }

    // Trigger on 40% scroll depth
    window.addEventListener('scroll', function onScroll() {
        var scrolled = (window.scrollY + window.innerHeight) / document.body.scrollHeight;
        if (scrolled > 0.4) {
            showPopup();
            window.removeEventListener('scroll', onScroll);
        }
    }, { passive: true });

    // Trigger on 25s session time
    setTimeout(showPopup, 25000);

    // Allow button
    var allowBtn = document.getElementById('bc-notif-allow');
    if (allowBtn) {
        allowBtn.addEventListener('click', function() {
            setCookie('bc_push_shown', '1', 7);
            popup.classList.remove('bc-notif-visible');
            if (window.OneSignal) {
                OneSignal.push(function() {
                    OneSignal.registerForPushNotifications();
                });
            }
        });
    }

    // Dismiss button
    var dismissBtn = document.getElementById('bc-notif-dismiss');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            setCookie('bc_push_shown', '1', 7);
            popup.classList.remove('bc-notif-visible');
        });
    }
})();

/* ---- Part 5: Composer "More Options" Button ---- */
(function() {
    function injectComposerMoreBtn() {
        var footer = document.querySelector('.pub-footer-upper');
        if (!footer || footer.querySelector('.bc-composer-more-btn')) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bc-composer-more-btn';
        btn.innerHTML = '••• More';
        btn.onclick = function() {
            footer.classList.toggle('bc-composer-expanded');
            btn.innerHTML = footer.classList.contains('bc-composer-expanded') ? '‹ Less' : '••• More';
        };
        footer.appendChild(btn);
    }
    // Inject when composer opens
    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-target="#tagPostBox"], .tag_pub_box_bg_camlve, .tag_pub_box_bg_text')) {
            setTimeout(injectComposerMoreBtn, 200);
        }
    });
    // Also try on modal shown
    document.addEventListener('shown.bs.modal', function(e) {
        if (e.target && e.target.id === 'tagPostBox') { injectComposerMoreBtn(); }
    });
    if (typeof $ !== 'undefined') {
        $(document).on('shown.bs.modal', '#tagPostBox', injectComposerMoreBtn);
    }
})();

/* ---- Part 8: Like Bounce Animation ---- */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.post_react_btn, .wow_react_btn, [data-type="reaction"], .like_post_btn, .wo_like_btn');
    if (btn) {
        var icon = btn.querySelector('svg, img, span');
        var target = icon || btn;
        target.classList.remove('bc-liked-bounce');
        void target.offsetWidth; // reflow to restart animation
        target.classList.add('bc-liked-bounce');
        setTimeout(function() { target.classList.remove('bc-liked-bounce'); }, 500);
    }
}, { passive: true });

/* ---- Part 11: Mobile Bottom Nav Active State ---- */
(function() {
    var nav = document.getElementById('bc-mobile-nav');
    if (!nav) return;
    var items = nav.querySelectorAll('.bc-mob-nav-item');
    var href = window.location.href;
    items.forEach(function(item) {
        var itemHref = item.getAttribute('href') || item.getAttribute('data-href') || '';
        if (itemHref && itemHref !== '#' && href.indexOf(itemHref) !== -1) {
            item.classList.add('bc-mob-active');
        }
    });
    // Home special case
    var homeItem = nav.querySelector('.bc-mob-home');
    if (homeItem && (href.match(/\/index\.php$/) || href.match(/\/$/) || href.match(/link1=home/))) {
        nav.querySelectorAll('.bc-mob-nav-item').forEach(function(i) { i.classList.remove('bc-mob-active'); });
        homeItem.classList.add('bc-mob-active');
    }
})();

})();
