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
                    } catch(e) { console.warn('[Bitchat] Script injection error:', e.message); }
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
    var html =
        '<span class="bc-ticker-label">' + label + '</span>' +
        '<span class="bc-ticker-price">' + price + '</span>' +
        '<span class="bc-ticker-change ' + cls + '">' + arrow + ' ' + Math.abs(change).toFixed(2) + '%</span>';
    el.innerHTML = html;

    // Sync to cloned element if exists
    var cloneId = el.id + '-clone';
    var cloneEl = document.getElementById(cloneId);
    if (cloneEl) cloneEl.innerHTML = html;
}

function fetchCrypto() {
    var strip = document.getElementById('bc-market-strip');
    if (!strip) return;
    var btcEl = document.getElementById('bc-tick-btc');
    var ethEl = document.getElementById('bc-tick-eth');
    var bnbEl = document.getElementById('bc-tick-bnb');
    var xrpEl = document.getElementById('bc-tick-xrp');
    var solEl = document.getElementById('bc-tick-sol');
    var trxEl = document.getElementById('bc-tick-trx');
    if (!btcEl || !ethEl) return;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,binancecoin,ripple,solana,tron&vs_currencies=usd&include_24hr_change=true', true);
    xhr.timeout = 10000;
    xhr.ontimeout = function() { console.warn('[Bitchat] Crypto ticker request timed out'); };
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.bitcoin) {
                    var btcP = '$' + Math.round(d.bitcoin.usd).toLocaleString('en-US');
                    var btcC = d.bitcoin.usd_24h_change || 0;
                    updateTicker(btcEl, 'BTC', btcP, btcC, btcC >= 0);
                }
                if (d.ethereum) {
                    var ethP = '$' + Math.round(d.ethereum.usd).toLocaleString('en-US');
                    var ethC = d.ethereum.usd_24h_change || 0;
                    updateTicker(ethEl, 'ETH', ethP, ethC, ethC >= 0);
                }
                if (d.binancecoin && bnbEl) {
                    var bnbP = '$' + Math.round(d.binancecoin.usd).toLocaleString('en-US');
                    var bnbC = d.binancecoin.usd_24h_change || 0;
                    updateTicker(bnbEl, 'BNB', bnbP, bnbC, bnbC >= 0);
                }
                if (d.ripple && xrpEl) {
                    var xrpP = '$' + d.ripple.usd.toFixed(2);
                    var xrpC = d.ripple.usd_24h_change || 0;
                    updateTicker(xrpEl, 'XRP', xrpP, xrpC, xrpC >= 0);
                }
                if (d.solana && solEl) {
                    var solP = '$' + Math.round(d.solana.usd).toLocaleString('en-US');
                    var solC = d.solana.usd_24h_change || 0;
                    updateTicker(solEl, 'SOL', solP, solC, solC >= 0);
                }
                if (d.tron && trxEl) {
                    var trxP = '$' + d.tron.usd.toFixed(3);
                    var trxC = d.tron.usd_24h_change || 0;
                    updateTicker(trxEl, 'TRX', trxP, trxC, trxC >= 0);
                }
            } catch(e) { console.warn('[Bitchat] Crypto ticker parse error:', e.message); }
        }
    };
    xhr.send();
}

function fetchTRDC() {
    var trdcEl = document.getElementById('bc-tick-trdc');
    if (!trdcEl) return;

    var poolAddress = '0x7b57fa13cca5093f5d724823d58503dfd02ff07c';
    var apiUrl = 'https://api.geckoterminal.com/api/v2/networks/bsc/pools/' + poolAddress;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', apiUrl, true);
    xhr.timeout = 10000;
    xhr.ontimeout = function() {
        console.warn('[Bitchat] TRDC ticker request timed out');
        if (trdcEl) trdcEl.style.display = 'none';
        var prev = trdcEl.previousElementSibling;
        if (prev && prev.classList.contains('bc-ticker-sep')) prev.style.display = 'none';
    };
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                var pool = d.data.attributes;
                var priceUSD = parseFloat(pool.base_token_price_usd);
                var change24h = parseFloat(pool.price_change_percentage.h24) || 0;

                var priceStr;
                if (priceUSD >= 1) {
                    priceStr = '$' + priceUSD.toFixed(2);
                } else if (priceUSD >= 0.01) {
                    priceStr = '$' + priceUSD.toFixed(4);
                } else {
                    priceStr = '$' + priceUSD.toFixed(6);
                }

                updateTicker(trdcEl, 'TRDC', priceStr, change24h, change24h >= 0);
            } catch(e) {
                console.warn('[Bitchat] TRDC ticker parse error:', e.message);
                if (trdcEl) trdcEl.style.display = 'none';
                var prev = trdcEl.previousElementSibling;
                if (prev && prev.classList.contains('bc-ticker-sep')) prev.style.display = 'none';
            }
        }
    };
    xhr.onerror = function() {
        if (trdcEl) trdcEl.style.display = 'none';
        var prev = trdcEl.previousElementSibling;
        if (prev && prev.classList.contains('bc-ticker-sep')) prev.style.display = 'none';
    };
    xhr.send();
}

