// ==========================================================================
// HRMS Capstone — Clinic Inventory Management Page (JS)
// Live medicine inventory backed by cm_medicine_inventory and its movement
// ledger, plus filtering, pagination, stock-in, item actions, and toasts.
// ==========================================================================

var INVENTORY_STATUS_CLASS = {
  'Available': 'is-available',
  'Low Stock': 'is-low',
  'Out of Stock': 'is-out',
  'Expiring Soon': 'is-expiring',
  'Expired': 'is-expired'
};

function clearInventoryAlertHost() {
  var host = document.getElementById('clinicInventoryAlertHost');
  if (!host) return;
  host.querySelectorAll('.clinic-inventory__notification').forEach(function (notification) {
    window.clearTimeout(notification.inventoryDismissTimer);
  });
  host.replaceChildren();
}

function initInventory(root) {
  'use strict';

  if (!root || root.dataset.inventoryInit === '1') return;
  root.dataset.inventoryInit = '1';

  /* ── Element references ─────────────────────────────────────────────── */
  var table = root.querySelector('[data-inventory-table]');
  var tableBody = table ? table.querySelector('tbody') : null;
  var resultCount = root.querySelector('[data-inventory-count]');
  var noResultsRow = root.querySelector('[data-inventory-no-results]');
  var pagination = root.querySelector('[data-inventory-pagination]');
  var pageInfo = root.querySelector('[data-inventory-page-info]');
  var pageNumbers = root.querySelector('[data-inventory-page-numbers]');
  var previousPageButton = root.querySelector('[data-inventory-page-previous]');
  var nextPageButton = root.querySelector('[data-inventory-page-next]');
  var metricFilters = Array.prototype.slice.call(root.querySelectorAll('[data-inventory-metric]'));
  var inventorySection = root.querySelector('#inventory-items');
  var activeCategoryFilter = '';
  var activeStatusFilter = '';
  var currentPage = 1;
  var pageSize = 15;

  var itemForm = root.querySelector('[data-inventory-item-form]');
  var itemFormTitle = root.querySelector('[data-inventory-form-title]');
  var itemFormSubmit = itemForm ? itemForm.querySelector('[data-inventory-form-submit]') : null;
  var itemFormMode = root.querySelector('[data-inventory-form-mode]');
  var itemFormEditingId = root.querySelector('[data-inventory-editing-id]');
  var itemFormSuccess = root.querySelector('[data-inventory-form-success]');
  var itemFormSection = document.getElementById('inventory-form');

  var stockInForm = root.querySelector('[data-inventory-stock-in]');
  var stockInDialog = root.querySelector('[data-inventory-stock-in-dialog]');
  var stockInOpenButton = root.querySelector('[data-inventory-stock-in-open]');
  var expirationTableBody = root.querySelector('[data-inventory-expiration-table] tbody');
  var transactionsBody = root.querySelector('[data-inventory-transactions] tbody');
  var dialog = root.querySelector('[data-inventory-dialog]');
  var inventoryApi = root.dataset.inventoryApi;
  var csrfToken = root.dataset.csrfToken || '';

  /* ── Toast helper ───────────────────────────────────────────────────── */

  function showToast(message, type) {
    var stack = document.querySelector('.clinic-inventory__toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'clinic-inventory__toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    var toast = document.createElement('div');
    toast.className = 'clinic-inventory__toast clinic-inventory__toast--' + (type === 'error' ? 'error' : 'success');
    toast.textContent = message;
    stack.appendChild(toast);
    window.setTimeout(function () {
      toast.classList.add('is-leaving');
      window.setTimeout(function () { toast.remove(); }, 200);
    }, 2600);
  }

  /* ── Formatting and status helpers ──────────────────────────────────── */

  function formatDate(iso) {
    if (!iso) return '';
    var date = new Date(iso + (iso.length === 10 ? 'T00:00:00' : ''));
    if (isNaN(date.getTime())) return iso;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function todayIso() {
    var now = new Date();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    return now.getFullYear() + '-' + month + '-' + day;
  }

  function statusClass(status) {
    return INVENTORY_STATUS_CLASS[status] || '';
  }

  function itemRows() {
    return tableBody ? Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-id]')) : [];
  }

  function refreshItemSelects() {
    if (stockInForm) {
      var select = stockInForm.querySelector('select[name="item_name"]');
      if (!select) return;
      var previous = select.value;
      select.textContent = '';
      var rows = itemRows();
      if (rows.length === 0) {
        var emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'No inventory items available';
        select.appendChild(emptyOption);
        select.disabled = true;
      } else {
        select.disabled = false;
        rows.forEach(function (row) {
          var option = document.createElement('option');
          option.value = row.dataset.id;
          option.textContent = row.dataset.name + ' (' + row.dataset.id + ')';
          select.appendChild(option);
        });
        select.value = rows.some(function (row) { return row.dataset.id === previous; })
          ? previous
          : rows[0].dataset.id;
      }
    }
  }

  /* ── Row rendering ──────────────────────────────────────────────────── */

  function buildRow(item) {
    var row = document.createElement('tr');
    row.dataset.id = item.id;
    row.dataset.medicineId = String(item.medicineId || String(item.id).replace(/^MED-/, ''));
    row.dataset.name = item.name;
    row.dataset.category = item.category;
    row.dataset.quantity = String(item.quantity);
    row.dataset.unit = item.unit;
    row.dataset.minimum = String(item.minimum);
    row.dataset.expires = item.expires || '';
    row.dataset.description = item.description || '';
    row.dataset.supplier = item.supplier || '';
    row.dataset.batch = item.batch || '';
    row.dataset.storage = item.storage || '';
    row.dataset.received = item.received || '';
    row.dataset.dosageForm = item.dosageForm || 'Other';
    row.dataset.strength = item.strength || '';
    row.dataset.status = item.status;
    row.dataset.search = (item.id + ' ' + item.name + ' ' + item.category).toLowerCase();

    var codeCell = document.createElement('td');
    codeCell.className = 'clinic-inventory__code';
    codeCell.textContent = item.id;

    var nameCell = document.createElement('td');
    var nameStrong = document.createElement('strong');
    nameStrong.textContent = item.name + (item.strength ? ' ' + item.strength : '');
    var nameSmall = document.createElement('small');
    nameSmall.textContent = 'Minimum stock: ' + item.minimum + ' ' + item.unit;
    nameCell.appendChild(nameStrong);
    nameCell.appendChild(nameSmall);

    var categoryCell = document.createElement('td');
    categoryCell.textContent = item.category;

    var quantityCell = document.createElement('td');
    quantityCell.appendChild(document.createTextNode(String(item.quantity) + ' '));
    var quantityUnit = document.createElement('span');
    quantityUnit.className = 'clinic-inventory__unit';
    quantityUnit.textContent = item.unit;
    quantityCell.appendChild(quantityUnit);

    var expiryCell = document.createElement('td');
    if (item.expires) {
      expiryCell.textContent = formatDate(item.expires);
    } else {
      var muted = document.createElement('span');
      muted.className = 'clinic-inventory__muted';
      muted.textContent = 'Not applicable';
      expiryCell.appendChild(muted);
    }

    var statusCell = document.createElement('td');
    var statusPill = document.createElement('span');
    statusPill.className = 'clinic-inventory__status ' + statusClass(item.status);
    statusPill.textContent = item.status;
    statusCell.appendChild(statusPill);

    var actionCell = document.createElement('td');
    var actionGroup = document.createElement('div');
    actionGroup.className = 'clinic-inventory__row-actions';
    [
      { attr: 'data-inventory-view', icon: 'eye', label: 'View', cls: '' },
      { attr: 'data-inventory-edit', icon: 'pen-to-square', label: 'Edit', cls: '' }
    ].forEach(function (action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'clinic-inventory__icon-btn' + action.cls;
      button.setAttribute(action.attr, '');
      button.title = action.label;
      button.setAttribute('aria-label', action.label + ' ' + item.name);
      var icon = document.createElement('i');
      icon.className = 'fa-solid fa-' + action.icon;
      icon.setAttribute('aria-hidden', 'true');
      button.appendChild(icon);
      actionGroup.appendChild(button);
    });
    actionCell.appendChild(actionGroup);

    [codeCell, nameCell, categoryCell, quantityCell, expiryCell, statusCell, actionCell]
      .forEach(function (cell) { row.appendChild(cell); });
    return row;
  }

  function rowToItem(row) {
    return {
      id: row.dataset.id,
      medicineId: parseInt(row.dataset.medicineId, 10),
      name: row.dataset.name,
      category: row.dataset.category,
      quantity: parseInt(row.dataset.quantity, 10) || 0,
      unit: row.dataset.unit,
      minimum: parseInt(row.dataset.minimum, 10) || 0,
      expires: row.dataset.expires || '',
      description: row.dataset.description || '',
      supplier: row.dataset.supplier || '',
      batch: row.dataset.batch || '',
      storage: row.dataset.storage || '',
      received: row.dataset.received || '',
      dosageForm: row.dataset.dosageForm || 'Other',
      strength: row.dataset.strength || '',
      status: row.dataset.status
    };
  }

  var inventoryState = JSON.parse(root.dataset.inventorySeed || '{"items":[],"transactions":[]}');

  function renderTransaction(transaction) {
    if (!transactionsBody) return;
    var row = document.createElement('tr');
    [
      { className: 'clinic-inventory__code', text: transaction.id },
      { text: transaction.name },
      { text: (Number(transaction.quantity) > 0 ? '+' : '') + transaction.quantity },
      { text: formatDate(transaction.date) }
    ].forEach(function (value) {
      var cell = document.createElement('td');
      if (value.className) cell.className = value.className;
      cell.textContent = value.text;
      row.appendChild(cell);
    });
    transactionsBody.appendChild(row);
  }

  function renderExpirationRows() {
    if (!expirationTableBody) return;
    expirationTableBody.replaceChildren();
    inventoryState.items.filter(function (item) {
      return item.expires && (item.status === 'Expiring Soon' || item.status === 'Expired');
    }).forEach(function (item) {
      var row = document.createElement('tr');
      var values = [item.name + (item.strength ? ' ' + item.strength : ''), String(item.quantity), formatDate(item.expires)];
      values.forEach(function (value, index) {
        var cell = document.createElement('td');
        if (index === 0) {
          var name = document.createElement('strong');
          name.textContent = value;
          cell.appendChild(name);
        } else {
          cell.textContent = value;
        }
        row.appendChild(cell);
      });
      var statusCell = document.createElement('td');
      var status = document.createElement('span');
      status.className = 'clinic-inventory__status ' + statusClass(item.status);
      status.textContent = item.status;
      statusCell.appendChild(status);
      row.appendChild(statusCell);
      expirationTableBody.appendChild(row);
    });
  }

  function requestInventory(action, payload) {
    return fetch(inventoryApi, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(Object.assign({}, payload, { action: action, csrf_token: csrfToken }))
    }).then(function (response) {
      return response.json().catch(function () { throw new Error('The inventory service returned an unreadable response.'); })
        .then(function (result) {
          if (!response.ok || !result.success) throw new Error(result.message || 'The inventory request could not be completed.');
          return result;
        });
    });
  }

  function replaceInventoryItems(items, transactions) {
    inventoryState.items = items;
    if (tableBody) {
      tableBody.replaceChildren();
      items.forEach(function (item) {
        tableBody.appendChild(buildRow(item));
      });
    }
    renderExpirationRows();
    if (transactions) {
      inventoryState.transactions = transactions;
      if (transactionsBody) {
        transactionsBody.replaceChildren();
        transactions.forEach(renderTransaction);
      }
    }
    refreshItemSelects();
    applyFilters();
  }

  if (tableBody) {
    tableBody.replaceChildren();
    inventoryState.items.forEach(function (item) {
      tableBody.appendChild(buildRow(item));
    });
  }
  renderExpirationRows();
  if (transactionsBody) {
    transactionsBody.replaceChildren();
    inventoryState.transactions.forEach(renderTransaction);
  }

  /* ── Filtering ──────────────────────────────────────────────────────── */

  function applyFilters() {
    var filteredRows = itemRows().filter(function (row) {
      var matchesCategory = activeCategoryFilter === '' || row.dataset.category === activeCategoryFilter;
      var matchesStatus = activeStatusFilter === '' || row.dataset.status === activeStatusFilter;
      return matchesCategory && matchesStatus;
    });
    var visible = filteredRows.length;
    var totalPages = Math.ceil(visible / pageSize);
    currentPage = totalPages === 0 ? 1 : Math.min(currentPage, totalPages);
    var firstVisibleIndex = (currentPage - 1) * pageSize;

    itemRows().forEach(function (row) { row.hidden = true; });
    filteredRows.slice(firstVisibleIndex, firstVisibleIndex + pageSize).forEach(function (row) {
      row.hidden = false;
    });

    if (noResultsRow) noResultsRow.hidden = visible !== 0;
    if (resultCount) resultCount.textContent = visible + (visible === 1 ? ' item' : ' items');
    metricFilters.forEach(function (metric) {
      var matchesCategory = (metric.dataset.categoryFilter || '') === activeCategoryFilter;
      var matchesStatus = (metric.dataset.statusFilter || '') === activeStatusFilter;
      metric.setAttribute('aria-pressed', String(matchesCategory && matchesStatus));
      var count = itemRows().filter(function (row) {
        return (!(metric.dataset.categoryFilter || '') || row.dataset.category === metric.dataset.categoryFilter)
          && (!(metric.dataset.statusFilter || '') || row.dataset.status === metric.dataset.statusFilter);
      }).length;
      var value = metric.querySelector('strong');
      if (value) value.textContent = String(count);
    });
    updatePagination(visible, totalPages, firstVisibleIndex);
  }

  function updatePagination(totalItems, totalPages, firstVisibleIndex) {
    if (!pagination) return;
    pagination.hidden = totalPages <= 1;
    if (pageInfo) {
      var lastVisibleIndex = Math.min(firstVisibleIndex + pageSize, totalItems);
      pageInfo.textContent = totalItems === 0
        ? 'No items'
        : 'Showing ' + (firstVisibleIndex + 1) + '–' + lastVisibleIndex + ' of ' + totalItems;
    }
    if (previousPageButton) previousPageButton.disabled = currentPage <= 1;
    if (nextPageButton) nextPageButton.disabled = currentPage >= totalPages;
    if (!pageNumbers) return;

    pageNumbers.textContent = '';
    for (var page = 1; page <= totalPages; page++) {
      var button = document.createElement('button');
      button.className = 'clinic-inventory__page-button';
      button.type = 'button';
      button.textContent = String(page);
      button.setAttribute('aria-label', 'Page ' + page);
      button.setAttribute('data-inventory-page', String(page));
      if (page === currentPage) button.setAttribute('aria-current', 'page');
      pageNumbers.appendChild(button);
    }
  }

  if (previousPageButton) previousPageButton.addEventListener('click', function () {
    if (currentPage > 1) {
      currentPage--;
      applyFilters();
    }
  });
  if (nextPageButton) nextPageButton.addEventListener('click', function () {
    currentPage++;
    applyFilters();
  });
  if (pageNumbers) pageNumbers.addEventListener('click', function (event) {
    var button = event.target.closest('[data-inventory-page]');
    if (!button) return;
    currentPage = parseInt(button.dataset.inventoryPage, 10);
    applyFilters();
  });

  metricFilters.forEach(function (metric) {
    metric.addEventListener('click', function () {
      activeCategoryFilter = metric.dataset.categoryFilter || '';
      activeStatusFilter = metric.dataset.statusFilter || '';
      currentPage = 1;
      applyFilters();
      if (inventorySection) inventorySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  /* ── Field error helper ─────────────────────────────────────────────── */

  function setFieldError(field, message) {
    var input = field.querySelector('input, select, textarea');
    var error = field.querySelector('.clinic-inventory__field-error');
    if (!error) {
      error = document.createElement('small');
      error.className = 'clinic-inventory__field-error';
      field.appendChild(error);
    }
    error.textContent = message || '';
    if (input) {
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
  }

  function clearFieldErrors(form) {
    form.querySelectorAll('.clinic-inventory__field-error').forEach(function (error) {
      error.textContent = '';
    });
    form.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
      input.removeAttribute('aria-invalid');
    });
  }

  /* ── Item form: add and edit ────────────────────────────────────────── */

  function resetItemForm() {
    if (!itemForm) return;
    itemForm.reset();
    clearFieldErrors(itemForm);
    if (itemFormMode) itemFormMode.value = 'add';
    if (itemFormEditingId) itemFormEditingId.value = '';
    var codeInput = itemForm.querySelector('#itemCode');
    if (codeInput) codeInput.value = '';
    if (itemFormTitle) itemFormTitle.textContent = 'Add Medicine';
    if (itemFormSubmit) itemFormSubmit.innerHTML = '<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Medicine';
    if (itemFormSuccess) itemFormSuccess.hidden = true;
  }

  function enterEditMode(row) {
    var item = rowToItem(row);
    if (!itemForm) return;
    resetItemForm();
    if (itemFormSection) itemFormSection.hidden = false;
    if (itemFormMode) itemFormMode.value = 'edit';
    if (itemFormEditingId) itemFormEditingId.value = item.id;
    itemForm.querySelector('#itemCode').value = item.id;
    itemForm.querySelector('#itemName').value = item.name;
    itemForm.querySelector('#itemCategory').value = item.category;
    itemForm.querySelector('#itemDescription').value = item.description;
    itemForm.querySelector('#itemQuantity').value = String(item.quantity);
    itemForm.querySelector('#itemUnit').value = item.unit;
    itemForm.querySelector('#itemMinimum').value = String(item.minimum);
    itemForm.querySelector('#itemExpires').value = item.expires;
    itemForm.querySelector('#itemSupplier').value = item.supplier;
    itemForm.querySelector('#itemStorage').value = item.storage;
    if (itemFormTitle) itemFormTitle.textContent = 'Edit Medicine';
    if (itemFormSubmit) itemFormSubmit.innerHTML = '<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Update Medicine';
    if (itemFormSection) itemFormSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    itemForm.querySelector('#itemName').focus();
  }

  function readItemForm() {
    var quantity = Number(itemForm.querySelector('#itemQuantity').value);
    var minimum = Number(itemForm.querySelector('#itemMinimum').value);
    return {
      id: itemForm.querySelector('#itemCode').value,
      name: itemForm.querySelector('#itemName').value.trim(),
      category: itemForm.querySelector('#itemCategory').value,
      quantity: Number.isSafeInteger(quantity) ? quantity : -1,
      unit: itemForm.querySelector('#itemUnit').value,
      minimum: Number.isSafeInteger(minimum) ? minimum : -1,
      expires: itemForm.querySelector('#itemExpires').value,
      description: itemForm.querySelector('#itemDescription').value.trim(),
      supplier: itemForm.querySelector('#itemSupplier').value.trim(),
      storage: itemForm.querySelector('#itemStorage').value.trim(),
    };
  }

  function validateItemForm(item) {
    var errors = [];
    var nameField = itemForm.querySelector('#itemName').closest('.clinic-inventory__field');
    var categoryField = itemForm.querySelector('#itemCategory').closest('.clinic-inventory__field');
    var quantityField = itemForm.querySelector('#itemQuantity').closest('.clinic-inventory__field');
    var minimumField = itemForm.querySelector('#itemMinimum').closest('.clinic-inventory__field');

    clearFieldErrors(itemForm);
    if (item.name === '') {
      setFieldError(nameField, 'Enter the item name.');
      errors.push('name');
    }
    if (item.category === '') {
      setFieldError(categoryField, 'Select a category.');
      errors.push('category');
    }
    if (itemForm.querySelector('#itemQuantity').value.trim() === '' || !Number.isSafeInteger(item.quantity) || item.quantity < 0) {
      setFieldError(quantityField, 'Enter a whole-number quantity of zero or more.');
      errors.push('quantity');
    }
    if (!Number.isSafeInteger(item.minimum) || item.minimum < 0) {
      setFieldError(minimumField, 'Enter a whole-number minimum stock level of zero or more.');
      errors.push('minimum');
    }
    return errors;
  }

  if (itemForm) {
    itemForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var item = readItemForm();
      var errors = validateItemForm(item);
      if (errors.length) {
        showToast('Please correct the highlighted fields and try again.', 'error');
        return;
      }
      var isEdit = itemFormMode && itemFormMode.value === 'edit';
      var payload = {
        medicine_id: isEdit ? parseInt(item.id.replace(/^MED-/, ''), 10) : undefined,
        name: item.name,
        description: item.description,
        quantity: item.quantity,
        unit: item.unit,
        minimum: item.minimum,
        expires: item.expires,
        supplier: item.supplier,
        storage: item.storage
      };
      requestInventory(isEdit ? 'update' : 'create', payload).then(function (result) {
        replaceInventoryItems(result.items, result.transactions);
        if (!isEdit && itemFormSuccess) {
          itemFormSuccess.hidden = false;
          itemFormSuccess.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        showToast(isEdit ? 'Medicine details were updated.' : 'Medicine was added to inventory.');
        resetItemForm();
      }).catch(function (error) {
        showToast(error.message, 'error');
      });
    });
  }

  var itemFormClear = root.querySelector('[data-inventory-form-clear]');
  if (itemFormClear) itemFormClear.addEventListener('click', function () {
    resetItemForm();
    showToast('Form cleared.');
  });

  var itemFormCancel = root.querySelector('[data-inventory-form-cancel]');
  if (itemFormCancel) itemFormCancel.addEventListener('click', function () {
    resetItemForm();
    if (itemFormSection) itemFormSection.hidden = true;
    showToast('Edit cancelled.');
  });

  var addButton = root.querySelector('[data-inventory-add]');
  if (addButton && itemFormSection) {
    addButton.addEventListener('click', function () {
      resetItemForm();
      itemFormSection.hidden = false;
      itemFormSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var nameInput = itemForm && itemForm.querySelector('#itemName');
      if (nameInput) nameInput.focus();
    });
  }

  /* ── Row actions: view, edit, delete ────────────────────────────────── */

  if (tableBody) {
    tableBody.addEventListener('click', function (event) {
      var button = event.target.closest('button[data-inventory-view], button[data-inventory-edit]');
      if (!button) return;
      var row = button.closest('tr[data-id]');
      if (!row) return;

      if (button.hasAttribute('data-inventory-view')) {
        showItemDialog(rowToItem(row));
      } else if (button.hasAttribute('data-inventory-edit')) {
        enterEditMode(row);
      }
    });
  }

  function showItemDialog(item) {
    if (!dialog) return;
    var title = dialog.querySelector('[data-inventory-dialog-title]');
    var body = dialog.querySelector('[data-inventory-dialog-body]');
    if (title) title.textContent = item.name;
    if (body) {
      body.textContent = '';
      var fields = [
        ['Item ID', item.id],
        ['Item Name', item.name],
        ['Category', item.category],
        ['Strength', item.strength || 'Not recorded'],
        ['Dosage Form', item.dosageForm || 'Not recorded'],
        ['Description', item.description || 'Not recorded'],
        ['Quantity', item.quantity + ' ' + item.unit],
        ['Minimum Stock Level', item.minimum + ' ' + item.unit],
        ['Date Received', item.received ? formatDate(item.received) : 'Not recorded'],
        ['Expiration Date', item.expires ? formatDate(item.expires) : 'Not applicable'],
        ['Batch Number', item.batch || 'Not recorded'],
        ['Supplier', item.supplier || 'Not recorded'],
        ['Storage Location', item.storage || 'Not recorded'],
        ['Status', item.status]
      ];
      fields.forEach(function (pair) {
        var term = document.createElement('dt');
        term.textContent = pair[0];
        var description = document.createElement('dd');
        description.textContent = pair[1];
        body.appendChild(term);
        body.appendChild(description);
      });
    }
    if (typeof dialog.showModal === 'function') dialog.showModal();
  }

  var dialogClose = root.querySelector('[data-inventory-dialog-close]');
  if (dialogClose) dialogClose.addEventListener('click', function () { dialog.close(); });
  if (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
  }

  /* ── Stock in / stock out ───────────────────────────────────────────── */

  function todayDefaults(form) {
    if (!form) return;
    form.querySelectorAll('input[type="date"][name="date"]').forEach(function (input) {
      if (!input.value) input.value = todayIso();
    });
  }

  function recordStock(form) {
    if (!form) return false;
    var select = form.querySelector('select[name="item_name"]');
    var quantityInput = form.querySelector('input[name="quantity"]');
    var row = itemRows().filter(function (candidate) { return candidate.dataset.id === select.value; })[0];
    var quantity = Number(quantityInput.value);
    var quantityField = quantityInput.closest('.clinic-inventory__field');

    clearFieldErrors(form);
    if (!row) {
      showToast('Select an inventory item first.', 'error');
      return false;
    }
    if (!Number.isSafeInteger(quantity) || quantity <= 0) {
      setFieldError(quantityField, 'Enter a whole-number quantity greater than zero.');
      showToast('Please correct the highlighted fields and try again.', 'error');
      return false;
    }

    var supplierInput = form.querySelector('input[name="supplier"]');
    var batchInput = form.querySelector('input[name="batch"]');
    var remarksInput = form.querySelector('textarea[name="remarks"]');
    var expiresInput = form.querySelector('input[name="expires"]');
    var dateInput = form.querySelector('input[name="date"]');
    return requestInventory('stock-in', {
      medicine_id: parseInt(row.dataset.medicineId, 10),
      quantity: quantity,
      date: dateInput && dateInput.value ? dateInput.value : todayIso(),
      personnel: (form.querySelector('input[name="personnel"]') || {}).value || '',
      supplier: supplierInput ? supplierInput.value.trim() : '',
      expires: expiresInput ? expiresInput.value : '',
      notes: [
        supplierInput && supplierInput.value.trim() ? 'Supplier: ' + supplierInput.value.trim() : '',
        batchInput && batchInput.value.trim() ? 'Batch: ' + batchInput.value.trim() : '',
        remarksInput && remarksInput.value.trim() ? remarksInput.value.trim() : ''
      ].filter(Boolean).join('; ')
    }).then(function (result) {
      replaceInventoryItems(result.items, result.transactions);
      form.reset();
      todayDefaults(form);
      showToast(result.message);
    }).catch(function (error) {
      showToast(error.message, 'error');
      throw error;
    });
  }

  if (stockInOpenButton && stockInDialog) {
    stockInOpenButton.addEventListener('click', function () {
      if (typeof stockInDialog.showModal === 'function') {
        stockInDialog.showModal();
        var quantityInput = stockInForm && stockInForm.querySelector('input[name="quantity"]');
        if (quantityInput) quantityInput.focus();
      }
    });
  }

  var stockInCloseButton = root.querySelector('[data-inventory-stock-in-close]');
  if (stockInCloseButton && stockInDialog) {
    stockInCloseButton.addEventListener('click', function () {
      stockInDialog.close();
    });
  }

  if (stockInDialog) {
    stockInDialog.addEventListener('click', function (event) {
      if (event.target === stockInDialog) stockInDialog.close();
    });
  }

  if (stockInForm) {
    stockInForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var result = recordStock(stockInForm);
      if (!result || typeof result.then !== 'function') return;
      result.then(function () {
        if (stockInDialog) stockInDialog.close();
      }).catch(function () {});
    });
  }

  /* ── Notification dismiss ───────────────────────────────────────────── */

  var notifications = Array.prototype.slice.call(root.querySelectorAll('.clinic-inventory__notification'));
  var notificationSection = root.querySelector('#inventory-notifications');
  var activeNotification = null;
  var alertHost = document.getElementById('clinicInventoryAlertHost');
  if (notificationSection && alertHost) {
    clearInventoryAlertHost();
    alertHost.appendChild(notificationSection);
  }

  function dismissNotification(notification) {
    if (!notification || notification !== activeNotification || !notification.isConnected) return;
    window.clearTimeout(notification.inventoryDismissTimer);
    notification.classList.add('is-leaving');
    window.setTimeout(function () {
      notification.remove();
      activeNotification = null;
      notifications.shift();
      showNextNotification();
    }, 200);
  }

  function showNextNotification() {
    if (!notifications.length) {
      if (notificationSection) notificationSection.hidden = true;
      return;
    }

    activeNotification = notifications[0];
    scheduleNotificationDismiss(activeNotification);
  }

  function scheduleNotificationDismiss(notification) {
    window.clearTimeout(notification.inventoryDismissTimer);
    notification.inventoryDismissTimer = window.setTimeout(function () {
      dismissNotification(notification);
    }, 1800);
  }

  notifications.forEach(function (notification) {
    notification.hidden = false;
    notification.addEventListener('mouseenter', function () {
      window.clearTimeout(notification.inventoryDismissTimer);
    });
    notification.addEventListener('mouseleave', function () {
      if (notification === activeNotification && !notification.contains(document.activeElement)) {
        scheduleNotificationDismiss(notification);
      }
    });
    notification.addEventListener('focusin', function () {
      window.clearTimeout(notification.inventoryDismissTimer);
    });
    notification.addEventListener('focusout', function (event) {
      if (notification === activeNotification
        && !notification.contains(event.relatedTarget)
        && !notification.matches(':hover')) {
        scheduleNotificationDismiss(notification);
      }
    });
  });

  if (notificationSection) notificationSection.hidden = notifications.length === 0;
  showNextNotification();

  if (notificationSection) notificationSection.addEventListener('click', function (event) {
    var dismiss = event.target.closest('[data-inventory-dismiss]');
    if (!dismiss) return;
    var notification = dismiss.closest('.clinic-inventory__notification');
    dismissNotification(notification);
  });

  /* ── Initial state ──────────────────────────────────────────────────── */

  todayDefaults(stockInForm);
  refreshItemSelects();
  applyFilters();
}

document.addEventListener('DOMContentLoaded', function () {
  initInventory(document.querySelector('.clinic-inventory'));
});

/* Re-initialize when page fragments are loaded via the page switcher. */
window.addEventListener('page:loaded', function () {
  window.setTimeout(function () {
    var inventoryRoot = document.querySelector('.clinic-inventory');
    if (!inventoryRoot) {
      clearInventoryAlertHost();
      return;
    }
    initInventory(inventoryRoot);
  }, 10);
});
