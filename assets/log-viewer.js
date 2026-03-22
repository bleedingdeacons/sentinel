/**
 * Sentinel Log Viewer – AJAX refresh
 *
 * Handles the "Refresh" button on the admin log viewer page.
 */
(function () {
    'use strict';

    if (typeof sentinelLogViewer === 'undefined') {
        return;
    }

    var refreshBtn = document.getElementById('sentinel-refresh-log');
    var container  = document.getElementById('sentinel-log-aggregate-container');

    if (!refreshBtn || !container) {
        return;
    }

    refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        container.innerHTML =
            '<div class="sentinel-log-loading">' +
            '<span class="spinner"></span> Refreshing&hellip;' +
            '</div>';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', sentinelLogViewer.url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            refreshBtn.disabled = false;

            if (xhr.status !== 200) {
                container.innerHTML =
                    '<div class="notice notice-error"><p>Failed to refresh log data.</p></div>';
                return;
            }

            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success && response.data && response.data.html) {
                    container.innerHTML = response.data.html;
                } else {
                    container.innerHTML =
                        '<div class="notice notice-error"><p>Unexpected response.</p></div>';
                }
            } catch (e) {
                container.innerHTML =
                    '<div class="notice notice-error"><p>Failed to parse response.</p></div>';
            }
        };
        xhr.send(
            'action=' + encodeURIComponent(sentinelLogViewer.action) +
            '&nonce=' + encodeURIComponent(sentinelLogViewer.nonce)
        );
    });
})();
