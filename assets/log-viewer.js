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
     * Following lines (if context present): the pretty-printed context
     *
     * If Ctrl (or Cmd) is held, append to an internal buffer and write
     * all accumulated entries to clipboard. Plain click resets the buffer.
     */
    var clipBuffer = '';
    var clipBufferClearTimer = null;
    var CLIP_BUFFER_CLEAR_DELAY = 5000; // 5 seconds

    function handleCopy(group, e) {
        var level    = group.getAttribute('data-level')     || '';
        var channel  = group.getAttribute('data-channel')   || '';
        var count    = group.getAttribute('data-count')     || '';
        var lastSeen = group.getAttribute('data-last-seen') || '';
        var message  = group.getAttribute('data-message')   || '';
        var context  = group.getAttribute('data-context')   || '';

        var newText = level + '\t' + channel + '\t' + count + '\t' + lastSeen + '\n' + message;
        if (context) {
            newText += '\n' + context;
        }
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

        /* When Ctrl/Cmd-clicking, schedule a clear of the internal clip buffer
           after 15 seconds. Each new Ctrl/Cmd-click resets the timer so the
           window stays open while the user is actively accumulating entries. */
        if (append) {
            if (clipBufferClearTimer) {
                clearTimeout(clipBufferClearTimer);
            }
            clipBufferClearTimer = setTimeout(function () {
                clipBuffer = '';
                copyCount = 0;
                clipBufferClearTimer = null;
            }, CLIP_BUFFER_CLEAR_DELAY);
        }
    }

    /* Toast element – created once, reused */
    var toast = document.createElement('div');
    toast.className = 'sentinel-copy-toast';
    document.body.appendChild(toast);

    var toastLabel = document.createElement('span');
    toastLabel.className = 'sentinel-toast-label';
    toastLabel.textContent = 'Copied to clipboard';
    toast.appendChild(toastLabel);

    /* Countdown circle (SVG) – appended to toast, shown only during Ctrl+click accumulation */
    var COUNTDOWN_RADIUS = 8;
    var COUNTDOWN_CIRCUMFERENCE = 2 * Math.PI * COUNTDOWN_RADIUS;

    var countdownWrap = document.createElement('span');
    countdownWrap.className = 'sentinel-toast-countdown';
    countdownWrap.innerHTML =
        '<svg width="21" height="21" viewBox="0 0 21 21">' +
            '<circle cx="10.5" cy="10.5" r="' + COUNTDOWN_RADIUS + '" stroke="rgba(255,255,255,.25)" stroke-width="2.5" fill="none" />' +
            '<circle class="sentinel-countdown-progress" cx="10.5" cy="10.5" r="' + COUNTDOWN_RADIUS + '" ' +
                'stroke="#fff" stroke-width="2.5" fill="none" stroke-linecap="round" ' +
                'stroke-dasharray="' + COUNTDOWN_CIRCUMFERENCE + '" stroke-dashoffset="0" />' +
        '</svg>';
    toast.appendChild(countdownWrap);

    var countdownCircle = countdownWrap.querySelector('.sentinel-countdown-progress');
    var countdownInterval = null;
    var toastTimer = null;
    var copyCount = 0;

    function startCountdown() {
        stopCountdown();
        countdownCircle.style.strokeDashoffset = '0';
        countdownWrap.style.display = 'inline-block';

        var startTime = Date.now();
        var duration = CLIP_BUFFER_CLEAR_DELAY;

        function tick() {
            var elapsed = Date.now() - startTime;
            var progress = Math.min(elapsed / duration, 1);
            countdownCircle.style.strokeDashoffset = progress * COUNTDOWN_CIRCUMFERENCE;
            if (progress < 1) {
                countdownInterval = requestAnimationFrame(tick);
            }
        }
        countdownInterval = requestAnimationFrame(tick);
    }

    function stopCountdown() {
        if (countdownInterval) {
            cancelAnimationFrame(countdownInterval);
            countdownInterval = null;
        }
    }

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
            toastLabel.textContent = 'Copied ' + copyCount + ' items';
        } else {
            toastLabel.textContent = 'Copied to clipboard';
        }

        if (toastTimer) {
            clearTimeout(toastTimer);
        }
        toast.classList.add('sentinel-toast-visible');

        if (appended) {
            /* Ctrl+click: keep toast visible with countdown until buffer clears */
            startCountdown();
            toastTimer = setTimeout(function () {
                toast.classList.remove('sentinel-toast-visible');
                stopCountdown();
                countdownWrap.style.display = 'none';
                toastTimer = null;
            }, CLIP_BUFFER_CLEAR_DELAY);
        } else {
            /* Plain click: brief toast, no countdown */
            countdownWrap.style.display = 'none';
            stopCountdown();
            toastTimer = setTimeout(function () {
                toast.classList.remove('sentinel-toast-visible');
                toastTimer = null;
            }, 1500);
        }
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
