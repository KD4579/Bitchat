/**
 * UI-12: Skeleton Feed Loader
 * Shows shimmer placeholders instantly, then fades out when real content loads
 */
(function() {
    'use strict';

    const BC_SKELETON = {
        // Build a single skeleton card HTML
        card: function(lines) {
            lines = lines || 3;
            let linesHtml = '';
            for (let i = 0; i < lines; i++) {
                const w = i === 0 ? '60%' : (i === lines - 1 ? '40%' : '85%');
                linesHtml += `<div class="bc-skel-line" style="width:${w}"></div>`;
            }
            return `
            <div class="bc-skel-card" aria-hidden="true">
                <div class="bc-skel-header">
                    <div class="bc-skel-avatar"></div>
                    <div class="bc-skel-meta">
                        <div class="bc-skel-line" style="width:40%"></div>
                        <div class="bc-skel-line" style="width:25%;height:10px;margin-top:6px"></div>
                    </div>
                </div>
                <div class="bc-skel-body">${linesHtml}</div>
                <div class="bc-skel-image"></div>
                <div class="bc-skel-actions">
                    <div class="bc-skel-action"></div>
                    <div class="bc-skel-action"></div>
                    <div class="bc-skel-action"></div>
                </div>
            </div>`;
        },

        // Build the skeleton feed overlay (3 cards)
        build: function(count) {
            count = count || 3;
            let html = '<div class="bc-skel-feed" id="bc-skel-overlay">';
            for (let i = 0; i < count; i++) {
                html += this.card(i === 1 ? 4 : 3);
            }
            html += '</div>';
            return html;
        },

        // Show skeleton over feed container
        show: function(targetId, count) {
            const target = document.getElementById(targetId || 'posts-laoded');
            if (!target) return;
            // Only insert if no real posts yet
            if (target.querySelector('.bc-skel-feed')) return;
            const skel = document.createElement('div');
            skel.innerHTML = this.build(count || 3);
            target.prepend(skel.firstChild);
        },

        // Fade out and remove skeleton
        hide: function() {
            const skel = document.getElementById('bc-skel-overlay');
            if (!skel) return;
            skel.style.opacity = '0';
            skel.style.transition = 'opacity .3s ease';
            setTimeout(function() {
                if (skel.parentNode) skel.parentNode.removeChild(skel);
            }, 320);
        },

        // Auto: show on tab switch, expose globally for tab function to use
        init: function() {
            // Expose hide so bcFeedTab can call it after AJAX finishes
            window.BC_SKELETON = this;

            // Patch bcFeedTab if it exists to use proper skeleton
            const origFeedTab = window.bcFeedTab;
            if (typeof origFeedTab === 'function') {
                window.bcFeedTab = (btn, tab) => {
                    const postsEl = document.getElementById('posts-laoded');
                    if (postsEl) {
                        postsEl.innerHTML = BC_SKELETON.build(3);
                    }
                    // Defer to original after skeleton is placed
                    origFeedTab(btn, tab);
                };
            }
        }
    };

    // Init when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { BC_SKELETON.init(); });
    } else {
        BC_SKELETON.init();
    }

    window.BC_SKELETON = BC_SKELETON;
})();
