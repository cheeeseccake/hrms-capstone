    
    export function reinitPage(page) {
    initTabs();
    initForms();
    window.dispatchEvent(new CustomEvent('page:loaded', { detail: { page: page } }));
    }

    // ─── Tab Switcher ─────────────────────────────────────────────────────────────

    export function initTabs() {
    const tabItems = document.querySelectorAll('.tab-item');

    if (!tabItems.length) return;

    tabItems.forEach(function (tab) {
        // Tab switching is also handled by layout/tab-content.js. Only one
        // handler may own a tab: a second handler conflicts with the first
        // because both clear the active panel before setting a new one.
        if (tab.dataset.tabInit === '1') return;

        tab.addEventListener('click', function () {
        // Scope to the containing tab-container so sibling tab groups on the
        // same page are unaffected.
        const container = this.closest('.tab-container') || document;
        const containerTabs = container.querySelectorAll('.tab-item');
        const containerContents = container.querySelectorAll('.tab-content');

        containerTabs.forEach(function (t) { t.classList.remove('active'); });
        containerContents.forEach(function (c) { c.classList.remove('active'); });

        this.classList.add('active');

        // Panels are identified by a data-tab attribute; the id lookup is kept
        // only as a fallback for older markup.
        let target = container.querySelector('.tab-content[data-tab="' + this.dataset.tab + '"]');
        if (!target) target = document.getElementById(this.dataset.tab);
        if (target) target.classList.add('active');
        });

        tab.dataset.tabInit = '1';
    });
    }

    // ─── Form Submissions ─────────────────────────────────────────────────────────

    export function initForms() {
    const forms = document.querySelectorAll('form:not([data-skip]):not(#approval-upload-form)');

    forms.forEach(function (form) {
        const fresh = form.cloneNode(true);
        form.parentNode.replaceChild(fresh, form);

        fresh.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(fresh);
        const action = fresh.getAttribute('action') || window.location.href;

        fetch(action, {
            method: fresh.getAttribute('method') || 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
            if (!response.ok) throw new Error('Form submission failed');
            return response.text();
            })
            .then(function (result) {
            console.log('Form submitted successfully', result);
            const current = new URL(location).searchParams.get('page') || 'dashboard-overview';
            // Fire an event so main.js can handle the page reload
            window.dispatchEvent(new CustomEvent('form:success', { detail: { page: current } }));
            })
            .catch(function (err) {
            console.error('Form error', err);
            });
        });
    });
    }