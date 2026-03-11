/**
 * Aidelnicek — M1/M2/M3 JS
 */

document.addEventListener('DOMContentLoaded', function () {
    // ── M1/M2: Toggle zobrazení/skrytí hesla ─────────────────────────────────
    document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrapper = this.closest('.password-toggle');
            var input = wrapper.querySelector('input');
            if (input.type === 'password') {
                input.type = 'text';
                this.setAttribute('aria-label', 'Skrýt heslo');
                this.textContent = '🙈';
            } else {
                input.type = 'password';
                this.setAttribute('aria-label', 'Zobrazit heslo');
                this.textContent = '👁';
            }
        });
    });

    // ── M3: Jídelníček interakce ──────────────────────────────────────────────
    initAlternativePicker();
    initEatenCheckboxes();
});

/**
 * Read the CSRF token from the meta tag injected by layout.php.
 * @returns {string}
 */
function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Send a POST request with CSRF token.
 * Returns a Promise that resolves to the parsed JSON body, or rejects on error.
 *
 * @param {string} url
 * @param {Object} data  — key/value pairs added to FormData
 * @returns {Promise<Object>}
 */
function postAjax(url, data) {
    var fd = new FormData();
    fd.append('csrf_token', getCsrfToken());
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });

    return fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    }).then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    });
}

/**
 * Show a temporary error banner at the top of .day-plan or body.
 */
function showNetworkError() {
    var existing = document.getElementById('ajax-error-banner');
    if (existing) return;
    var banner = document.createElement('p');
    banner.id = 'ajax-error-banner';
    banner.className = 'alert alert-error';
    banner.style.position = 'fixed';
    banner.style.top = '1rem';
    banner.style.left = '50%';
    banner.style.transform = 'translateX(-50%)';
    banner.style.zIndex = '9999';
    banner.style.maxWidth = '90vw';
    banner.textContent = 'Nepodařilo se uložit změnu. Zkontrolujte připojení a zkuste znovu.';
    document.body.prepend(banner);
    setTimeout(function () { banner.remove(); }, 5000);
}

/**
 * M3: Clicking an alternative option sends a /plan/choose request and updates
 * the UI to reflect the new selection state.
 */
function initAlternativePicker() {
    document.querySelectorAll('.alt-choose-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var planId   = this.getAttribute('data-plan-id');
            var redirect = this.getAttribute('data-redirect') || '/plan/day';
            var card     = this.closest('.meal-card');
            if (!card) return;

            postAjax('/plan/choose', { plan_id: planId, redirect_to: redirect })
                .then(function (json) {
                    if (!json.ok) { showNetworkError(); return; }

                    // Update visual state for all alternatives in this card
                    card.querySelectorAll('.alt-option').forEach(function (opt) {
                        var optPlanId = opt.getAttribute('data-plan-id');
                        var isChosen  = optPlanId === planId;

                        opt.classList.toggle('is-chosen', isChosen);

                        // Update aria-pressed on the button inside
                        var optBtn = opt.querySelector('.alt-choose-btn');
                        if (optBtn) optBtn.setAttribute('aria-pressed', isChosen ? 'true' : 'false');

                        // Badge update
                        var badge = opt.querySelector('.alt-badge');
                        if (badge) {
                            badge.style.background = isChosen ? '' : '';
                        }

                        // Show/hide the eaten checkbox — only on the chosen option
                        manageEatenCheckbox(opt, isChosen, redirect);
                    });
                })
                .catch(function () { showNetworkError(); });
        });
    });
}

/**
 * Add or remove the eaten checkbox within an alt-option element.
 *
 * @param {Element} optEl      — .alt-option element
 * @param {boolean} show       — whether to show the checkbox
 * @param {string}  redirect
 */
function manageEatenCheckbox(optEl, show, redirect) {
    var existing = optEl.querySelector('.eaten-checkbox');

    if (!show) {
        if (existing) existing.remove();
        return;
    }

    if (existing) return; // already there

    var planId = optEl.getAttribute('data-plan-id');
    var label  = document.createElement('label');
    label.className = 'eaten-checkbox';
    label.setAttribute('data-plan-id', planId);

    var input = document.createElement('input');
    input.type      = 'checkbox';
    input.className = 'eaten-checkbox__input';
    input.setAttribute('data-plan-id', planId);
    input.setAttribute('data-redirect', redirect);

    var span  = document.createElement('span');
    span.className   = 'eaten-checkbox__label';
    span.textContent = 'Snězeno';

    label.appendChild(input);
    label.appendChild(span);
    optEl.appendChild(label);

    // Attach handler to the newly created checkbox
    attachEatenHandler(input);
}

/**
 * M3: Toggling the "Snězeno" checkbox sends a /plan/eaten request and updates UI.
 */
function initEatenCheckboxes() {
    document.querySelectorAll('.eaten-checkbox__input').forEach(function (cb) {
        attachEatenHandler(cb);
    });
}

function attachEatenHandler(cb) {
    cb.addEventListener('change', function () {
        var planId   = this.getAttribute('data-plan-id');
        var redirect = this.getAttribute('data-redirect') || '/plan/day';
        var optEl    = this.closest('.alt-option');
        var cbEl     = this;

        postAjax('/plan/eaten', { plan_id: planId, redirect_to: redirect })
            .then(function (json) {
                if (!json.ok) { cbEl.checked = !cbEl.checked; showNetworkError(); return; }

                var isEaten = json.is_eaten === true || json.is_eaten === 1;
                cbEl.checked = isEaten;

                if (optEl) {
                    optEl.classList.toggle('is-eaten', isEaten);
                }
            })
            .catch(function () {
                cbEl.checked = !cbEl.checked;
                showNetworkError();
            });
    });
}
