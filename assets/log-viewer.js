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
                    bindCopyRows();
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
    var STORAGE_KEY = 'sentinel_auto_refresh';

    /* Restore saved state */
    if (autoToggle) {
        try {
            if (localStorage.getItem(STORAGE_KEY) === '1') {
                autoToggle.checked = true;
                startAutoRefresh();
            }
        } catch (e) {}

        autoToggle.addEventListener('change', function () {
            try {
                localStorage.setItem(STORAGE_KEY, autoToggle.checked ? '1' : '0');
            } catch (e) {}

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
     *
     * If Ctrl (or Cmd) is held, append to an internal buffer and write
     * all accumulated entries to clipboard. Plain click resets the buffer.
     */
    var clipBuffer = '';

    function handleCopy(group, e) {
        var level    = group.getAttribute('data-level')     || '';
        var channel  = group.getAttribute('data-channel')   || '';
        var count    = group.getAttribute('data-count')     || '';
        var lastSeen = group.getAttribute('data-last-seen') || '';
        var message  = group.getAttribute('data-message')   || '';

        var newText = level + '\t' + channel + '\t' + count + '\t' + lastSeen + '\n' + message;
        var append  = e && (e.ctrlKey || e.metaKey);

        if (append && clipBuffer) {
            clipBuffer = clipBuffer + '\n' + newText;
        } else {
            clipBuffer = newText;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(clipBuffer).then(function () {
                showCopied(group, append);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = clipBuffer;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCopied(group, append);
        }
    }

    /* Toast element – created once, reused */
    var toast = document.createElement('div');
    toast.className = 'sentinel-copy-toast';
    toast.textContent = 'Copied to clipboard';
    document.body.appendChild(toast);
    var toastTimer = null;
    var copyCount = 0;

    function showCopied(group, appended) {
        group.classList.add('sentinel-copied');
        setTimeout(function () {
            group.classList.remove('sentinel-copied');
        }, 1200);

        /* Track count — reset on plain copy, increment on append */
        if (appended) {
            copyCount++;
        } else {
            copyCount = 1;
        }

        /* Show toast */
        if (copyCount > 1) {
            toast.textContent = 'Copied ' + copyCount + ' items to clipboard';
        } else {
            toast.textContent = 'Copied to clipboard';
        }
        if (toastTimer) {
            clearTimeout(toastTimer);
        }
        toast.classList.add('sentinel-toast-visible');
        toastTimer = setTimeout(function () {
            toast.classList.remove('sentinel-toast-visible');
            toastTimer = null;
        }, 1500);
    }

    /**
     * Bind click handlers to all log groups in the container.
     * Called on page load and after each AJAX refresh.
     */
    function bindCopyRows() {
        var groups = container.querySelectorAll('.sentinel-log-group');
        groups.forEach(function (group) {
            group.addEventListener('click', function (e) {
                e.preventDefault();
                handleCopy(group, e);
            });
        });
    }

    // Initial bind
    bindCopyRows();
})();
