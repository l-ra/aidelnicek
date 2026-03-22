/**
 * Aidelnicek — M1/M2/M3 JS
 */

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    var s = String(str);
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function () {
    // ── Responsivní navigace: hamburger toggle ────────────────────────────────
    initNavToggle();
    initRunningJobsIndicator();

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
    initMealRecipeButtons();
    initSwapDropdown();
    initVariantExpand();

    // ── M4: Nákupní seznam interakce ──────────────────────────────────────────
    initShoppingToggle();
    initShoppingRemove();
    initShoppingFilter();
    initShoppingCopySignedLink();
});

/**
 * Mobile navigation: toggles the hamburger menu open/closed.
 * Closes on outside click and when a nav link is activated.
 */
function initNavToggle() {
    var toggle = document.querySelector('.nav-toggle');
    var nav    = document.getElementById('main-nav');
    if (!toggle || !nav) { return; }

    function openNav() {
        nav.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Zavřít menu');
    }

    function closeNav() {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Otevřít menu');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (nav.classList.contains('is-open')) {
            closeNav();
        } else {
            openNav();
        }
    });

    // Close when a nav link or logout button is clicked
    nav.addEventListener('click', function (e) {
        if (e.target.closest('a') || e.target.closest('button[type="submit"]')) {
            closeNav();
        }
    });

    // Close on click outside the header
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.site-header')) {
            closeNav();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && nav.classList.contains('is-open')) {
            closeNav();
            toggle.focus();
        }
    });
}

/**
 * Polling indicator of currently running LLM generation jobs.
 * Header count is refreshed every 5 seconds.
 */
function initRunningJobsIndicator() {
    var indicator = document.getElementById('llm-jobs-indicator');
    if (!indicator) { return; }

    var countEl = indicator.querySelector('.nav-llm-jobs__count');
    var pollUrl = indicator.getAttribute('data-poll-url') || '/llm/jobs-running-count';
    var timerId = null;

    function renderCount(rawCount) {
        var parsed = parseInt(rawCount, 10);
        var count  = Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;

        if (countEl) {
            countEl.textContent = String(count);
        }

        indicator.classList.toggle('is-active', count > 0);
        indicator.setAttribute('aria-label', 'Rozpracované LLM joby: ' + count);
        indicator.setAttribute('title', 'Rozpracované LLM joby: ' + count);
    }

    function poll() {
        fetch(pollUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (json) {
                if (!json || json.ok !== true) { return; }
                renderCount(json.count);
            })
            .catch(function () {
                // Tichý fail: indikátor zůstane na poslední známé hodnotě.
            });
    }

    renderCount(0);
    poll();
    timerId = setInterval(poll, 5000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });

    window.addEventListener('beforeunload', function () {
        if (timerId !== null) {
            clearInterval(timerId);
        }
    });
}

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
 * M3: Clicking "Vybrat pro mě" or "Vybrat pro všechny" sends request and updates UI.
 */
