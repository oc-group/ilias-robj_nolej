/**
 * Check for updates.
 * @param {string} endpoint
 * @param {string} redirectTo
 * @param {number} interval seconds
 */
function xnlj_check_updates(endpoint, redirectTo, interval = 10) {
    // Minimum 1 second.
    if (interval < 1) {
        interval = 1;
    }

    setInterval(() => {
        fetch(endpoint, { credentials: "same-origin" })
            .then((response) => response.text())
            .then((text) => {
                if (text == "update") {
                    window.location = redirectTo;
                }
            })
            .catch(() => {
                // Ignore network errors, retry at the next interval.
            });
    }, interval * 1000);
}
