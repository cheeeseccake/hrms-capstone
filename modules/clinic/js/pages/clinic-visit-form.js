// ==========================================================================
// HRMS Capstone — Clinic Visit Form (JS)
// Self-contained behavior for the "Record Clinic Visit" form shown on the
// Employee Health page: visit-type panels, cascading conditional fields,
// wording suggestions, live BMI preview, upload validation, and the
// protected AJAX submit against lib/api/record-clinic-visit.php.
// The page module owns toasts and the unsaved-changes guard, so those
// capabilities are handed in through the options object.
// ==========================================================================

export function initClinicVisitForm(root, options) {
  'use strict';

  var settings = options || {};
  var showToast = typeof settings.showToast === 'function' ? settings.showToast : function () {};
  var onVisitDirtyChange = typeof settings.onVisitDirtyChange === 'function' ? settings.onVisitDirtyChange : function () {};

  var visitDirty = false;

  function setVisitDirty(value) {
    visitDirty = !!value;
    onVisitDirtyChange(visitDirty);
  }

  /* ── Cascading clinic visit form ─────────────────────────────────────── */

  var visitDialog = root.querySelector('[data-eh-visit-panel]');
  var visitForm = root.querySelector('[data-eh-visit-form]');
  if (!visitDialog || !visitForm) return false;

  var dispensePanels = Array.prototype.slice.call(visitForm.querySelectorAll('[data-visit-type="consultation"], [data-visit-type="emergency"]'));
  var inventorySeed = JSON.parse(visitForm.dataset.inventorySeed || '{"items":[],"transactions":[]}');
  var inventoryState = inventorySeed;
  var inventoryApi = visitForm.dataset.inventoryApi;
  var inventoryCsrfToken = visitForm.dataset.csrfToken || '';

  function dateToday() {
    var now = new Date();
    return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
  }

  function isExpiredItem(item) {
    if (!item.expires) return false;
    var expiry = new Date(item.expires + 'T00:00:00');
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    return !Number.isNaN(expiry.getTime()) && expiry < today;
  }

  function availableMedicines(state) {
    return state.items.filter(function (item) {
      return item.category === 'Medicine'
        && Number(item.quantity) > 0
        && item.status !== 'Expired'
        && !isExpiredItem(item);
    });
  }

  function refreshDispensePanels() {
    var medicines = availableMedicines(inventoryState);
    dispensePanels.forEach(function (panel) {
      var select = panel.querySelector('[data-dispense-item]');
      var quantity = panel.querySelector('[data-dispense-quantity]');
      var date = panel.querySelector('[data-dispense-date]');
      var personnel = panel.querySelector('[data-dispense-personnel]');
      var stock = panel.querySelector('[data-dispense-stock]');
      if (!select || !quantity || !date || !personnel) return;
      var previous = select.value;
      select.replaceChildren(new Option('No medicine dispensed', ''));
      medicines.forEach(function (item) {
      select.add(new Option(item.name + (item.strength ? ' ' + item.strength : '') + ' — ' + item.quantity + ' ' + item.unit + ' available', item.id));
      });
      select.value = medicines.some(function (item) { return item.id === previous; }) ? previous : '';
      var selectedItem = medicines.filter(function (item) { return item.id === select.value; })[0];
      quantity.disabled = !selectedItem;
      date.disabled = !selectedItem;
      personnel.disabled = !selectedItem;
      quantity.max = selectedItem ? String(selectedItem.quantity) : '';
      if (selectedItem && !date.value) date.value = dateToday();
      if (!selectedItem) quantity.value = '';
      if (stock) stock.textContent = selectedItem ? 'Available: ' + selectedItem.quantity + ' ' + selectedItem.unit + '.' : '';
    });
  }

  function validateDispensing() {
    var panel = visitForm.querySelector('[data-visit-type="' + visitTypeSelect.value + '"]');
    if (!panel || (visitTypeSelect.value !== 'consultation' && visitTypeSelect.value !== 'emergency')) return true;
    var select = panel.querySelector('[data-dispense-item]');
    if (!select || !select.value) return true;
    var error = panel.querySelector('[data-dispense-error]');
    var quantity = panel.querySelector('[data-dispense-quantity]');
    var personnel = panel.querySelector('[data-dispense-personnel]');
    var item = inventoryState.items.filter(function (candidate) { return candidate.id === select.value; })[0];
    var amount = Number(quantity && quantity.value);
    var message = '';
    if (!item || item.category !== 'Medicine' || item.status === 'Expired' || isExpiredItem(item)) {
      message = 'This medicine is expired or unavailable for dispensing.';
    } else if (!Number.isInteger(amount) || amount <= 0) {
      message = 'Enter a whole-number quantity greater than zero.';
    } else if (amount > Number(item.quantity)) {
      message = 'Only ' + item.quantity + ' ' + item.unit + ' are currently available.';
    } else if (!personnel || !personnel.value.trim()) {
      message = 'Enter the name of the dispensing personnel.';
    }
    if (error) error.textContent = message;
    if (message) {
      var focusTarget = !personnel || !personnel.value.trim() ? personnel : quantity;
      if (focusTarget) focusTarget.focus();
      return false;
    }
    return true;
  }

  function commitDispensing(visit) {
    var panel = visitForm.querySelector('[data-visit-type="' + visitTypeSelect.value + '"]');
    var select = panel && panel.querySelector('[data-dispense-item]');
    if (!select || !select.value) return Promise.resolve();
    var quantity = Number(panel.querySelector('[data-dispense-quantity]').value);
    var personnel = panel.querySelector('[data-dispense-personnel]').value.trim();
    var date = panel.querySelector('[data-dispense-date]').value || dateToday();
    var item = inventoryState.items.filter(function (candidate) { return candidate.id === select.value; })[0];
    if (!item || item.category !== 'Medicine' || item.status === 'Expired' || isExpiredItem(item) || quantity > Number(item.quantity)) {
      return Promise.reject(new Error('The selected medicine stock changed before dispensing could be recorded. Review the inventory and try again.'));
    }
    return fetch(inventoryApi, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        action: 'dispense',
        medicine_id: item.medicineId,
        quantity: quantity,
        date: date,
        personnel: personnel,
        visit_id: visit && (visit.visit_id || visit.id) ? (visit.visit_id || visit.id) : null,
        csrf_token: inventoryCsrfToken
      })
    }).then(function (response) {
      return response.json().catch(function () { throw new Error('Inventory returned an unreadable response.'); })
        .then(function (result) {
          if (!response.ok || !result.success) throw new Error(result.message || 'Medicine could not be dispensed from inventory.');
          inventoryState.items = result.items;
        });
    });
  }

  refreshDispensePanels();
  dispensePanels.forEach(function (panel) {
    var select = panel.querySelector('[data-dispense-item]');
    if (select) select.addEventListener('change', refreshDispensePanels);
  });

  var visitTypeSelect = visitForm.querySelector('[name="visit_type"]');
  var visitTypeStorageKey = 'clinic-visit-type:' + (root.getAttribute('data-employee-id') || 'current');
  var visitError = visitForm.querySelector('[data-eh-visit-error]');
  /* One save button per visit-type panel; all of them share the busy state. */
  var visitSubmitButtons = Array.prototype.slice.call(visitDialog.querySelectorAll('[data-eh-visit-submit]'));
  var visitBusy = false;

  function setVisitButtonsBusy(busy) {
    visitSubmitButtons.forEach(function (button) {
      if (!button.form || button.form !== visitForm) return; /* Another form's button is not ours. */
      button.disabled = busy;
      if (busy) {
        button.dataset.originalText = button.innerHTML;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Saving…';
      } else if (button.dataset.originalText) {
        button.innerHTML = button.dataset.originalText;
      }
    });
  }

  /* The form belongs to the Clinic Visits tab only. The submit footer is
     optional markup; the submit listener works without it. */
  function syncVisitPanelVisibility() {
    var activeTab = root.querySelector('.tab-item.active');
    var activeTabName = activeTab ? activeTab.dataset.tab : 'visits';

    visitDialog.hidden = activeTabName !== 'visits';
  }

  var healthTabNav = root.querySelector('.tab-nav');
  if (healthTabNav) {
    healthTabNav.addEventListener('click', function () {
      window.setTimeout(syncVisitPanelVisibility, 0);
    });
  }

  function updateConsultationBmi() {
    var height = visitForm.querySelector('[data-bmi-height]');
    var weight = visitForm.querySelector('[data-bmi-weight]');
    var output = visitForm.querySelector('[data-bmi-output]');
    if (!height || !weight || !output) return;
    var h = Number(height.value);
    var w = Number(weight.value);
    if (height.value === '' || weight.value === '' || !Number.isFinite(h) || !Number.isFinite(w) || h < 30 || h > 260 || w < 1 || w > 400) {
      output.value = '';
      return;
    }
    output.value = (w / Math.pow(h / 100, 2)).toFixed(2);
  }

  /**
   * Appends a canned phrase to a text field. Phrases are kept on a single
   * ";" separated line, and picking an entry that already exists replaces it
   * instead of duplicating it. Used by the wording chips and the
   * "common options" dropdowns.
   */
  function appendSuggestion(field, suggestion) {
    var current = field.value.trim();
    if (!current) {
      field.value = suggestion;
      return;
    }
    var segments = current.split(/\s*;\s*/);
    var lastSegment = segments[segments.length - 1].toLowerCase();
    if (suggestion.toLowerCase().indexOf(lastSegment) !== -1 || lastSegment.indexOf(suggestion.toLowerCase()) !== -1) {
      segments[segments.length - 1] = suggestion;
      field.value = segments.join('; ');
    } else if (!segments.some(function (item) { return item.toLowerCase() === suggestion.toLowerCase(); })) {
      field.value = current + (/[.!?]$/.test(current) ? ' ' : '; ') + suggestion;
    }
  }

  /**
   * Shows the suggestion buttons matching what has been typed in a field.
   * The row appears only while the field has text, so an untouched field
   * stays clean. Matches are ranked so the closest one is first and stays
   * highlighted, and Enter inserts it.
   */
  function filterSuggestions(group, field, options) {
    var suggestionButtons = options.buttons;
    var emptyMessage = options.emptyMessage;
    var setActiveSuggestion = options.setActive;
    var term = (field && field.value || '').trim().toLowerCase();

    if (!term) {
      suggestionButtons.forEach(function (button) { button.hidden = false; });
      setActiveSuggestion(null);
      if (emptyMessage) emptyMessage.hidden = true;
      group.hidden = true;
      return;
    }

    var matches = [];
    suggestionButtons.forEach(function (button) {
      var text = button.textContent.trim().toLowerCase();
      var position = text.indexOf(term);
      var visible = position !== -1;
      button.hidden = !visible;
      if (visible) matches.push({ button: button, score: position * 1000 + Math.max(0, text.length - term.length) });
    });
    matches.sort(function (a, b) { return a.score - b.score; });
    setActiveSuggestion(matches.length ? matches[0].button : null);
    if (emptyMessage) emptyMessage.hidden = matches.length !== 0;
    group.hidden = false;
  }

  visitForm.querySelectorAll('[data-suggestion-target]').forEach(function (group) {
    var field = document.getElementById(group.getAttribute('data-suggestion-target'));
    var suggestionButtons = Array.prototype.slice.call(group.querySelectorAll('button'));
    var emptyMessage = group.querySelector('.eh-visit-suggestion-empty');
    var activeSuggestion = null;
    function setActiveSuggestion(button) {
      activeSuggestion = button || null;
      suggestionButtons.forEach(function (item) {
        var active = item === activeSuggestion;
        item.classList.toggle('is-active', active);
        if (active) item.setAttribute('aria-current', 'true');
        else item.removeAttribute('aria-current');
      });
    }
    var filter = function () { filterSuggestions(group, field, { buttons: suggestionButtons, emptyMessage: emptyMessage, setActive: setActiveSuggestion }); };
    if (field) {
      field.addEventListener('input', filter);
      field.addEventListener('keydown', function (event) {
        if (group.hidden) return;
        var visibleButtons = suggestionButtons.filter(function (button) { return !button.hidden; });
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          if (!visibleButtons.length) return;
          event.preventDefault();
          var currentIndex = visibleButtons.indexOf(activeSuggestion);
          var direction = event.key === 'ArrowDown' ? 1 : -1;
          var nextIndex = currentIndex === -1 ? (direction > 0 ? 0 : visibleButtons.length - 1) : (currentIndex + direction + visibleButtons.length) % visibleButtons.length;
          setActiveSuggestion(visibleButtons[nextIndex]);
        } else if (event.key === 'Enter' && activeSuggestion && !event.isComposing) {
          event.preventDefault();
          activeSuggestion.click();
        } else if (event.key === 'Escape') {
          group.hidden = true;
          setActiveSuggestion(null);
        }
      });
    }
    group.addEventListener('click', function (event) {
      var button = event.target.closest('button');
      if (!button) return;
      if (!field || field.disabled) return;
      appendSuggestion(field, button.textContent.trim());
      field.dispatchEvent(new Event('input', { bubbles: true }));
      group.hidden = true;
      field.focus();
      setVisitDirty(true);
    });
  });
  visitForm.querySelectorAll('[data-bmi-height], [data-bmi-weight]').forEach(function (field) {
    field.addEventListener('input', updateConsultationBmi);
  });

  visitForm.querySelectorAll('[data-field-error]').forEach(function (error, index) {
    error.id = 'ehVisitFieldError' + index;
    var field = error.parentElement && error.parentElement.querySelector('input, select, textarea');
    if (!field) return;
    /* Keep the list de-duplicated: markup already names some error spans. */
    var describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    if (describedBy.indexOf(error.id) === -1) describedBy.push(error.id);
    field.setAttribute('aria-describedby', describedBy.join(' '));
  });

  function setVisitError(message) {
    visitError.textContent = message || '';
    visitError.hidden = !message;
  }

  function setFieldError(name, message) {
    var activePanel = visitForm.querySelector('[data-visit-type="' + visitTypeSelect.value + '"]');
    var error = activePanel && activePanel.querySelector('[data-field-error="' + name + '"]');
    if (!error) error = visitForm.querySelector(':scope > .eh-visit-grid [data-field-error="' + name + '"]');
    if (error) error.textContent = message || '';
    var fields = activePanel ? activePanel.querySelectorAll('[name="' + name + '"]') : [];
    Array.prototype.forEach.call(fields, function (field) {
      if (message) field.setAttribute('aria-invalid', 'true');
      else field.removeAttribute('aria-invalid');
    });
    visitForm.querySelectorAll(':scope > .eh-visit-grid [name="' + name + '"]').forEach(function (field) {
      if (message) field.setAttribute('aria-invalid', 'true');
      else field.removeAttribute('aria-invalid');
    });
  }

  function syncVisitFields() {
    var selectedType = visitTypeSelect.value;
    visitForm.querySelectorAll('[data-visit-type]').forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-visit-type') !== selectedType;
      panel.disabled = panel.getAttribute('data-visit-type') !== selectedType;
    });

    if (selectedType === 'return_to_work') {
      loadPrevVisitData();
    }

    visitForm.querySelectorAll('[data-visit-condition]').forEach(function (section) {
      var panel = section.closest('[data-visit-type]');
      var conditions = (section.getAttribute('data-visit-condition') || '').split('&');
      var visible = !!panel && !panel.disabled && conditions.every(function (condition) {
        var parts = condition.split('=');
        var trigger = panel && parts[0] ? panel.querySelector('[name="' + parts[0] + '"]') : null;
        return !!trigger && trigger.value === parts[1];
      });
      section.hidden = !visible;
      section.querySelectorAll('input, select, textarea').forEach(function (field) {
        field.disabled = !visible;
        field.required = visible && field.name === 'followup_date';
        if (!visible) setFieldError(field.name, '');
      });
    });
    var reasonLabel = visitForm.querySelector('[data-reason-label]');
    var reasonField = visitForm.querySelector('[name="reason"]');
    var reasonSuggestions = visitForm.querySelector('[data-suggestion-target="ehVisitReason"]');
    if (reasonField) reasonField.readOnly = selectedType === 'return_to_work';
    if (reasonSuggestions && selectedType === 'return_to_work') reasonSuggestions.hidden = true;
    if (reasonLabel) reasonLabel.firstChild.textContent = selectedType === 'emergency'
      ? 'Complaint, incident, or emergency description '
      : (selectedType === 'return_to_work' ? 'Reason for return-to-work visit ' : 'Reason for visit / chief complaint ');
  }

  function loadPrevVisitData() {
    var panel = visitForm.querySelector('[data-visit-type="return_to_work"]');
    if (!panel) return;

    var reasonField = visitForm.querySelector('[name="reason"]');
    var restAdvisedReason = panel.dataset.prevReason || '';
    if (reasonField && !reasonField.value.trim() && restAdvisedReason) {
      reasonField.value = restAdvisedReason;
    }

    var prevAbsenceStart = panel.dataset.prevAbsenceStart || '';
    var prevAbsenceEnd = panel.dataset.prevAbsenceEnd || '';
    var startDate = visitForm.querySelector('#ehAbsenceStart');
    var endDate = visitForm.querySelector('#ehAbsenceEnd');

    if (prevAbsenceStart) {
      var parts = prevAbsenceStart.split(' ');
      startDate.value = parts[0];
    }
    if (prevAbsenceEnd) {
      var parts = prevAbsenceEnd.split(' ');
      endDate.value = parts[0];
    }
  }

  /* Current Asia/Manila wall-clock time as a datetime-local value (YYYY-MM-DDTHH:MM). */
  function manilaNowLocal() {
    var parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    }).formatToParts(new Date()).reduce(function (acc, item) { acc[item.type] = item.value; return acc; }, {});
    return parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
  }

  function resetVisitForm() {
    visitForm.reset();
    visitForm.querySelector('[name="visit_at"]').value = manilaNowLocal();
    visitForm.querySelectorAll('[data-field-error]').forEach(function (error) { error.textContent = ''; });
    visitForm.querySelectorAll('[aria-invalid="true"]').forEach(function (field) { field.removeAttribute('aria-invalid'); });
    visitForm.querySelectorAll('[data-suggestion-target]').forEach(function (group) {
      group.hidden = true;
      group.querySelectorAll('button').forEach(function (button) { button.hidden = false; });
      var empty = group.querySelector('.eh-visit-suggestion-empty');
      if (empty) empty.hidden = true;
    });
    visitForm.querySelectorAll('[data-eh-visit-file]').forEach(function (input) {
      var field = input.closest('.eh-form-field');
      var label = field && field.querySelector('[data-file-name]');
      var remove = field && field.querySelector('[data-remove-file]');
      if (label) label.textContent = 'No file selected';
      if (remove) remove.hidden = true;
    });
    setVisitError('');
    updateConsultationBmi();
    setVisitDirty(false);
    syncVisitFields();
    refreshDispensePanels();
  }

  visitTypeSelect.addEventListener('change', function () {
    try { window.sessionStorage.setItem(visitTypeStorageKey, visitTypeSelect.value); } catch (error) { /* Storage may be unavailable. */ }
    syncVisitFields();
    setVisitError('');
    setVisitDirty(true);
  });
  visitForm.addEventListener('input', function (event) {
    if (event.target.matches('[data-eh-visit-file]')) return;
    setVisitDirty(true);
    var name = event.target.name;
    if (name) setFieldError(name, '');
  });
  visitForm.addEventListener('change', function (event) {
    if (event.target.matches('[data-eh-visit-file]')) return;
    if (event.target.name) setFieldError(event.target.name, '');
    syncVisitFields();
    setVisitDirty(true);
  });

  visitForm.querySelectorAll('[data-eh-visit-file]').forEach(function (input) {
    input.addEventListener('change', function () {
      var field = input.closest('.eh-form-field');
      var label = field && field.querySelector('[data-file-name]');
      var remove = field && field.querySelector('[data-remove-file]');
      var file = input.files && input.files[0];
      setFieldError(input.name, '');
      if (file && file.size > 10 * 1024 * 1024) {
        setFieldError(input.name, 'Choose a file no larger than 10 MB.');
        input.value = '';
        file = null;
      } else if (file && (!/\.(pdf|jpe?g|png)$/i.test(file.name) || (file.type !== '' && !/^(application\/pdf|image\/jpeg|image\/png)$/.test(file.type)))) {
        setFieldError(input.name, 'Choose a PDF, JPG, or PNG file.');
        input.value = '';
        file = null;
      }
      if (label) label.textContent = file ? file.name : 'No file selected';
      if (remove) remove.hidden = !file;
      setVisitDirty(true);
    });
    var fileField = input.closest('.eh-form-field');
    var removeButton = fileField && fileField.querySelector('[data-remove-file]');
    if (removeButton) {
      removeButton.addEventListener('click', function () {
        input.value = '';
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  });

  function formatVisitDate(value) {
    var date = new Date(String(value || '').replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value || '') : date.toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function appendVisitRow(visit) {
    var body = root.querySelector('.eh-clinic-visits-card .eh-card-body');
    if (!body) return;
    var table = body.querySelector('.eh-visit-table');
    if (!table) {
      body.replaceChildren();
      var wrap = document.createElement('div');
      wrap.className = 'eh-table-wrap';
      table = document.createElement('table');
      table.className = 'eh-table eh-visit-table';
      var thead = document.createElement('thead');
      var headRow = document.createElement('tr');
      ['Date and time', 'Visit type', 'Reason'].forEach(function (label) {
        var th = document.createElement('th'); th.textContent = label; headRow.appendChild(th);
      });
      thead.appendChild(headRow);
      table.append(thead, document.createElement('tbody'));
      wrap.appendChild(table);
      body.appendChild(wrap);
    }
    var labels = { consultation: 'Consultation', emergency: 'Emergency', return_to_work: 'Return to Work' };
    var row = document.createElement('tr');
    [['Date and time', formatVisitDate(visit.date)], ['Visit type', labels[visit.type] || 'Clinic visit']].forEach(function (pair) {
      var td = document.createElement('td'); td.setAttribute('data-label', pair[0]); td.textContent = pair[1] || ''; row.appendChild(td);
    });
    var reasonCell = document.createElement('td');
    reasonCell.setAttribute('data-label', 'Reason');
    var reasonText = document.createElement('div');
    reasonText.textContent = visit.reason || '';
    reasonCell.appendChild(reasonText);
    var detailLabels = {
      employee_report: 'Employee-reported information', relevant_information: 'Relevant information provided', additional_notes: 'Additional notes',
      assistance_provided: 'Assistance provided', advice_given: 'Advice or instructions', blood_pressure: 'Blood pressure',
      pulse_rate: 'Pulse rate', respiratory_rate: 'Respiratory rate', temperature: 'Temperature', oxygen_saturation: 'Oxygen saturation',
      height_cm: 'Height (cm)', weight_kg: 'Weight (kg)', bmi: 'BMI (calculated)', physician_assessment: 'Physician assessment performed',
      physician_name: 'Physician name', physician_identifier: 'Physician identifier', physician_assessment_at: 'Physician assessment date and time',
      physician_findings: 'Physician clinical findings', physician_diagnosis: 'Physician-documented diagnosis', physician_plan: 'Physician treatment or management plan',
      physician_advice: 'Physician medical advice', physician_followup: 'Physician-recommended follow-up', rest_advised: 'Rest advised', rest_duration: 'Rest duration', rest_duration_preset: 'Rest duration (preset)', rest_duration_unit: 'Rest duration unit',
      rest_start_date: 'Rest start date', rest_end_date: 'Rest end date', rest_instructions: 'Rest instructions', rest_followup: 'Rest recommendation follow-up', work_advice: 'Work advice',
      work_return_at: 'Recommended return-to-work date and time', work_rest_notes: 'Work rest instructions or notes', work_referral_destination: 'Work advice referral destination',
      work_referral_notes: 'Work advice referral notes', work_referral_followup: 'Work advice follow-up instructions',
      work_documentation_required: 'Work documentation required', work_documentation_due: 'Expected documentation date',
      work_documentation_notes: 'Work documentation follow-up notes', consult_work_restrictions: 'Consultation work restrictions',
      work_advice_notes: 'Other work advice', referral: 'External referral',
      referral_destination: 'Referral destination', referral_details: 'Referral details', outcome: 'Outcome or disposition',
      followup_required: 'Follow-up required', followup_date: 'Follow-up date', followup_notes: 'Follow-up notes',
      incident_at: 'Incident date and time', incident_location: 'Incident location', circumstances: 'Circumstances',
      accident_type: 'Type of accident or injury',
      first_aid: 'First aid or basic assistance', actions_taken: 'Actions taken', assistance_at: 'Time assistance was provided',
      response_notes: 'Response notes',
      external_contacted: 'External service contacted',
      transfer_occurred: 'Transfer or transport', transfer_details: 'Transfer details', contact_person: 'Person or service contacted',
      followup_instructions: 'Follow-up instructions', absence_start: 'Absence start date', absence_end: 'Absence end date',
      expected_return_date: 'Expected or documented return date', documentation_status: 'Documentation status',
      documentation_notes: 'Documentation notes', work_restrictions: 'Documented work restrictions',
      accommodations: 'Recommended accommodations', supporting_notes: 'Supporting documentation or notes'
    };
    var detailEntries = Object.keys(visit.details || {}).filter(function (key) {
      var value = visit.details[key];
      return Object.prototype.hasOwnProperty.call(detailLabels, key) && value !== null && value !== '' && typeof value !== 'object';
    });
    if (detailEntries.length) {
      var details = document.createElement('details'); details.className = 'eh-visit-details';
      var summary = document.createElement('summary'); summary.textContent = 'View visit details'; details.appendChild(summary);
      var detailList = document.createElement('dl');
      detailEntries.forEach(function (key) {
        var item = document.createElement('div');
        var term = document.createElement('dt'); term.textContent = detailLabels[key];
        var description = document.createElement('dd');
        var value = visit.details[key];
        if (key === 'documentation_status') value = ({ documentation_received: 'Documentation received', awaiting_documentation: 'Awaiting documentation', referred_for_assessment: 'Referred for assessment', other: 'Other' })[value] || value;
        if (key === 'physician_assessment') value = ({ yes: 'Yes', no: 'No' })[value] || value;
        if (key === 'rest_advised') value = ({ not_specified: 'Not specified', yes: 'Yes', no: 'No' })[value] || value;
        if (key === 'work_advice') value = ({ not_specified: 'Not specified', usual: 'May return to work as usual', rest: 'Advised to rest before returning to work', assessment: 'Referred for medical assessment', documentation: 'Return-to-work documentation required', restrictions: 'Documented work restrictions', other: 'Other' })[value] || value;
        if (key === 'rest_duration_preset') value = ({ '2_hours': '2 hours', '4_hours': '4 hours', half_day: 'Half day', '1_day': '1 day', '2_days': '2 days', '3_days': '3 days', custom: 'Custom' })[value] || value;
        description.textContent = typeof value === 'boolean' ? (value ? 'Yes' : 'No') : String(value);
        item.append(term, description); detailList.appendChild(item);
      });
      details.appendChild(detailList); reasonCell.appendChild(details);
    }
    row.appendChild(reasonCell);
    table.querySelector('tbody').prepend(row);
  }

  /* No submit button ships with the form, so Enter in a single-line field
     is the save trigger. Textareas keep Enter for line breaks, and a
     suggestion menu that already handled the key is left alone. */
  visitForm.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.defaultPrevented || visitBusy) return;
    var field = event.target;
    if (!field || field.tagName === 'TEXTAREA' || field.isContentEditable) return;
    event.preventDefault();
    if (typeof visitForm.requestSubmit === 'function') visitForm.requestSubmit();
    else visitForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });

  visitForm.addEventListener('submit', function (event) {
    event.preventDefault();
    if (visitBusy) return;
    setVisitError('');
    visitForm.querySelectorAll('[data-field-error]').forEach(function (error) { error.textContent = ''; });
    visitForm.querySelectorAll('[data-dispense-error]').forEach(function (error) { error.textContent = ''; });
    if (!validateDispensing()) return;
    if (!visitForm.reportValidity()) {
      var invalid = visitForm.querySelector(':invalid:not(:disabled)');
      if (invalid) invalid.focus();
      return;
    }

    var visitAtField = visitForm.querySelector('[name="visit_at"]');
    if (visitAtField && visitAtField.value > manilaNowLocal()) {
      setFieldError('visit_at', 'Visit date and time cannot be in the future.');
      visitAtField.focus();
      return;
    }

    visitBusy = true;
    var abortController = typeof AbortController === 'function' ? new AbortController() : null;
    var abortTimer = abortController ? window.setTimeout(function () { abortController.abort(); }, 30000) : null;
    setVisitButtonsBusy(true);
    fetch(visitForm.action, {
      method: 'POST',
      body: new FormData(visitForm),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
      signal: abortController ? abortController.signal : undefined
    }).then(function (response) {
      return response.json().catch(function () { throw new Error('The server returned an unreadable response.'); })
        .then(function (payload) { if (!response.ok || !payload.success) { var err = new Error(payload.message || 'The clinic visit could not be saved.'); err.payload = payload; throw err; } return payload; });
    }).then(function (payload) {
      if (!visitEditing) {
        return commitDispensing(payload.visit).then(function () { return payload; }).catch(function (error) {
          error.visitSaved = true;
          throw error;
        });
      }
      return payload;
    }).then(function (payload) {
      if (!visitEditing) appendVisitRow(payload.visit);
      showToast(payload.message || 'Clinic visit recorded successfully.', 'success');

      /* Back to the employee's health record, on the Clinic Visits tab, so the
         freshly saved visit appears in the server-rendered table. The API sends
         the exact return address; the local fallback assumes the current page. */
      setVisitDirty(false);
      var employeeId = root.getAttribute('data-employee-id') || new URLSearchParams(window.location.search).get('employee_id') || '';
      var returnUrl = typeof payload.redirect === 'string' && payload.redirect !== ''
        ? payload.redirect
        : '?page=employee-health&employee_id=' + encodeURIComponent(employeeId) + '&section=visits';

      window.setTimeout(function () {
        window.location.assign(returnUrl);
      }, 400);
    }).catch(function (error) {
      var payload = error.payload || {};
      var failureMessage = error.message || 'The clinic visit could not be saved.';
      if (error.visitSaved) failureMessage = 'The visit was saved, but inventory dispensing could not be recorded: ' + failureMessage;
      if (error.name === 'AbortError') failureMessage = 'The request timed out. Check your connection, then try again; the visit was not recorded unless it appears in the list.';
      else if (error instanceof TypeError) failureMessage = 'Could not reach the server. Check your connection, then try again.';
      setVisitError(failureMessage);
      Object.keys(payload.field_errors || {}).forEach(function (name) { setFieldError(name, payload.field_errors[name]); });
      var firstError = visitForm.querySelector('[aria-invalid="true"]:not(:disabled)');
      if (firstError) firstError.focus();
    }).finally(function () {
      if (abortTimer) window.clearTimeout(abortTimer);
      visitBusy = false;
      setVisitButtonsBusy(false);
    });
  });

  /* resetVisitForm() pre-fills the required visit date and time with the
      current Asia/Manila time and ends with syncVisitFields(), so it replaces
      the bare sync call here: without it the required field starts empty and
      reportValidity() silently blocks every save. */
  resetVisitForm();

  /* ── Editing an existing visit ─────────────────────────────────────────── */

  var visitEditPayload = null;
  try {
    var visitEditRaw = visitForm.getAttribute('data-visit-edit');
    visitEditPayload = visitEditRaw ? JSON.parse(visitEditRaw) : null;
  } catch (error) {
    visitEditPayload = null;
  }

  function toLocalDateTime(value) {
    var text = String(value || '').trim().replace(' ', 'T');
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text) ? text.slice(0, 16) : '';
  }

  function toLocalDate(value) {
    var text = String(value || '').trim().replace(' ', 'T');
    return /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(0, 10) : '';
  }

  var editDateTimeFields = ['incident_at', 'assistance_at', 'physician_assessment_at', 'work_return_at'];
  var editDateFields = ['rest_start_date', 'rest_end_date', 'work_documentation_due', 'followup_date', 'absence_start', 'absence_end', 'expected_return_date'];
  var editSkipFields = ['bmi', 'rest_duration'];

  function applyVisitEdit(payload) {
    if (!payload) return false;
    if (payload.visit_type) visitTypeSelect.value = payload.visit_type;

    var visitAt = visitForm.querySelector('[name="visit_at"]');
    if (visitAt && payload.visit_at) visitAt.value = toLocalDateTime(payload.visit_at);

    var reason = visitForm.querySelector('[name="reason"]');
    if (reason) reason.value = payload.reason || '';

    var details = payload.details || {};
    Object.keys(details).forEach(function (key) {
      if (editSkipFields.indexOf(key) !== -1) return;
      var field = visitForm.querySelector('[name="' + key + '"]');
      if (!field) return;
      var value = details[key];
      if (value === null || value === undefined || value === '') return;
      if (typeof value === 'boolean') value = value ? 'yes' : 'no';
      else if (editDateTimeFields.indexOf(key) !== -1) value = toLocalDateTime(value);
      else if (editDateFields.indexOf(key) !== -1) value = toLocalDate(value);
      else if (typeof value === 'number') value = String(value);
      if (value === '') return;
      field.value = value;
    });

    /* Certificates already stored with the visit stay attached; a new upload
       adds to them instead of replacing the stored file. */
    (payload.documents || []).forEach(function (documentInfo) {
      var input = visitForm.querySelector('[name="' + (documentInfo.type === 'fit_to_work' ? 'fit_to_work' : 'medical_certificate') + '"]');
      if (!input) return;
      var wrapper = input.closest('.eh-form-field');
      var label = wrapper && wrapper.querySelector('[data-file-name]');
      if (label) label.textContent = (documentInfo.name || 'Attached certificate') + ' (already attached)';
    });

    syncVisitFields();
    updateConsultationBmi();
    setVisitDirty(false);
    return true;
  }

  var visitEditing = applyVisitEdit(visitEditPayload);
  if (visitEditing) {
    visitForm.querySelectorAll('[data-clinic-dispense]').forEach(function (field) { field.hidden = true; });
  }

  var requestedVisitType = new URLSearchParams(window.location.search).get('visit_type');
  var rememberedVisitType = '';
  try { rememberedVisitType = window.sessionStorage.getItem(visitTypeStorageKey) || ''; } catch (error) { /* Storage may be unavailable. */ }
  var visitTypes = ['consultation', 'emergency', 'return_to_work'];
  if (!visitEditing) {
    if (visitTypes.indexOf(requestedVisitType) !== -1) {
      visitTypeSelect.value = requestedVisitType;
    } else if (visitTypes.indexOf(rememberedVisitType) !== -1) {
      visitTypeSelect.value = rememberedVisitType;
    }
  }
  syncVisitFields();
  syncVisitPanelVisibility();

  return true;
}
