// ==========================================================================
// HRMS Capstone — Employee Health Page (JS)
// View-first / edit-on-click behavior for the health profile, conditional
// fields, live BMI preview, unsaved-changes protection, and toasts.
// The clinic visit form lives in ./clinic-visit-form.js and is initialized
// from here with the toast and dirty-state hooks it needs.
// ==========================================================================

import { initClinicVisitForm } from './clinic-visit-form.js';

function initEmployeeHealth(root) {
  'use strict';

  /*
   * Keep the main content and its side panel in the same grid row. Older
   * rendered markup can leave the aside elsewhere in the page, where the
   * grid reserves an empty second track. Move that existing panel back into
   * the row before any page behavior initializes.
   */
  if (root) {
    var ehRow = root.querySelector(':scope > .eh-row');
    if (ehRow && !ehRow.querySelector(':scope > .eh-col-side')) {
      var ehSide = root.querySelector('.eh-col-side[aria-label="Employee health summary"], .eh-col-side[aria-label="Search guidance"]')
        || document.querySelector('.eh-col-side[aria-label="Employee health summary"], .eh-col-side[aria-label="Search guidance"]');
      if (ehSide && ehSide.parentElement !== ehRow) {
        ehRow.appendChild(ehSide);
      }
    }
  }

  if (!root || root.dataset.ehInit === '1') return;
  root.dataset.ehInit = '1';

  /* Live employee lookup uses the same server-side registry search and
     authorization as the submitted form, then updates the results card. */
  var employeeSearchInput = root.querySelector('#ehSearchInput');
  var employeeSearchForm = employeeSearchInput && employeeSearchInput.form;
  var employeeResults = root.querySelector('[data-employee-results]');
  var employeeResultCount = root.querySelector('[data-employee-result-count]');
  if (employeeSearchInput && employeeSearchForm && employeeResults) {
    var employeeSearchTimer = null;
    var employeeSearchRequest = null;
    var searchStatus = employeeSearchForm.querySelector('[name="employment_status"]');

    function loadEmployeeSuggestions() {
      var term = employeeSearchInput.value.trim();
      if (employeeSearchRequest) employeeSearchRequest.abort();
      if (term === '' && (!searchStatus || !searchStatus.value)) {
        var emptyResults = document.createElement('div');
        emptyResults.className = 'eh-empty';
        emptyResults.textContent = 'Enter an employee number, employee code, or name to search the registry.';
        employeeResults.replaceChildren(emptyResults);
        employeeResults.removeAttribute('aria-busy');
        if (employeeResultCount) employeeResultCount.hidden = true;
        return;
      }

      employeeResults.setAttribute('aria-busy', 'true');
      var request = new AbortController();
      employeeSearchRequest = request;
      var url = new URL(employeeSearchForm.action || window.location.href, window.location.href);
      url.search = '';
      url.searchParams.set('page', 'employee-health');
      if (term !== '') url.searchParams.set('q', term);
      if (searchStatus && searchStatus.value) url.searchParams.set('employment_status', searchStatus.value);

      fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        signal: request.signal
      }).then(function (response) {
        if (!response.ok) throw new Error('Employee search failed.');
        return response.text();
      }).then(function (html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var nextResults = parsed.querySelector('[data-employee-results]');
        var nextCount = parsed.querySelector('[data-employee-result-count]');
        if (!nextResults) throw new Error('Employee results were unavailable.');
        employeeResults.replaceChildren.apply(employeeResults, Array.from(nextResults.childNodes).map(function (node) {
          return document.importNode(node, true);
        }));
        if (employeeResultCount) {
          employeeResultCount.textContent = nextCount ? nextCount.textContent.trim() : '';
          employeeResultCount.hidden = !nextCount || nextCount.hidden;
        }
      }).catch(function (error) {
        if (error.name === 'AbortError') return;
        employeeResults.textContent = 'Unable to load employee suggestions. Please try again.';
        if (employeeResultCount) employeeResultCount.hidden = true;
      }).finally(function () {
        if (employeeSearchRequest === request) {
          employeeResults.removeAttribute('aria-busy');
          employeeSearchRequest = null;
        }
      });
    }

    employeeSearchInput.addEventListener('input', function () {
      window.clearTimeout(employeeSearchTimer);
      employeeSearchTimer = window.setTimeout(loadEmployeeSuggestions, 250);
    });
    if (searchStatus) searchStatus.addEventListener('change', loadEmployeeSuggestions);
  }

  /* ── Toast notifications ─────────────────────────────────────────────── */

  function showToast(message, type) {
    var stack = document.querySelector('.eh-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'eh-toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    var toast = document.createElement('div');
    toast.className = 'eh-toast eh-toast--' + (type === 'error' ? 'error' : 'success');
    toast.textContent = message;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    stack.appendChild(toast);
    var remove = function () {
      if (!toast.parentNode) return;
      toast.classList.add('is-leaving');
      window.setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 160);
    };
    toast.addEventListener('click', remove);
    window.setTimeout(remove, 6000);
  }

  /* Server-rendered flash messages are also surfaced as toasts. */
  root.querySelectorAll('[data-eh-flash]').forEach(function (flash) {
    var type = flash.getAttribute('data-eh-flash') || 'success';
    var message = (flash.textContent || '').trim();
    if (message !== '') {
      showToast(message, type);
    }
    flash.hidden = true;
  });

  /* ── Profile completion intro card: pops in top-right like a toast ─── */

  var introCard = root.querySelector('[data-eh-intro-card]');
  if (introCard) {
    /* Relocate into the fixed toast stack so it appears below the header at
       the top-right on both desktop and mobile. */
    var stack = document.querySelector('.eh-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'eh-toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    stack.appendChild(introCard);
    introCard.classList.add('eh-side-card--intro');

    var meter = introCard.querySelector('.eh-meter');
    if (meter) {
      window.setTimeout(function () {
        meter.classList.add('is-animating');
      }, 180);
    }

    /* Dismiss after 3 seconds, waiting for the 0.4s fade-out to finish. */
    window.setTimeout(function () {
      introCard.classList.add('is-dismissed');
      window.setTimeout(function () {
        introCard.hidden = true;
      }, 460);
    }, 3000);
  }

  /* ── Internal tab deep-linking (?section=) ───────────────────────────── */

  var tabItems = Array.prototype.slice.call(root.querySelectorAll('.tab-item'));
  var tabContainer = root.querySelector('.eh-tab-container');
  var employeeStatePrefix = 'eh-health-state:' + (root.dataset.employeeId || 'current');

  function readSavedState(key) {
    try { return window.localStorage.getItem(employeeStatePrefix + ':' + key); }
    catch (error) { return null; }
  }

  function saveState(key, value) {
    try { window.localStorage.setItem(employeeStatePrefix + ':' + key, value); }
    catch (error) { /* Storage can be disabled; URL state remains available for tabs. */ }
  }

  function activateSection(id) {
    var match = tabItems.filter(function (tab) { return tab.dataset.tab === id; })[0];
    if (match) match.click();
  }

  /* The server marks the section to open first (edit mode opens the profile). */
  var savedTab = readSavedState('tab');
  var sectionFromUrl = new URLSearchParams(window.location.search).get('section');
  var defaultSection = (tabContainer && tabContainer.dataset.ehDefaultTab) || 'visits';
  var requestedSection = tabItems.some(function (tab) { return tab.dataset.tab === sectionFromUrl; })
    ? sectionFromUrl
    : (tabItems.some(function (tab) { return tab.dataset.tab === savedTab; }) ? savedTab : defaultSection);
  if (requestedSection && tabItems.some(function (tab) { return tab.dataset.tab === requestedSection; })) {
    activateSection(requestedSection);
  }

  tabItems.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('section', tab.dataset.tab);
      window.history.replaceState(window.history.state, '', url.toString());
      saveState('tab', tab.dataset.tab);
    });
  });

  /* ── Unsaved-changes guard ───────────────────────────────────────────── */

  var dirty = false;
  var visitDirty = false;

  function confirmDiscard() {
    return !dirty || window.confirm('Discard unsaved changes and leave the health profile form?');
  }

  /* The visit form reports its own dirty state so this guard covers both forms. */
  initClinicVisitForm(root, {
    showToast: showToast,
    onVisitDirtyChange: function (isDirty) { visitDirty = isDirty; }
  });

  /* ── Switching tabs with unsaved changes asks first (capture phase so the
     shared tab handler only runs after confirmation). ─────────────────── */

  var tabNav = root.querySelector('.tab-nav');
  if (tabNav) {
    tabNav.addEventListener('click', function (event) {
      var tab = event.target.closest ? event.target.closest('.tab-item') : null;
      if (!tab) return;
      if (!confirmDiscard()) {
        event.preventDefault();
        event.stopPropagation();
      }
    }, true);
  }

  /* ── Edit-mode form behavior ─────────────────────────────────────────── */

  var form = document.getElementById('ehProfileForm');

  if (form) {
  var submitButtons = Array.prototype.slice.call(document.querySelectorAll('button[type="submit"]'))
    .filter(function (button) { return button.form === form; });
  var emergencyPhoneInput = document.getElementById('ehEmergencyPhone');

  function validateEmergencyPhone() {
    if (!emergencyPhoneInput) return true;
    var phone = emergencyPhoneInput.value.trim();
    var normalized = phone.replace(/[\s().-]/g, '');
    var validPhilippineNumber = /^(?:09\d{9}|0[2-9]\d{8,9}|\+?639\d{9}|\+?63[2-9]\d{8,9})$/.test(normalized);
    var valid = phone === '' || (/^[0-9+().\s-]+$/.test(phone) && validPhilippineNumber);
    emergencyPhoneInput.setAttribute('aria-invalid', valid ? 'false' : 'true');
    emergencyPhoneInput.setCustomValidity(valid ? '' : 'Enter a valid Philippine mobile or landline number, such as 0917-555-1234 or +63 917 555 1234.');
    return valid;
  }

  if (emergencyPhoneInput) {
    emergencyPhoneInput.addEventListener('input', validateEmergencyPhone);
    emergencyPhoneInput.addEventListener('blur', validateEmergencyPhone);
  }

  function markDirty() { dirty = true; }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);

  form.addEventListener('submit', function (event) {
    if (!validateEmergencyPhone()) {
      event.preventDefault();
      emergencyPhoneInput.reportValidity();
      return;
    }
    dirty = false;
    submitButtons.forEach(function (submitButton) {
      submitButton.disabled = true;
      submitButton.dataset.originalHtml = submitButton.innerHTML;
      submitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    });
  });

  /* Cancel discards unsaved changes (the server keeps saved values). */
  root.querySelectorAll('[data-eh-cancel]').forEach(function (cancelLink) {
    cancelLink.addEventListener('click', function (event) {
      if (!dirty || window.confirm('Discard unsaved changes to this health profile?')) return;
      event.preventDefault();
    });
  });

  /* ── Cascading (conditional) fields across all profile sections ─────── */

  /**
   * A field marked data-eh-condition-field/val is only shown while its
   * trigger field holds the expected value. Anything marked
   * data-eh-trigger re-enables the fields it reveals when changed.
   */
  function ehConditionField(el) {
    return el.getAttribute('data-eh-condition-field');
  }

  function ehTriggerControl(name, scope) {
    return scope.querySelector('[name="' + name + '"]');
  }

  function ehSyncConditionals(scope) {
    var conditionals = scope.querySelectorAll('[data-eh-condition-field]');
    conditionals.forEach(function (el) {
      var triggerName = ehConditionField(el);
      var expected = el.getAttribute('data-eh-condition-value');
      var trigger = ehTriggerControl(triggerName, scope);
      if (!trigger) return;
      var matches = trigger.type === 'checkbox'
        ? (trigger.checked ? '1' : '0') === expected
        : trigger.value === expected;
      el.hidden = !matches;
    });
  }

  var formRoot = document.getElementById('ehProfileForm') || root;
  if (formRoot) {
    formRoot.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || !target.name) return;
      /* Re-evaluate fields revealed by this control. */
      formRoot.querySelectorAll('[data-eh-condition-field="' + target.name + '"]').forEach(function () {
        ehSyncConditionals(formRoot);
      });
      if (target.hasAttribute('data-eh-trigger')) {
        ehSyncConditionals(formRoot);
      }
    });
    ehSyncConditionals(formRoot);
  }

  } /* end edit-mode form behavior */

  /* ── Health profile accordion ───────────────────────────────────────── */

  var accordions = Array.prototype.slice.call(root.querySelectorAll('[data-eh-accordion]'));
  if (accordions.length > 0) {
    accordions.forEach(function (accordion) {
      var head = accordion.querySelector('.eh-accordion-head');
      var body = accordion.querySelector('.eh-accordion-body');
      if (!head || !body) return;

      function setOpen(open) {
        accordion.classList.toggle('is-open', open);
        body.hidden = !open;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      head.addEventListener('click', function () {
        var willOpen = !accordion.classList.contains('is-open');

        /* Accordion behaviour: opening one section closes the others. */
        accordions.forEach(function (other) {
          if (other === accordion) return;
          other.classList.remove('is-open');
          var otherBody = other.querySelector('.eh-accordion-body');
          var otherHead = other.querySelector('.eh-accordion-head');
          if (otherBody) otherBody.hidden = true;
          if (otherHead) otherHead.setAttribute('aria-expanded', 'false');
        });

        setOpen(willOpen);

        /* Remember the open section in the URL so a reload (or a profile
           save) reopens it instead of the first section. */
        if (typeof window.history.replaceState === 'function') {
          var url = new URL(window.location.href);
          if (willOpen) {
            url.searchParams.set('profile_section', accordion.getAttribute('data-section') || '');
          } else {
            url.searchParams.delete('profile_section');
          }
          window.history.replaceState(window.history.state, '', url.toString());
        }

        /* Keep the opened section in view, clear of the fixed header.
           Only scroll when opening (closing leaves nothing to focus). */
        if (willOpen && typeof accordion.scrollIntoView === 'function') {
          var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          accordion.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start'
          });
        }
      });
    });
  }

  /* ── Delete confirmation for record rows ───────────────────────────── */

  root.querySelectorAll('[data-eh-confirm]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      if (!window.confirm(button.getAttribute('data-eh-confirm'))) {
        event.preventDefault();
      }
    });
  });

  /* ── Return to Work button ─────────────────────────────────────────── */

  root.querySelectorAll('.eh-return-to-work-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var visitId = button.getAttribute('data-visit-id');
      var employeeId = root.dataset.employeeId;
      if (!employeeId || !visitId) return;
      var url = new URL(window.location.href);
      url.searchParams.set('page', 'clinic-visit');
      url.searchParams.set('employee_id', employeeId);
      url.searchParams.set('visit_type', 'return_to_work');
      url.searchParams.set('rest_visit_id', visitId);
      window.location.assign(url.toString());
    });
  });

  /* ── Edit visit button ─────────────────────────────────────────────── */

  root.querySelectorAll('.eh-edit-visit-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var visitId = button.getAttribute('data-visit-id');
      var employeeId = root.dataset.employeeId;
      if (!employeeId || !visitId) return;
      var url = new URL(window.location.href);
      url.searchParams.set('page', 'clinic-visit');
      url.searchParams.set('employee_id', employeeId);
      url.searchParams.set('edit_visit_id', visitId);
      var visitType = button.getAttribute('data-visit-type');
      if (visitType) url.searchParams.set('visit_type', visitType);
      window.location.assign(url.toString());
    });
  });

  /* ── Live BMI preview ────────────────────────────────────────────────── */

  root.querySelectorAll('.eh-delete-visit-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var visitId = button.getAttribute('data-visit-id');
      var employeeId = root.dataset.employeeId;
      if (!employeeId || !visitId || !window.confirm('Delete this older clinic visit and its attached documents? This cannot be undone.')) return;

      var endpoint = new URL('lib/api/record-clinic-visit.php', window.location.href);
      endpoint.searchParams.set('employee_id', employeeId);
      var body = new FormData();
      body.set('action', 'delete_visit');
      body.set('visit_id', visitId);
      body.set('csrf_token', button.getAttribute('data-csrf-token') || '');
      button.disabled = true;

      fetch(endpoint.toString(), {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (response) {
        return response.text().then(function (responseText) {
          var payload;
          try {
            payload = JSON.parse(responseText);
          } catch (parseError) {
            var message = response.redirected
              ? 'Your session may have expired. Reload the page and sign in again.'
              : 'The server returned an invalid response (HTTP ' + response.status + '). The visit was not deleted.';
            throw new Error(message);
          }
          if (!response.ok || !payload.success) throw new Error(payload.message || 'The clinic visit could not be deleted.');
          var row = button.closest('tr');
          if (row) row.remove();
          showToast(payload.message || 'Clinic visit deleted.', 'success');
        });
      }).catch(function (error) {
        showToast(error.message || 'The clinic visit could not be deleted.', 'error');
        button.disabled = false;
      });
    });
  });

  var heightInput = document.getElementById('eh_height_cm');
  var weightInput = document.getElementById('eh_weight_kg');
  var bmiPreview = document.getElementById('eh_bmi');

  function updateBmi() {
    if (!heightInput || !weightInput || !bmiPreview) return;
    var h = parseFloat(heightInput.value);
    var w = parseFloat(weightInput.value);
    if (!h || !w || h < 30 || h > 260 || w < 1 || w > 400) {
      bmiPreview.value = bmiPreview.dataset.serverValue || '—';
      return;
    }
    var meters = h / 100;
    bmiPreview.value = (w / (meters * meters)).toFixed(2);
  }

  if (bmiPreview) {
    bmiPreview.dataset.serverValue = bmiPreview.value;
  }
  if (heightInput) heightInput.addEventListener('input', updateBmi);
  if (weightInput) weightInput.addEventListener('input', updateBmi);

  /* ── Consent handling ────────────────────────────────────────────────── */

  var consentCheckbox = document.getElementById('eh_privacy_consent');
  var consentDate = document.getElementById('eh_consent_date');

  if (consentCheckbox && consentDate) {
    consentCheckbox.addEventListener('change', function () {
      if (!consentCheckbox.checked) return;
      if (consentDate.value === '') {
        var today = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        consentDate.value = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
      }
    });
  }

  /* Native accordions retain the existing form controls and values. */
  function makeAccordion(section, headingSelector) {
    var heading = section.querySelector(headingSelector);
    if (!heading) return;
    var title = (heading.textContent || '').trim();
    if (!title) return;
    var details = document.createElement('details');
    details.className = 'eh-accordion';
    var accordionKey = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    details.dataset.ehAccordionKey = accordionKey;
    var savedOpen = readSavedState('accordion:' + accordionKey);
    details.open = savedOpen === null ? true : savedOpen === '1';
    var summary = document.createElement('summary');
    summary.textContent = title;
    heading.remove();
    var parent = section.parentNode;
    parent.replaceChild(details, section);
    details.appendChild(summary);
    details.appendChild(section);
    details.addEventListener('toggle', function () {
      saveState('accordion:' + accordionKey, details.open ? '1' : '0');
    });
  }
  root.querySelectorAll('.eh-form-section').forEach(function (section) {
    makeAccordion(section, '.eh-form-legend');
  });
  root.querySelectorAll('.tab-content[data-tab="profile"] .eh-view-section').forEach(function (section) {
    makeAccordion(section, '.eh-form-legend');
  });

  /* Reusable accessible autocomplete for profile multi-value fields. */
  root.querySelectorAll('[data-eh-autocomplete]').forEach(function (component) {
    var searchInput = component.querySelector('.eh-autocomplete-input');
    var tags = component.querySelector('[data-eh-tags]');
    var valueInput = component.querySelector('[data-eh-autocomplete-value]');
    var statusInput = component.querySelector('[data-eh-autocomplete-status]');
    var noKnownCheckbox = component.parentNode.querySelector('[data-eh-no-known]');
    var menu = component.querySelector('[data-eh-menu]');
    var list = component.querySelector('[role="listbox"]');
    var emptyMessage = component.querySelector('[data-eh-empty]');
    var limitMessage = component.parentNode.querySelector('[data-eh-limit]');
    var historyFormat = component.hasAttribute('data-eh-history-format');
    var historyPrefix = component.getAttribute('data-history-prefix') || 'EH_HISTORY_V1:';
    var historyNotes = historyFormat ? document.getElementById(component.getAttribute('data-notes-input-id')) : null;
    var historyTouched = false;
    var maxLength = parseInt(component.getAttribute('data-max-length') || '', 10) || 2000;
    if (!searchInput || !tags || !valueInput || !menu || !list) return;

    var suggestions = [];
    var selected = [];
    try { suggestions = JSON.parse(component.getAttribute('data-suggestions') || '[]'); } catch (error) { suggestions = []; }
    try { selected = JSON.parse(component.getAttribute('data-initial') || '[]'); } catch (error) { selected = []; }

    function normalize(value) { return String(value || '').trim().toLowerCase(); }
    function uniqueValues(values) {
      var seen = Object.create(null);
      return values.map(function (value) { return String(value || '').trim(); }).filter(function (value) {
        var key = normalize(value);
        if (!key || seen[key]) return false;
        seen[key] = true;
        return true;
      });
    }
    function serialize(values) {
      if (historyFormat) {
        var notes = historyNotes ? historyNotes.value.trim() : '';
        if (!values.length && !notes) return '';
        return historyPrefix + JSON.stringify({ entries: values, notes: notes });
      }
      return values.map(function (value) { return '"' + value.replace(/"/g, '""') + '"'; }).join(', ');
    }
    function syncFields(silent) {
      selected = uniqueValues(selected);
      if (!(historyFormat && silent && !historyTouched)) {
        valueInput.value = serialize(selected);
      }
      if (statusInput) {
        statusInput.value = noKnownCheckbox && noKnownCheckbox.checked
          ? 'none_known'
          : (selected.length ? 'reported' : 'not_recorded');
      }
      if (limitMessage) {
        limitMessage.textContent = 'This field cannot exceed ' + maxLength + ' characters.';
        limitMessage.hidden = !(historyFormat && historyTouched && valueInput.value.length > maxLength);
      }
      if (!silent) valueInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function closeMenu() {
      menu.hidden = true;
      searchInput.setAttribute('aria-expanded', 'false');
      searchInput.removeAttribute('aria-activedescendant');
      activeIndex = -1;
    }
    function openMenu() {
      if (searchInput.disabled) return;
      menu.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
      renderOptions();
    }
    function renderTags() {
      tags.replaceChildren();
      selected.forEach(function (value, index) {
        var chip = document.createElement('span');
        chip.className = 'eh-autocomplete-chip';
        var text = document.createElement('span');
        text.textContent = value;
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'eh-autocomplete-remove';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Remove ' + value);
        remove.addEventListener('click', function () {
          selected.splice(index, 1);
          if (historyFormat) historyTouched = true;
          renderTags();
          syncFields();
          searchInput.focus();
          if (!menu.hidden) renderOptions();
        });
        chip.appendChild(text);
        chip.appendChild(remove);
        tags.appendChild(chip);
      });
    }
    var activeIndex = -1;
    var renderedOptions = [];
    function renderOptions() {
      var query = normalize(searchInput.value);
      var selectedKeys = Object.create(null);
      selected.forEach(function (value) { selectedKeys[normalize(value)] = true; });
      var matches = suggestions.filter(function (item) {
        var label = typeof item === 'string' ? item : item.label;
        return label && !selectedKeys[normalize(label)] && (!query || normalize(label).indexOf(query) !== -1);
      }).slice(0, 8);

      list.replaceChildren();
      renderedOptions = [];
      activeIndex = -1;
      if (emptyMessage) {
        emptyMessage.hidden = matches.length > 0;
        emptyMessage.textContent = query === '' ? 'All matching suggestions have been selected.' : 'No matching suggestions. Press Enter to add a custom entry.';
      }

    function addOption(label, category, custom, value) {
        var item = document.createElement('li');
        item.setAttribute('role', 'presentation');
        var option = document.createElement('button');
        option.type = 'button';
        option.className = 'eh-autocomplete-option' + (custom ? ' eh-autocomplete-option--custom' : '');
        option.id = list.id + '-option-' + renderedOptions.length;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        var labelNode = document.createElement('span');
        labelNode.textContent = label;
        option.appendChild(labelNode);
        if (category) {
          var categoryNode = document.createElement('small');
          categoryNode.textContent = category;
          option.appendChild(categoryNode);
        }
        option.addEventListener('click', function () {
          if (addValue(value || label)) {
            searchInput.value = '';
            renderTags();
            syncFields();
            closeMenu();
            searchInput.focus();
          }
        });
        item.appendChild(option);
        list.appendChild(item);
        renderedOptions.push(option);
      }

      matches.forEach(function (item) {
        addOption(typeof item === 'string' ? item : item.label, typeof item === 'string' ? '' : (item.category || ''), false);
      });
      if (query && matches.length === 0 && !selectedKeys[query]) {
        addOption('Add “' + searchInput.value.trim() + '”', 'Custom entry', true, searchInput.value.trim());
      }
    }
    function addValue(value) {
      value = String(value || '').trim();
      if (!value || selected.some(function (item) { return normalize(item) === normalize(value); })) return false;
      var next = selected.concat([value]);
      if (serialize(next).length > maxLength) {
        if (limitMessage) limitMessage.hidden = false;
        return false;
      }
      if (limitMessage) limitMessage.hidden = true;
      selected = next;
      if (historyFormat) historyTouched = true;
      return true;
    }
    function setActive(index) {
      if (!renderedOptions.length) return;
      activeIndex = (index + renderedOptions.length) % renderedOptions.length;
      renderedOptions.forEach(function (option, optionIndex) {
        var active = optionIndex === activeIndex;
        option.classList.toggle('is-active', active);
        option.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      searchInput.setAttribute('aria-activedescendant', renderedOptions[activeIndex].id);
      if (renderedOptions[activeIndex].scrollIntoView) renderedOptions[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    renderTags();
    syncFields(true);
    if (historyNotes) {
      historyNotes.addEventListener('input', function () {
        historyTouched = true;
        syncFields();
      });
    }
    if (noKnownCheckbox) {
      searchInput.disabled = noKnownCheckbox.checked;
      noKnownCheckbox.addEventListener('change', function () {
        if (noKnownCheckbox.checked && selected.length) {
          if (!window.confirm('Confirm no known ' + (component.dataset.ehAutocompleteKey === 'allergies' ? 'allergies' : 'medical conditions') + ' and clear the entered items?')) {
            noKnownCheckbox.checked = false;
          } else {
            selected = [];
            renderTags();
          }
        }
        searchInput.disabled = noKnownCheckbox.checked;
        closeMenu();
        syncFields();
      });
    }
    searchInput.addEventListener('focus', openMenu);
    searchInput.addEventListener('input', function () {
      if (limitMessage) limitMessage.hidden = true;
      openMenu();
      renderOptions();
    });
    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (menu.hidden) openMenu();
        setActive(activeIndex + 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (menu.hidden) openMenu();
        setActive(activeIndex < 0 ? renderedOptions.length - 1 : activeIndex - 1);
      } else if (event.key === 'Enter') {
        event.preventDefault();
        if (!menu.hidden && activeIndex >= 0 && renderedOptions[activeIndex]) {
          renderedOptions[activeIndex].click();
        } else if (searchInput.value.trim()) {
          var customOption = renderedOptions.filter(function (option) { return option.classList.contains('eh-autocomplete-option--custom'); })[0];
          if (customOption) customOption.click();
        }
      } else if (event.key === 'Escape') {
        closeMenu();
      } else if (event.key === 'Backspace' && searchInput.value === '' && selected.length) {
        selected.pop();
        if (historyFormat) historyTouched = true;
        renderTags();
        syncFields();
        if (!menu.hidden) renderOptions();
      } else if (event.key === 'Tab') {
        closeMenu();
      }
    });
  });

  /* Warn before leaving with unsaved profile or visit entries. One listener only, so
     re-initialising after a page switch never leaves a stale handler behind. */
  if (window.__ehBeforeUnload) window.removeEventListener('beforeunload', window.__ehBeforeUnload);
  window.__ehBeforeUnload = function (event) {
    if (!dirty && !visitDirty) return;
    event.preventDefault();
    event.returnValue = '';
  };
  window.addEventListener('beforeunload', window.__ehBeforeUnload);

  if (!window.__ehAutocompleteOutsideClickBound) {
    window.__ehAutocompleteOutsideClickBound = true;
    document.addEventListener('click', function (event) {
      document.querySelectorAll('.eh-autocomplete').forEach(function (component) {
        if (component.contains(event.target)) return;
        var menu = component.querySelector('[data-eh-menu]');
        var input = component.querySelector('.eh-autocomplete-input');
        if (menu) menu.hidden = true;
        if (input) {
          input.setAttribute('aria-expanded', 'false');
          input.removeAttribute('aria-activedescendant');
        }
      });
    });
  }
}

document.addEventListener('DOMContentLoaded', function () {
  initEmployeeHealth(document.querySelector('.employee-health'));
});

/* Re-initialize when page fragments are loaded via the page switcher. */
window.addEventListener('page:loaded', function () {
  window.setTimeout(function () {
    initEmployeeHealth(document.querySelector('.employee-health'));
  }, 10);
});