function initAlternativePicker() {
    document.querySelectorAll('.alt-choose-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var planId      = this.getAttribute('data-plan-id');
            var redirect    = this.getAttribute('data-redirect') || '/plan/day';
            var card        = this.closest('.meal-card');
            var mealDetail  = this.closest('.meal-detail');
            if (!card && !mealDetail) return;

            var forHousehold = this.classList.contains('alt-choose-btn--household');
            var url = forHousehold ? '/plan/choose-household' : '/plan/choose';

            postAjax(url, { plan_id: planId, redirect_to: redirect })
                .then(function (json) {
                    if (!json.ok) { showNetworkError(); return; }

                    // Meal detail page or day plan with primary/collapsed: full reload
                    var hasNewLayout = mealDetail || (card && card.querySelector('.meal-slot-primary'));
                    if (hasNewLayout) {
                        window.location.href = redirect;
                        return;
                    }

                    // Legacy layout: update visual state in place
                    if (!card) return;
                    card.querySelectorAll('.alt-option').forEach(function (opt) {
                        var optPlanId = opt.getAttribute('data-plan-id');
                        var isChosen  = optPlanId === planId;

                        opt.classList.toggle('is-chosen', isChosen);

                        var optBtn = opt.querySelector('.alt-choose-btn');
                        if (optBtn) optBtn.setAttribute('aria-pressed', isChosen ? 'true' : 'false');

                        var badge = opt.querySelector('.alt-badge');
                        if (badge) {
                            badge.style.background = isChosen ? '' : '';
                        }

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

/**
 * M3: Swap modal — prohození jídla za jídlo z jiného dne.
 * Zobrazí vlastní modální okno s přehledem jídel k výměně (název, popis, ingredience).
 */
function initSwapDropdown() {
    var modal = document.getElementById('swap-meal-modal');
    if (!modal) return;

    var titleEl = document.getElementById('swap-meal-modal-title');
    var subtitleEl = document.getElementById('swap-meal-modal-subtitle');
    var listEl = document.getElementById('swap-meal-modal-list');
    var closeBtn = document.getElementById('swap-meal-modal-close');
    var backdrop = modal.querySelector('.swap-meal-modal__backdrop');

    var currentCard = null;

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        currentCard = null;
    }

    function openModal(card) {
        currentCard = card;
        var btn = card.querySelector('.meal-card__swap-btn');
        if (!btn) return;

        var optionsJson = btn.getAttribute('data-swap-options');
        var mealTypeLabel = btn.getAttribute('data-meal-type-label') || '';
        var options = [];
        try {
            options = optionsJson ? JSON.parse(optionsJson) : [];
        } catch (e) {
            options = [];
        }

        titleEl.textContent = 'Vyměnit jídlo';
        subtitleEl.textContent = 'Vyberte jídlo z jiného dne pro ' + mealTypeLabel + ':';
        listEl.innerHTML = '';

        var scopeCheckbox = document.getElementById('swap-meal-modal-scope-user-only');
        if (scopeCheckbox) scopeCheckbox.checked = false;

        if (options.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = 'Žádná jiná jídla k výměně.';
            listEl.appendChild(empty);
        } else {
            options.forEach(function (opt) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'swap-meal-option';
                item.setAttribute('data-day', opt.day);
                item.setAttribute('role', 'listitem');

                var html = '<span class="swap-meal-option__day">' + escapeHtml(opt.dayLabel) + '</span>';
                html += '<span class="swap-meal-option__name">' + escapeHtml(opt.mealName) + '</span>';
                if (opt.description) {
                    html += '<p class="swap-meal-option__desc">' + escapeHtml(opt.description) + '</p>';
                }
                if (opt.ingredients && opt.ingredients.length > 0) {
                    html += '<ul class="swap-meal-option__ingredients">';
                    opt.ingredients.forEach(function (ing) {
                        var text = typeof ing === 'object' && ing !== null
                            ? (ing.name || '') + (ing.quantity ? ' — ' + ing.quantity + (ing.unit ? ' ' + ing.unit : '') : '')
                            : String(ing);
                        html += '<li>' + escapeHtml(text) + '</li>';
                    });
                    html += '</ul>';
                }
                item.innerHTML = html;

                item.addEventListener('click', function () {
                    var dayB = parseInt(this.getAttribute('data-day'), 10);
                    if (!dayB || !currentCard) return;

                    var weekId = parseInt(currentCard.getAttribute('data-week-id'), 10);
                    var dayA = parseInt(currentCard.getAttribute('data-current-day'), 10);
                    var mealType = currentCard.getAttribute('data-meal-type');
                    var redirect = currentCard.getAttribute('data-redirect') || '/plan/day';

                    if (!weekId || !mealType || dayA === dayB) return;

                    item.disabled = true;
                    listEl.querySelectorAll('.swap-meal-option').forEach(function (o) {
                        o.disabled = true;
                    });

                    var scopeEl = document.getElementById('swap-meal-modal-scope-user-only');
                    var swapScope = (scopeEl && scopeEl.checked) ? 'user_only' : 'household';

                    postAjax('/plan/swap', {
                        week_id: weekId,
                        day_a: dayA,
                        day_b: dayB,
                        meal_type: mealType,
                        swap_scope: swapScope,
                        redirect_to: redirect,
                    })
                        .then(function (json) {
                            if (json.ok) {
                                window.location.href = redirect;
                            } else {
                                showNetworkError();
                                listEl.querySelectorAll('.swap-meal-option').forEach(function (o) {
                                    o.disabled = false;
                                });
                            }
                        })
                        .catch(function () {
                            showNetworkError();
                            listEl.querySelectorAll('.swap-meal-option').forEach(function (o) {
                                o.disabled = false;
                            });
                        });
                });

                listEl.appendChild(item);
            });
        }

        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var firstOption = listEl.querySelector('.swap-meal-option');
        if (firstOption) firstOption.focus();
    }

    document.querySelectorAll('.meal-card__swap-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = this.closest('.meal-card');
            if (card) openModal(card);
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && e.key === 'Escape') closeModal();
    });
}

/**
 * Day plan: expand/collapse hidden variants.
 * Per-card expand buttons and header "Rozbalit všechny varianty" button.
 * Uses event delegation so clicks work even when DOM is dynamic.
 */
function initVariantExpand() {
    var collapsedSelectors = '.alt-option--collapsed.meal-slot-collapsed';

    // Header "Rozbalit všechny varianty" button
    document.body.addEventListener('click', function (e) {
        var expandAllBtn = e.target.closest('#expand-all-variants-btn');
        if (!expandAllBtn) return;

        e.preventDefault();
        e.stopPropagation();
        document.querySelectorAll(collapsedSelectors).forEach(function (el) {
            if (el.getAttribute('data-collapsed') === 'true') {
                expandVariant(el);
            }
        });
    });

    // Per-card "Rozbalit / Sbalit variantu" buttons (event delegation)
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-expand-variant');
        if (!btn) return;

        var collapsed = btn.closest('.alt-option--collapsed.meal-slot-collapsed');
        if (!collapsed) return;

        e.preventDefault();
        e.stopPropagation();

        if (collapsed.getAttribute('data-collapsed') === 'true') {
            expandVariant(collapsed);
        } else {
            collapseVariant(collapsed);
        }
    });

    // Show "Rozbalit všechny varianty" only when collapsed variants exist
    var expandAllBtn = document.getElementById('expand-all-variants-btn');
    if (expandAllBtn) {
        var collapsedEls = document.querySelectorAll(collapsedSelectors);
        var hasCollapsed = collapsedEls.length > 0;
        if (!hasCollapsed) {
            expandAllBtn.remove();
        } else {
            expandAllBtn.removeAttribute('hidden');
        }
    }
}