function fetchIndices() {
    // Yahoo Finance blocks CORS from browsers — hide NIFTY/SENSEX elements
    var ids = ['bc-tick-nifty', 'bc-tick-sensex'];
    ids.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.style.display = 'none';
            var prev = el.previousElementSibling;
            if (prev && prev.classList.contains('bc-ticker-sep')) prev.style.display = 'none';
            var cloneEl = document.getElementById(id + '-clone');
            if (cloneEl) {
                cloneEl.style.display = 'none';
                var prevClone = cloneEl.previousElementSibling;
                if (prevClone && prevClone.classList.contains('bc-ticker-sep')) prevClone.style.display = 'none';
            }
        }
    });
}

var marketStrip = document.getElementById('bc-market-strip');
if (marketStrip) {
    fetchCrypto();
    fetchTRDC();
    fetchIndices(); // runs once — just hides NIFTY/SENSEX (no CORS-friendly API)
    var _cryptoInterval = setInterval(fetchCrypto, 60000);
    var _trdcInterval = setInterval(fetchTRDC, 60000);
    window.addEventListener('beforeunload', function() {
        clearInterval(_cryptoInterval);
        clearInterval(_trdcInterval);
    });
}

/* ---- Part 3: Install App Popup ---- */
/* Shows for 5s, hides, repeats every 5min, max 5 times.
   If user clicks Install or X → never shows again until logout.
   Uses sessionStorage (clears on logout/tab close).
   Random bg + font color from 15 presets each appearance.
   Also captures beforeinstallprompt for native PWA install. */
