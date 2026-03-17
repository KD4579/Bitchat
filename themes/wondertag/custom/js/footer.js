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
    // --- Part 0a: Tag native app for CSS hiding ---
    if (navigator.userAgent.indexOf('BitchatApp') !== -1) {
        document.documentElement.classList.add('bc-native-app');
    }

    // --- Part 0a2: Lightbox swipe navigation (native app only) ---
    if (document.documentElement.classList.contains('bc-native-app')) {
        var _lbStartX = 0, _lbStartY = 0;
        $(document).on('touchstart', '.wo_imagecombo_lbox', function(e) {
            var t = e.originalEvent.touches[0];
            _lbStartX = t.clientX;
            _lbStartY = t.clientY;
        });
        $(document).on('touchend', '.wo_imagecombo_lbox', function(e) {
            var t = e.originalEvent.changedTouches[0];
            var dx = t.clientX - _lbStartX;
            var dy = t.clientY - _lbStartY;
            if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx)) return;
            var id = parseInt($(this).attr('data-post-id'));
            if (!id) return;
            if (dx < 0 && typeof Wo_NextPicture === 'function') {
                Wo_NextPicture(id);
            } else if (dx > 0 && typeof Wo_PreviousPicture === 'function') {
                Wo_PreviousPicture(id);
            }
        });
    }

    // --- Part 0b: Mobile search overlay close handler ---
    $(document).on('click', '.tag_toggle_search', function() {
        $('.search-container').removeClass('bc-search-open');
    });

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
                                if (typeof Wo_FinishBar === 'function') Wo_FinishBar();
                            }
                        }, 5000);
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
            document.body.classList.add('night_mode');
        } else {
            document.documentElement.classList.remove('bc-dark-mode');
            document.body.classList.remove('night_mode');
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

    // DexScreener has CORS enabled — use as primary
    var dexScreenerUrl = 'https://api.dexscreener.com/latest/dex/pairs/bsc/0x7b57fa13cca5093f5d724823d58503dfd02ff07c';
    // GeckoTerminal does NOT have CORS — use PHP proxy as fallback
    var geckoProxyUrl = Wo_Ajax_Requests_File() + '?f=trdc_price';

    function formatPrice(priceUSD) {
        if (priceUSD >= 1) return '$' + priceUSD.toFixed(2);
        if (priceUSD >= 0.01) return '$' + priceUSD.toFixed(4);
        if (priceUSD >= 0.0001) return '$' + priceUSD.toFixed(6);
        return '$' + priceUSD.toFixed(8);
    }

    function showTicker(priceUSD, change24h) {
        trdcEl.style.display = '';
        var prevSep = trdcEl.previousElementSibling;
        if (prevSep && prevSep.classList.contains('bc-ticker-sep')) prevSep.style.display = '';
        updateTicker(trdcEl, 'TRDC', formatPrice(priceUSD), change24h, change24h >= 0);
    }

    function fetchFromGeckoProxy() {
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', geckoProxyUrl, true);
        xhr2.timeout = 10000;
        xhr2.onload = function() {
            if (xhr2.status === 200) {
                try {
                    var d = JSON.parse(xhr2.responseText);
                    if (d.price && d.price > 0) {
                        showTicker(d.price, d.change24h || 0);
                    }
                } catch(e) {}
            }
        };
        xhr2.onerror = function() {};
        xhr2.send();
    }

    // Primary: DexScreener (has CORS)
    var xhr = new XMLHttpRequest();
    xhr.open('GET', dexScreenerUrl, true);
    xhr.timeout = 10000;
    xhr.ontimeout = function() {
        fetchFromGeckoProxy();
    };
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                var pair = d.pair || (d.pairs && d.pairs[0]);
                if (!pair) { fetchFromGeckoProxy(); return; }
                var priceUSD = parseFloat(pair.priceUsd);
                if (isNaN(priceUSD) || priceUSD <= 0) { fetchFromGeckoProxy(); return; }
                var change24h = (pair.priceChange && pair.priceChange.h24 != null) ? parseFloat(pair.priceChange.h24) : 0;
                showTicker(priceUSD, change24h);
            } catch(e) {
                fetchFromGeckoProxy();
            }
        } else {
            fetchFromGeckoProxy();
        }
    };
    xhr.onerror = function() {
        fetchFromGeckoProxy();
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
   Also captures beforeinstallprompt for native PWA install.
   SKIPPED entirely when running inside the Bitchat Android app. */
document.addEventListener('DOMContentLoaded', function() {
    var popup = document.getElementById('bc-install-popup');
    if (!popup) return;

    /* Don't show install popup if already inside the app */
    if (navigator.userAgent.indexOf('BitchatApp') !== -1) return;

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

    /* Show styled install guide modal for non-PWA browsers */
    function showInstallGuide() {
        if (document.getElementById('bc-install-guide-modal')) return;

        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        var isAndroid = /Android/.test(navigator.userAgent);
        var stepIcon, step1, step2;

        if (isIOS) {
            stepIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
            step1 = 'Tap the <b>Share</b> button <span style="font-size:18px">&#9757;</span> at the bottom of Safari';
            step2 = 'Scroll down and tap <b>"Add to Home Screen"</b>';
        } else if (isAndroid) {
            stepIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
            step1 = 'Tap the <b>menu</b> button <b>&#8942;</b> in your browser\'s top-right corner';
            step2 = 'Tap <b>"Add to Home Screen"</b> or <b>"Install App"</b>';
        } else {
            stepIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
            step1 = 'Click the <b>browser menu</b> (&#8942; or &#8943;) in the top-right corner';
            step2 = 'Select <b>"Install Bitchat"</b> or <b>"Add to Home Screen"</b>';
        }

        var overlay = document.createElement('div');
        overlay.id = 'bc-install-guide-modal';
        overlay.innerHTML =
            '<div class="bc-ig-card">' +
                '<button class="bc-ig-close" aria-label="Close">&times;</button>' +
                '<div class="bc-ig-icon">' + stepIcon + '</div>' +
                '<h3 class="bc-ig-title">Install Bitchat</h3>' +
                '<p class="bc-ig-subtitle">Add Bitchat to your home screen for a faster, app-like experience.</p>' +
                '<div class="bc-ig-steps">' +
                    '<div class="bc-ig-step"><span class="bc-ig-num">1</span><span>' + step1 + '</span></div>' +
                    '<div class="bc-ig-step"><span class="bc-ig-num">2</span><span>' + step2 + '</span></div>' +
                '</div>' +
                '<button class="bc-ig-done">Got it</button>' +
            '</div>';

        document.body.appendChild(overlay);

        /* Close handlers */
        function closeGuide() {
            overlay.classList.add('bc-ig-out');
            setTimeout(function() { overlay.remove(); }, 250);
        }
        overlay.querySelector('.bc-ig-close').addEventListener('click', closeGuide);
        overlay.querySelector('.bc-ig-done').addEventListener('click', closeGuide);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeGuide();
        });

        /* Entrance animation */
        requestAnimationFrame(function() { overlay.classList.add('bc-ig-in'); });
    }

    /* Android install choice modal — PWA-first approach with APK fallback */
    function showAndroidInstallChoice() {
        if (document.getElementById('bc-android-install-modal')) return;

        var overlay = document.createElement('div');
        overlay.id = 'bc-android-install-modal';
        overlay.innerHTML =
            '<div class="bc-ig-card">' +
                '<button class="bc-ig-close" aria-label="Close">&times;</button>' +
                '<div class="bc-ig-icon"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div>' +
                '<h3 class="bc-ig-title">Install Bitchat</h3>' +
                '<p class="bc-ig-subtitle">Choose your preferred install method:</p>' +
                '<div style="display:flex;flex-direction:column;gap:10px;margin-top:16px;">' +
                    '<button class="bc-ig-done" id="bc-android-pwa-btn" style="background:#10b981;border:none;color:#fff;padding:14px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>' +
                        'Add to Home Screen (Recommended)' +
                    '</button>' +
                    '<button class="bc-ig-done" id="bc-android-apk-btn" style="background:transparent;border:2px solid #ddd;color:#333;padding:14px;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
                        'Download Android App (APK)' +
                    '</button>' +
                    '<p style="font-size:11px;color:#888;text-align:center;margin:4px 0 0;">Home Screen install is instant, no downloads needed, and no security warnings.</p>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        function closeModal() {
            overlay.classList.add('bc-ig-out');
            setTimeout(function() { overlay.remove(); }, 250);
        }

        overlay.querySelector('.bc-ig-close').addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });

        /* PWA button — show browser's Add to Home Screen guide */
        overlay.querySelector('#bc-android-pwa-btn').addEventListener('click', function() {
            closeModal();
            showInstallGuide();
        });

        /* APK button — download with warning */
        overlay.querySelector('#bc-android-apk-btn').addEventListener('click', function() {
            closeModal();
            /* Brief warning then download */
            var warn = document.createElement('div');
            warn.id = 'bc-apk-warn';
            warn.innerHTML =
                '<div class="bc-ig-card" style="max-width:340px;">' +
                    '<h3 class="bc-ig-title" style="font-size:17px;">Download APK</h3>' +
                    '<div class="bc-ig-steps">' +
                        '<div class="bc-ig-step"><span class="bc-ig-num">1</span><span>Tap <b>Download</b> below</span></div>' +
                        '<div class="bc-ig-step"><span class="bc-ig-num">2</span><span>If Chrome warns, tap <b>"Download anyway"</b></span></div>' +
                        '<div class="bc-ig-step"><span class="bc-ig-num">3</span><span>Open the downloaded file and tap <b>Install</b></span></div>' +
                        '<div class="bc-ig-step"><span class="bc-ig-num">4</span><span>If asked, enable <b>"Install unknown apps"</b> for Chrome</span></div>' +
                    '</div>' +
                    '<button class="bc-ig-done" id="bc-apk-download-go" style="background:#6C5CFF;color:#fff;width:100%;margin-top:12px;">Download Bitchat App (2.5 MB)</button>' +
                    '<button class="bc-ig-done" style="background:transparent;border:1px solid #ddd;color:#666;width:100%;margin-top:8px;" id="bc-apk-cancel">Cancel</button>' +
                '</div>';
            document.body.appendChild(warn);
            requestAnimationFrame(function() { warn.classList.add('bc-ig-in'); });

            warn.querySelector('#bc-apk-download-go').addEventListener('click', function() {
                window.location.href = '/upload/Bitchat-v1.0.3.apk';
                warn.classList.add('bc-ig-out');
                setTimeout(function() { warn.remove(); }, 250);
            });
            warn.querySelector('#bc-apk-cancel').addEventListener('click', function() {
                warn.classList.add('bc-ig-out');
                setTimeout(function() { warn.remove(); }, 250);
            });
            warn.addEventListener('click', function(e) {
                if (e.target === warn) { warn.classList.add('bc-ig-out'); setTimeout(function() { warn.remove(); }, 250); }
            });
        });

        requestAnimationFrame(function() { overlay.classList.add('bc-ig-in'); });
    }

    /* Install button — Android gets PWA first (no warnings), APK as fallback.
       iOS gets PWA, desktop gets PWA. */
    document.getElementById('bc-install-btn').addEventListener('click', function() {
        dismiss();
        var ua = navigator.userAgent.toLowerCase();
        var isAndroid = ua.indexOf('android') > -1;

        if (isAndroid && deferredPWAPrompt) {
            /* Best: native PWA install — no warnings, no sideloading */
            deferredPWAPrompt.prompt();
            deferredPWAPrompt.userChoice.then(function(choice) {
                deferredPWAPrompt = null;
                if (choice.outcome === 'dismissed') {
                    /* User declined PWA — offer APK as fallback */
                    showAndroidInstallChoice();
                }
            });
        } else if (isAndroid) {
            /* No PWA prompt available — show choice modal */
            showAndroidInstallChoice();
        } else if (deferredPWAPrompt) {
            /* Native PWA Add to Home Screen */
            deferredPWAPrompt.prompt();
            deferredPWAPrompt.userChoice.then(function() { deferredPWAPrompt = null; });
        } else {
            /* Fallback: show styled install guide (iOS, desktop) */
            showInstallGuide();
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

/* ---- Part 4: App Update Popup ---- */
/* Checks if the installed app version is outdated and shows a
   persistent update banner. Version is set server-side below and
   compared to the version string in the app's user agent.
   Shows every page load until user updates. */
(function() {
    var LATEST_VERSION = '1.0.3'; /* ← bump this when you upload a new APK */
    var APK_URL = '/upload/Bitchat-v1.0.3.apk'; /* ← update filename too */

    var ua = navigator.userAgent;
    var match = ua.match(/BitchatApp\/([\d.]+)/);
    if (!match) return; /* not in the app */

    var installedVersion = match[1];
    if (installedVersion === LATEST_VERSION) return; /* up to date */

    /* Compare versions: return true if installed < latest */
    function isOlder(installed, latest) {
        var a = installed.split('.').map(Number);
        var b = latest.split('.').map(Number);
        for (var i = 0; i < Math.max(a.length, b.length); i++) {
            var x = a[i] || 0, y = b[i] || 0;
            if (x < y) return true;
            if (x > y) return false;
        }
        return false;
    }

    if (!isOlder(installedVersion, LATEST_VERSION)) return;

    /* Skip if user dismissed this session (reappears on refresh/relogin) */
    if (sessionStorage.getItem('bc_update_dismissed') === LATEST_VERSION) return;

    /* Build update banner */
    var banner = document.createElement('div');
    banner.id = 'bc-update-banner';
    banner.innerHTML =
        '<div class="bc-upd-content">' +
            '<div class="bc-upd-icon">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
            '</div>' +
            '<div class="bc-upd-text">' +
                '<strong>Update Available</strong>' +
                '<span>v' + LATEST_VERSION + ' is ready. You have v' + installedVersion + '</span>' +
            '</div>' +
            '<button class="bc-upd-btn" id="bc-update-btn">Update</button>' +
            '<button class="bc-upd-close" id="bc-update-close">&times;</button>' +
        '</div>';
    document.body.appendChild(banner);

    /* Show with animation */
    requestAnimationFrame(function() {
        requestAnimationFrame(function() { banner.classList.add('bc-upd-visible'); });
    });

    document.getElementById('bc-update-btn').addEventListener('click', function() {
        window.location.href = APK_URL;
    });

    document.getElementById('bc-update-close').addEventListener('click', function() {
        banner.classList.remove('bc-upd-visible');
        setTimeout(function() { banner.remove(); }, 300);
        sessionStorage.setItem('bc_update_dismissed', LATEST_VERSION);
    });
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
            if (!window._bcToolsLoaded && !window._bcToolsLoading) {
                bcLoadAdvancedTools();
            } else if (window._bcToolsLoaded) {
                footer.classList.toggle('bc-composer-expanded');
                btn.innerHTML = footer.classList.contains('bc-composer-expanded') ? '\u2039 Less' : '\u2022\u2022\u2022 More';
            }
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

/* ---- Part 9: Settings Sidebar Toggle Fix ---- */
/* The mobile back button (.setting_navigation) in settings pages calls
   fadeIn(50) on .tag_sett_sidebar. On screens ≤992px, the CSS sets
   display: none which should lose to jQuery's inline display: block,
   but in practice it can fail. This handler reinforces with !important. */
document.addEventListener('click', function(e) {
    if (e.target.closest('.setting_navigation')) {
        var sidebar = document.querySelector('.tag_sett_sidebar');
        if (sidebar) {
            sidebar.style.setProperty('display', 'block', 'important');
            sidebar.style.setProperty('opacity', '1', 'important');
        }
    }
}, false);

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

/* =========================================================================
   Part 5: Sidebar "Load More" for native app — show 5 items at a time
   ========================================================================= */
(function() {
    if (!document.documentElement.classList.contains('bc-native-app')) return;

    var ITEMS_PER_PAGE = 5;

    function initLoadMore() {
        var sections = document.querySelectorAll('.tag_sidebar_section');
        sections.forEach(function(section) {
            if (section.querySelector('.bc-sidebar-load-more')) return; // already init
            var items = section.querySelectorAll(':scope > a');
            if (items.length <= ITEMS_PER_PAGE) return;

            // Hide items beyond the first 5
            for (var i = ITEMS_PER_PAGE; i < items.length; i++) {
                items[i].style.display = 'none';
                items[i].setAttribute('data-bc-hidden', '1');
            }

            var btn = document.createElement('button');
            btn.className = 'bc-sidebar-load-more';
            btn.textContent = 'Load More...';
            btn.style.display = 'block';
            btn.addEventListener('click', function() {
                var hidden = section.querySelectorAll('a[data-bc-hidden="1"]');
                var count = 0;
                for (var j = 0; j < hidden.length && count < ITEMS_PER_PAGE; j++) {
                    hidden[j].style.display = '';
                    hidden[j].removeAttribute('data-bc-hidden');
                    count++;
                }
                if (section.querySelectorAll('a[data-bc-hidden="1"]').length === 0) {
                    btn.style.display = 'none';
                }
            });
            section.appendChild(btn);
        });
    }

    // Run on load and after AJAX navigation
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLoadMore);
    } else {
        initLoadMore();
    }
    $(document).ajaxComplete(function() { setTimeout(initLoadMore, 300); });
})();