function expandVariant(el) {
    var content = el.querySelector('.alt-option__collapsed-content');
    var btn = el.querySelector('.alt-expand-btn');
    if (!content || !btn) return;

    content.hidden = false;
    el.setAttribute('data-collapsed', 'false');
    btn.setAttribute('aria-expanded', 'true');
    btn.querySelector('.alt-expand-btn__label').textContent = 'Sbalit variantu ' + (el.getAttribute('data-alt') || '2');
}

function collapseVariant(el) {
    var content = el.querySelector('.alt-option__collapsed-content');
    var btn = el.querySelector('.alt-expand-btn');
    if (!content || !btn) return;

    content.hidden = true;
    el.setAttribute('data-collapsed', 'true');
    btn.setAttribute('aria-expanded', 'false');
    btn.querySelector('.alt-expand-btn__label').textContent = 'Rozbalit variantu ' + (el.getAttribute('data-alt') || '2');
}

/**
 * M9: Recipe generation & display for each meal alternative.
 */
function initMealRecipeButtons() {
    document.querySelectorAll('.meal-recipe-btn').forEach(function (btn) {
        setRecipeButtonIdleLabel(btn);

        btn.addEventListener('click', function () {
            var planId = this.getAttribute('data-plan-id');
            var panel = this.parentElement ? this.parentElement.querySelector('.meal-recipe-panel') : null;
            if (!planId || !panel) { return; }

            var recipeTextEl = panel.querySelector('.meal-recipe-text');
            var metaEl = panel.querySelector('.meal-recipe-meta');
            var loaded = this.getAttribute('data-loaded') === '1';
            var loading = this.getAttribute('data-loading') === '1';
            if (!recipeTextEl || loading) { return; }

            // Toggle already loaded recipe without additional network request.
            if (loaded) {
                var isHidden = panel.hasAttribute('hidden');
                if (isHidden) {
                    panel.removeAttribute('hidden');
                    this.textContent = 'Skrýt recept';
                    this.setAttribute('aria-expanded', 'true');
                } else {
                    panel.setAttribute('hidden', 'hidden');
                    this.textContent = 'Zobraz recept';
                    this.setAttribute('aria-expanded', 'false');
                }
                return;
            }

            this.setAttribute('data-loading', '1');
            this.disabled = true;
            this.textContent = 'Připravuji...';
            this.setAttribute('aria-expanded', 'false');
            panel.setAttribute('hidden', 'hidden');

            postAjax('/plan/recipe', { plan_id: planId })
                .then(function (json) {
                    if (!json.ok) {
                        showNetworkError();
                        resetRecipeButtonAfterFailure(btn);
                        return;
                    }

                    if (json.status === 'ready' && json.recipe) {
                        applyRecipeReadyState(btn, panel, recipeTextEl, metaEl, json);
                        return;
                    }

                    if (json.status === 'generating') {
                        btn.textContent = 'Generuji recept...';
                        var jobId = json.job_id ? String(json.job_id) : '';
                        pollRecipeStatus(planId, jobId, btn, panel, recipeTextEl, metaEl, 0);
                        return;
                    }

                    showNetworkError();
                    resetRecipeButtonAfterFailure(btn);
                })
                .catch(function () {
                    showNetworkError();
                    resetRecipeButtonAfterFailure(btn);
                });
        });
    });
}