document.addEventListener('DOMContentLoaded', function() {
    var popup = document.getElementById('bc-install-popup');
    if (!popup) return;

    /* Capture native PWA install prompt */
    var deferredPWAPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPWAPrompt = e;
    });

    /* 15 vibrant color pairs: [background, text] */
    var colorPairs = [
        ['#FF6B6B', '#FFFFFF'], /* Coral red */
        ['#4ECDC4', '#FFFFFF'], /* Teal */
        ['#FFE66D', '#1a1a2e'], /* Sunny yellow */
        ['#6C5CFF', '#FFFFFF'], /* Brand purple */
        ['#FF9F43', '#FFFFFF'], /* Warm orange */
        ['#00D2D3', '#1a1a2e'], /* Cyan */
        ['#EE5A24', '#FFFFFF'], /* Burnt orange */
        ['#0ABDE3', '#FFFFFF'], /* Sky blue */
        ['#10AC84', '#FFFFFF'], /* Emerald */
        ['#F368E0', '#FFFFFF'], /* Pink */
        ['#1B9CFC', '#FFFFFF'], /* Bright blue */
        ['#FF6348', '#FFFFFF'], /* Tomato */
        ['#2ED573', '#1a1a2e'], /* Lime green */
        ['#A55EEA', '#FFFFFF'], /* Amethyst */
        ['#FD7272', '#FFFFFF']  /* Salmon */
    ];
    var usedColors = [];

    function pickColor() {
        if (usedColors.length >= colorPairs.length) usedColors = [];
        var idx;
        do { idx = Math.floor(Math.random() * colorPairs.length); }
        while (usedColors.indexOf(idx) !== -1);
        usedColors.push(idx);
        return colorPairs[idx];
    }

    /* sessionStorage key — clears on logout (session end) */
    var KEY = 'bc_install_v2';
    var COUNT_KEY = 'bc_install_v2_count';

    if (sessionStorage.getItem(KEY)) return;

    var showCount = parseInt(sessionStorage.getItem(COUNT_KEY) || '0', 10);
    var MAX_SHOWS = 5;
    var SHOW_DURATION = 5000;   /* 5 seconds visible */
    var INTERVAL = 300000;      /* 5 minutes between shows */
    var hideTimer = null;

    function applyColors() {
        var pair = pickColor();
        popup.style.background = pair[0];
        popup.style.color = pair[1];
        popup.querySelector('.bc-install-btn').style.color = pair[0];
        popup.querySelector('.bc-install-btn').style.background = pair[1];
        popup.querySelector('.bc-install-close').style.color = pair[1];
        popup.querySelector('.bc-install-icon').style.color = pair[1];
    }

    function showPopup() {
        if (sessionStorage.getItem(KEY)) return;
        if (showCount >= MAX_SHOWS) return;

        showCount++;
        sessionStorage.setItem(COUNT_KEY, String(showCount));

        applyColors();
        popup.style.display = 'flex';
        /* Let browser paint, then trigger entrance animation */
        requestAnimationFrame(function() {
            popup.classList.add('bc-install-visible');
        });

        /* Full 5 seconds AFTER entrance animation (300ms) completes */
        hideTimer = setTimeout(function() {
            popup.classList.remove('bc-install-visible');
            setTimeout(function() { popup.style.display = 'none'; }, 300);

            /* Schedule next show if under max */
            if (showCount < MAX_SHOWS && !sessionStorage.getItem(KEY)) {
                setTimeout(showPopup, INTERVAL);
            }
        }, 300 + SHOW_DURATION);
    }

    function dismiss() {
        sessionStorage.setItem(KEY, '1');
        if (hideTimer) clearTimeout(hideTimer);
        popup.classList.remove('bc-install-visible');
        setTimeout(function() { popup.style.display = 'none'; }, 300);
    }

    /* Install button — triggers PWA install + OneSignal push */
    document.getElementById('bc-install-btn').addEventListener('click', function() {
        dismiss();
        /* Trigger native PWA Add to Home Screen if available */
        if (deferredPWAPrompt) {
            deferredPWAPrompt.prompt();
            deferredPWAPrompt.userChoice.then(function() { deferredPWAPrompt = null; });
        }
        /* Trigger OneSignal push notifications if available */
        if (window.OneSignal) {
            OneSignal.push(function() { OneSignal.registerForPushNotifications(); });
        }
    });

    /* Close X button */
    document.getElementById('bc-install-close').addEventListener('click', dismiss);

    /* First show after 3 seconds (let page settle) */
    setTimeout(showPopup, 3000);
});

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

/* ---- Part 11: Mobile Bottom Nav — REMOVED ---- */
/* #bc-mobile-nav is hidden (display:none). Using WoWonder native bottom bar instead. */

})();

/* ==========================================================================
   FIX-4: Post Composer Modal
   Moves #tagPostBox to <body> to escape CSS containment contexts.
   Prevents double-open via show.bs.modal gate.
   NO inline style forcing — CSS rules (#tagPostBox.show) handle visibility.
   Inline !important styles were causing the "double popup" artifact by
   persisting during Bootstrap's fade-out transition.
   ========================================================================== */
(function() {
    if (typeof $ === 'undefined' || window.__FIX4_LOADED) return;
    window.__FIX4_LOADED = true;

    var _isOpen = false;

    // Dedupe + move #tagPostBox to <body>
    function moveToBody() {
        var all = document.querySelectorAll('#tagPostBox');
        for (var i = 1; i < all.length; i++) all[i].parentNode.removeChild(all[i]);
        var $tp = $('#tagPostBox');
        if ($tp.length && !$tp.parent().is('body')) $tp.detach().appendTo('body');
    }

    $(function() { moveToBody(); });
    $(document).ajaxComplete(moveToBody);

    // Gate: prevent double-open
    $(document).on('show.bs.modal', '#tagPostBox', function(e) {
        if (_isOpen) {
            e.preventDefault();
            return false;
        }
        _isOpen = true;
    });

    // After shown: only clean extra backdrops (no inline styles!)
    $(document).on('shown.bs.modal', '#tagPostBox', function() {
        var bds = document.querySelectorAll('.modal-backdrop');
        for (var i = 1; i < bds.length; i++) bds[i].parentNode.removeChild(bds[i]);
    });

    // Reset on hide
    $(document).on('hidden.bs.modal', '#tagPostBox', function() {
        _isOpen = false;
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
    });

    // Click overlay to close
    $(document).on('click', '#tagPostBox', function(e) {
        if (e.target === this) {
            $(this).modal('hide');
        }
    });
})();
