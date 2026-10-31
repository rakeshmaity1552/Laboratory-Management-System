/**
 * Laboratory Management System (LMS)
 * Client-side Application Scripts
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  /* ==========================================================================
     1. Flash Messages Auto-dismiss & Close Handling
     ========================================================================== */
  const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
  alerts.forEach(function (alert) {
    // Add close button handler if present
    const closeBtn = alert.querySelector('.alert-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        dismissAlert(alert);
      });
    }

    // Auto dismiss after 5 seconds
    setTimeout(function () {
      dismissAlert(alert);
    }, 5000);
  });

  function dismissAlert(alertElement) {
    if (!alertElement || alertElement.classList.contains('alert-dismissing')) return;
    alertElement.classList.add('alert-dismissing', 'alert-fade-out');
    setTimeout(function () {
      if (alertElement.parentNode) {
        alertElement.parentNode.removeChild(alertElement);
      }
    }, 300);
  }

  /* ==========================================================================
     2. Mobile Navigation Toggle (Hamburger Menu)
     ========================================================================== */
  const navbarToggler = document.querySelector('.navbar-toggler');
  const navbarCollapse = document.querySelector('.navbar-collapse');

  if (navbarToggler && navbarCollapse) {
    navbarToggler.addEventListener('click', function (e) {
      e.stopPropagation();
      navbarCollapse.classList.toggle('show');
      const isExpanded = navbarCollapse.classList.contains('show');
      navbarToggler.setAttribute('aria-expanded', isExpanded);
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
      if (!navbarCollapse.contains(e.target) && !navbarToggler.contains(e.target)) {
        navbarCollapse.classList.remove('show');
        navbarToggler.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Dropdown toggle handling
  const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
  dropdownToggles.forEach(function (toggle) {
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const parentDropdown = toggle.closest('.dropdown');
      
      // Close other dropdowns
      document.querySelectorAll('.dropdown.open').forEach(function (d) {
        if (d !== parentDropdown) d.classList.remove('open');
      });

      if (parentDropdown) {
        parentDropdown.classList.toggle('open');
      }
    });
  });

  document.addEventListener('click', function () {
    document.querySelectorAll('.dropdown.open').forEach(function (d) {
      d.classList.remove('open');
    });
  });

  /* ==========================================================================
     3. Confirm Dialogs for Dangerous Actions
     ========================================================================== */
  document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-confirm], .confirm-action, .btn-danger:not([type="submit"])');
    if (target) {
      const message = target.getAttribute('data-confirm') || 
                      target.getAttribute('data-message') || 
                      'Are you sure you want to perform this action? This operation cannot be undone.';
      if (!window.confirm(message)) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    }
  });

  // Intercept form submissions that have data-confirm
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      const message = form.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
      if (!window.confirm(message)) {
        e.preventDefault();
        return false;
      }
    });
  });

  /* ==========================================================================
     4. Modal Open / Close Functionality
     ========================================================================== */
  // Open modal trigger: [data-modal-target="#modalId"]
  document.querySelectorAll('[data-modal-target]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const targetSelector = btn.getAttribute('data-modal-target');
      const targetModal = document.querySelector(targetSelector);
      if (targetModal) {
        openModal(targetModal);
      }
    });
  });

  // Close buttons inside modal
  document.querySelectorAll('.modal-close, [data-modal-close]').forEach(function (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      const modal = closeBtn.closest('.modal');
      if (modal) {
        closeModal(modal);
      }
    });
  });

  // Close when clicking modal backdrop
  document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.classList.contains('modal-backdrop')) {
        closeModal(modal);
      }
    });
  });

  // Close on ESC key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      const openModals = document.querySelectorAll('.modal.show');
      openModals.forEach(function (modal) {
        closeModal(modal);
      });
    }
  });

  window.openModal = function (modal) {
    if (typeof modal === 'string') modal = document.querySelector(modal);
    if (!modal) return;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    const autoFocus = modal.querySelector('[autofocus], input:not([type="hidden"])');
    if (autoFocus) autoFocus.focus();
  };

  window.closeModal = function (modal) {
    if (typeof modal === 'string') modal = document.querySelector(modal);
    if (!modal) return;
    modal.classList.remove('show');
    if (!document.querySelector('.modal.show')) {
      document.body.style.overflow = '';
    }
  };

  /* ==========================================================================
     5. Supplementary Form Validation Helpers
     ========================================================================== */
  document.querySelectorAll('form:not([novalidate])').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      let isValid = true;
      const requiredInputs = form.querySelectorAll('input[required], select[required], textarea[required]');

      requiredInputs.forEach(function (input) {
        const value = input.value.trim();
        let errorSpan = input.parentNode.querySelector('.form-error');

        if (!value) {
          isValid = false;
          input.classList.add('is-invalid');
          if (!errorSpan) {
            errorSpan = document.createElement('div');
            errorSpan.className = 'form-error';
            errorSpan.textContent = 'This field is required.';
            input.parentNode.appendChild(errorSpan);
          }
        } else {
          input.classList.remove('is-invalid');
          if (errorSpan && errorSpan.textContent === 'This field is required.') {
            errorSpan.remove();
          }
        }
      });

      if (!isValid) {
        e.preventDefault();
        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.focus();
      }
    });
  });

  /* ==========================================================================
     6. Character Counter for Description Textarea
     ========================================================================== */
  const textareasWithCounter = document.querySelectorAll('textarea[maxlength], textarea[data-char-counter]');
  textareasWithCounter.forEach(function (textarea) {
    const maxChars = parseInt(textarea.getAttribute('maxlength') || '500', 10);
    const counterId = textarea.getAttribute('data-char-counter');
    let counterElem = counterId ? document.getElementById(counterId) : null;

    if (!counterElem) {
      counterElem = document.createElement('div');
      counterElem.className = 'char-counter';
      textarea.parentNode.appendChild(counterElem);
    }

    function updateCounter() {
      const currentLen = textarea.value.length;
      counterElem.textContent = `${currentLen} / ${maxChars} characters`;
      if (currentLen >= maxChars) {
        counterElem.classList.add('text-danger');
      } else {
        counterElem.classList.remove('text-danger');
      }
    }

    textarea.addEventListener('input', updateCounter);
    updateCounter();
  });

  /* ==========================================================================
     7. Auto-Submit Filter Forms on Change (Debounced)
     ========================================================================== */
  const filterForms = document.querySelectorAll('form.auto-filter, .filters-bar form');
  filterForms.forEach(function (form) {
    let debounceTimer = null;

    // Select elements and checkboxes submit immediately
    form.querySelectorAll('select, input[type="radio"], input[type="checkbox"]').forEach(function (elem) {
      elem.addEventListener('change', function () {
        form.submit();
      });
    });

    // Text inputs submit after 400ms debounce
    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(function (input) {
      input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          form.submit();
        }, 400);
      });
    });
  });

  /* ==========================================================================
     8. Table Row Click to Navigate (Ticket Lists & Inventory)
     ========================================================================== */
  const clickableRows = document.querySelectorAll('tr[data-href], .clickable-row');
  clickableRows.forEach(function (row) {
    row.addEventListener('click', function (e) {
      // Avoid triggering when user clicks on a button, link, or input inside row
      if (e.target.closest('a, button, input, select, label')) {
        return;
      }
      const href = row.getAttribute('data-href');
      if (href) {
        window.location.href = href;
      }
    });
  });

  /* ==========================================================================
     9. CSRF Token Sync / Helper
     ========================================================================== */
  window.getCsrfToken = function () {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) return metaTag.getAttribute('content');
    const input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  };
});