function pollRecipeStatus(planId, jobId, btn, panel, recipeTextEl, metaEl, attempt) {
    var pollUrl = '/plan/recipe-status?plan_id=' + encodeURIComponent(planId);
    if (jobId) {
        pollUrl += '&job_id=' + encodeURIComponent(jobId);
    }

    fetch(pollUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        })
        .then(function (json) {
            if (!json || json.ok !== true) {
                throw new Error('invalid response');
            }

            if (json.status === 'ready' && json.recipe) {
                applyRecipeReadyState(btn, panel, recipeTextEl, metaEl, json);
                return;
            }

            if (json.status === 'generating') {
                var delayMs = attempt < 3 ? 1500 : 2500;
                setTimeout(function () {
                    pollRecipeStatus(planId, jobId, btn, panel, recipeTextEl, metaEl, attempt + 1);
                }, delayMs);
                return;
            }

            throw new Error('unexpected status');
        })
        .catch(function () {
            showNetworkError();
            resetRecipeButtonAfterFailure(btn);
        });
}

function applyRecipeReadyState(btn, panel, recipeTextEl, metaEl, json) {
    recipeTextEl.textContent = json.recipe;
    panel.removeAttribute('hidden');
    btn.setAttribute('data-loaded', '1');
    btn.setAttribute('data-has-recipe', '1');
    btn.removeAttribute('data-loading');
    btn.disabled = false;
    btn.textContent = 'Skrýt recept';
    btn.setAttribute('aria-expanded', 'true');

    if (metaEl) {
        var note = json.was_generated
            ? 'Recept byl právě vygenerován přes AI a uložen.'
            : 'Recept je načten ze sdílené databáze.';
        if (json.shared_across_users === true) {
            note += ' Sdílený i pro ostatní uživatele se stejným návrhem jídla.';
        }
        metaEl.textContent = note;
        metaEl.removeAttribute('hidden');
    }
}

function setRecipeButtonIdleLabel(btn) {
    if (!btn) { return; }
    var hasRecipe = btn.getAttribute('data-has-recipe') === '1';
    btn.textContent = hasRecipe ? 'Zobraz recept' : 'Generuj recept';
    btn.setAttribute('aria-expanded', 'false');
}

function resetRecipeButtonAfterFailure(btn) {
    if (!btn) { return; }
    btn.removeAttribute('data-loading');
    btn.disabled = false;
    setRecipeButtonIdleLabel(btn);
}

// ── M4: Shopping list ────────────────────────────────────────────────────────

/**
 * Recalculates and updates the progress bar, progress label and filter tab
 * counters based on the current DOM state of .shopping-item elements.
 */
function updateShoppingProgress() {
    var container = document.getElementById('shopping-items-container');
    if (!container) { return; }

    var allItems       = container.querySelectorAll('.shopping-item');
    var purchasedItems = container.querySelectorAll('.shopping-item.is-purchased');
    var total          = allItems.length;
    var bought         = purchasedItems.length;
    var remaining      = total - bought;
    var percent        = total > 0 ? Math.round(bought / total * 100) : 0;

    var fill  = document.getElementById('shopping-progress-fill');
    var label = document.getElementById('shopping-progress-label');
    if (fill)  { fill.style.width = percent + '%'; }
    if (label) { label.textContent = bought + ' / ' + total + ' nakoupeno'; }

    var countAll       = document.getElementById('count-all');
    var countRemaining = document.getElementById('count-remaining');
    var countPurchased = document.getElementById('count-purchased');
    if (countAll)       { countAll.textContent       = total; }
    if (countRemaining) { countRemaining.textContent = remaining; }
    if (countPurchased) { countPurchased.textContent = bought; }
}

