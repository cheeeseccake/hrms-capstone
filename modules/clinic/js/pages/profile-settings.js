(function () {
  'use strict';

  if (window.clinicPasswordModalEventsBound) return;
  window.clinicPasswordModalEventsBound = true;

  var previousBodyOverflow = '';
  var previousFocus = null;

  function getModal() {
    return document.getElementById('pwModalBackdrop');
  }

  function openModal() {
    var modal = getModal();
    if (!modal || modal.classList.contains('active')) return;

    previousFocus = document.activeElement;
    previousBodyOverflow = document.body.style.overflow;

    document.querySelectorAll('#userDropdown').forEach(function (dropdown) {
      dropdown.classList.remove('open');
    });

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    var firstInput = modal.querySelector('input[type="password"]');
    if (firstInput) firstInput.focus();
  }

  function closeModal() {
    var modal = getModal();
    if (!modal || !modal.classList.contains('active')) return;

    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = previousBodyOverflow;

    if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
      previousFocus.focus();
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    if (target.closest('[data-password-modal-open]')) {
      openModal();
      return;
    }

    if (target.closest('[data-password-modal-close]') || target.id === 'pwModalBackdrop') {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
  });

  function handlePageLoaded(event) {
    if (event && event.detail && event.detail.page !== 'profile-settings') return;
    if (window.location.hash === '#change-password') openModal();
  }

  window.addEventListener('page:loaded', handlePageLoaded);
  document.addEventListener('DOMContentLoaded', handlePageLoaded, { once: true });
  handlePageLoaded();
})();
