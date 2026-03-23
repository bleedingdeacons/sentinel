/**
 * Sentinel Log Viewer – AJAX refresh, auto-refresh toggle, copy to clipboard
 */
(function () {
    'use strict';

    if (typeof sentinelLogViewer === 'undefined') {
        return;
    }

    var refreshBtn = document.getElementById('sentinel-refresh-log');
    var container  = document.getElementById('sentinel-log-aggregate-container');
    var autoToggle = document.getElementById('sentinel-auto-refresh');

    if (!refreshBtn || !container) {
        return;
    }

    var autoRefreshTimer = null;
    var AUTO_REFRESH_INTERVAL = 15000; // 15 seconds

    /**
     * Perform an AJAX refresh of the aggregate table.
     */
    function doRefresh(silent) {
        if (!silent) {
            refreshBtn.disabled = true;
            container.innerHTML =
                '<div class="sentinel-log-loading">' +
                '<span class="spinner"></span> Refreshing&hellip;' +
                '</div>';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', sentinelLogViewer.url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            refreshBtn.disabled = false;

            if (xhr.status !== 200) {
                if (!silent) {
                    container.innerHTML =
                        '<div class="notice notice-error"><p>Failed to refresh log data.</p></div>';
                }
                return;
            }

            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success && response.data && response.data.html) {
                    container.innerHTML = response.data.html;
                    bindCopyButtons();
                } else if (!silent) {
                    container.innerHTML =
                        '<div class="notice notice-error"><p>Unexpected response.</p></div>';
                }
            } catch (e) {
                if (!silent) {
                    container.innerHTML =
                        '<div class="notice notice-error"><p>Failed to parse response.</p></div>';
                }
            }
        };
        xhr.send(
            'action=' + encodeURIComponent(sentinelLogViewer.action) +
            '&nonce=' + encodeURIComponent(sentinelLogViewer.nonce)
        );
    }

    /* Manual refresh button */
    refreshBtn.addEventListener('click', function () {
        doRefresh(false);
    });

    /* Auto-refresh toggle */
    if (autoToggle) {
        autoToggle.addEventListener('change', function () {
            if (autoToggle.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        autoRefreshTimer = setInterval(function () {
            doRefresh(true);
        }, AUTO_REFRESH_INTERVAL);
    }

    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    }

    /**
     * Copy entry data to clipboard.
     * Line 1: level \t channel \t count \t last_seen
     * Line 2: message
     */
    function handleCopy(btn) {
        var row = btn.closest('.sentinel-log-row-header');
        if (!row) return;

        var level    = row.getAttribute('data-level')     || '';
        var channel  = row.getAttribute('data-channel')   || '';
        var count    = row.getAttribute('data-count')     || '';
        var lastSeen = row.getAttribute('data-last-seen') || '';
        var message  = row.getAttribute('data-message')   || '';

        var text = level + '\t' + channel + '\t' + count + '\t' + lastSeen + '\n' + message;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(btn);
            });
        } else {
            // Fallback
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCopied(btn);
        }
    }

    function showCopied(btn) {
        var icon = btn.querySelector('.dashicons');
        if (icon) {
            icon.className = 'dashicons dashicons-yes';
            setTimeout(function () {
                icon.className = 'dashicons dashicons-clipboard';
            }, 1200);
        }
    }

    /**
     * Bind click handlers to all copy buttons in the container.
     * Called on page load and after each AJAX refresh.
     */
    function bindCopyButtons() {
        var buttons = container.querySelectorAll('.sentinel-copy-btn');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                handleCopy(btn);
            });
        });
    }

    // Initial bind
    bindCopyButtons();
})();