/**
 * Handles clicks on the circular check buttons next to each shopping item.
 * Sends a POST to /shopping/toggle and toggles the is-purchased class.
 */
function initShoppingToggle() {
    var container = document.getElementById('shopping-items-container');
    if (!container) { return; }

    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.shopping-item__check');
        if (!btn) { return; }

        var itemId = btn.getAttribute('data-item-id');
        var li     = btn.closest('.shopping-item');
        if (!itemId || !li) { return; }

        postAjax('/shopping/toggle', { item_id: itemId, redirect_to: '/shopping' })
            .then(function (json) {
                if (!json.ok) { showNetworkError(); return; }

                var isPurchased = json.is_purchased === true || json.is_purchased === 1;
                li.classList.toggle('is-purchased', isPurchased);
                btn.setAttribute('aria-pressed', isPurchased ? 'true' : 'false');
                btn.setAttribute('aria-label', isPurchased ? 'Odznačit' : 'Označit jako nakoupeno');

                updateShoppingProgress();
                applyCurrentFilter();
            })
            .catch(function () { showNetworkError(); });
    });
}

/**
 * Handles clicks on the × remove buttons.
 * Sends a POST to /shopping/remove and removes the list item from the DOM.
 */
function initShoppingRemove() {
    var container = document.getElementById('shopping-items-container');
    if (!container) { return; }

    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.shopping-item__remove');
        if (!btn) { return; }

        var itemId = btn.getAttribute('data-item-id');
        var li     = btn.closest('.shopping-item');
        if (!itemId || !li) { return; }

        postAjax('/shopping/remove', { item_id: itemId, redirect_to: '/shopping' })
            .then(function (json) {
                if (!json.ok) { showNetworkError(); return; }

                var group = li.closest('.shopping-category-group');
                li.remove();

                // Remove empty category group
                if (group && group.querySelector('.shopping-item') === null) {
                    group.remove();
                }

                updateShoppingProgress();
            })
            .catch(function () { showNetworkError(); });
    });
}

/**
 * Handles the "Kopírovat odkaz ke stažení" button.
 * Copies the signed CSV export URL to clipboard.
 */
function initShoppingCopySignedLink() {
    document.querySelectorAll('.js-copy-signed-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = this.getAttribute('data-url-csv') || '';
            if (!url) { return; }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    var orig = btn.textContent;
                    btn.textContent = 'Odkaz zkopírován';
                    setTimeout(function () { btn.textContent = orig; }, 2000);
                }).catch(function () {
                    fallbackCopy(url, btn);
                });
            } else {
                fallbackCopy(url, btn);
            }
        });
    });
}

function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        var orig = btn.textContent;
        btn.textContent = 'Odkaz zkopírován';
        setTimeout(function () { btn.textContent = orig; }, 2000);
    } catch (e) { /* ignore */ }
    document.body.removeChild(ta);
}

/**
 * Handles filter tab clicks (Vše / Zbývá / Nakoupeno).
 * Shows/hides .shopping-item elements by toggling a hidden class.
 */
function initShoppingFilter() {
    var tabContainer = document.querySelector('.shopping-filter');
    if (!tabContainer) { return; }

    tabContainer.addEventListener('click', function (e) {
        var tab = e.target.closest('.shopping-filter__tab');
        if (!tab) { return; }

        tabContainer.querySelectorAll('.shopping-filter__tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');

        applyCurrentFilter();
    });
}

/**
 * Applies the currently active filter to all .shopping-item elements.
 * Called after toggle and remove actions as well.
 */
function applyCurrentFilter() {
    var activeTab = document.querySelector('.shopping-filter__tab.is-active');
    var filter    = activeTab ? activeTab.getAttribute('data-filter') : 'all';
    var container = document.getElementById('shopping-items-container');
    if (!container) { return; }

    container.querySelectorAll('.shopping-item').forEach(function (li) {
        var isPurchased = li.classList.contains('is-purchased');
        var visible;
        if (filter === 'remaining') {
            visible = !isPurchased;
        } else if (filter === 'purchased') {
            visible = isPurchased;
        } else {
            visible = true;
        }
        li.style.display = visible ? '' : 'none';
    });
}
