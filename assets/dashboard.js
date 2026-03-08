/**
 * Sentinel Dashboard Widget - AJAX auto-refresh
 *
 * Polls the server at a configurable interval and replaces the
 * widget content so status changes appear without a page reload.
 */
(function () {
    'use strict';

    if (typeof sentinelAjax === 'undefined') {
        return;
    }

    var container = document.getElementById('sentinel_plugin_status');
    if (!container) {
        return;
    }

    // The actual widget content lives inside .inside
    var inside = container.querySelector('.inside');
    if (!inside) {
        return;
    }

    var intervalMs = (parseInt(sentinelAjax.interval, 10) || 30) * 1000;

    function refresh() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', sentinelAjax.url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || xhr.status !== 200) {
                return;
            }
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success && response.data && response.data.html) {
                    inside.innerHTML = response.data.html;
                }
            } catch (e) {
                // Silently ignore parse errors
            }
        };
        xhr.send(
            'action=' + encodeURIComponent(sentinelAjax.action) +
            '&nonce=' + encodeURIComponent(sentinelAjax.nonce)
        );
    }

    setInterval(refresh, intervalMs);
})();
