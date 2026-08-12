(() => {
  const L = window.WizardLabels || {};

  function root() {
    return document.querySelector('[data-shift-create-row]');
  }

  function stepEls(scope) {
    return Array.from(scope.querySelectorAll('[data-wizard-step]'));
  }

  function stepNumber(el) {
    return parseInt(el.getAttribute('data-wizard-step') || '0', 10) || 0;
  }

  function summaryFor(scope, num) {
    if (num === 1) {
      const name = scope.querySelector('input[data-field="name"]')?.value.trim();
      const color = scope.querySelector('input[data-field="color"]')?.value.trim();
      return name ? `${name}` : '';
    }
    if (num === 2) {
      const checked = scope.querySelectorAll('[data-weekday-chip].is-selected');
      return checked.length ? (L.daysPerWeek || '{n} days/week').replace('{n}', checked.length) : '';
    }
    if (num === 3) {
      const start = scope.querySelector('input[data-field="start_time"]')?.value || '';
      const end = scope.querySelector('input[data-field="end_time"]')?.value || '';
      return start && end ? `${start} - ${end}` : '';
    }
    if (num === 4) {
      const rest = scope.querySelectorAll('[data-restday-chip].is-selected');
      return rest.length ? (L.restDaysCount || '{n} fixed rest days').replace('{n}', rest.length) : (L.restDaysNone || 'none');
    }
    return '';
  }

  function setActiveStep(scope, num) {
    stepEls(scope).forEach((stepEl) => {
      const n = stepNumber(stepEl);
      const isActive = n === num;
      stepEl.classList.toggle('is-active', isActive);
      stepEl.classList.toggle('is-done', n < num);
      const body = stepEl.querySelector('[data-wizard-step-body]');
      if (body) body.hidden = !isActive;
      const summaryEl = stepEl.querySelector('[data-wizard-step-summary]');
      if (summaryEl) summaryEl.textContent = n < num ? summaryFor(scope, n) : '';
    });
    scope.setAttribute('data-wizard-active-step', String(num));
  }

  function validateStep(scope, num) {
    if (num === 1) {
      const name = scope.querySelector('input[data-field="name"]')?.value.trim();
      const deptSelect = scope.querySelector('select[data-field="department_ids"]');
      const hasDept = deptSelect && Array.from(deptSelect.selectedOptions || []).length > 0;
      if (!name) {
        window.DashboardFeedback?.error('Oops!', L.shiftNameRequired || 'Inserisci un nome per il turno.');
        return false;
      }
      if (!hasDept) {
        window.DashboardFeedback?.error('Oops!', L.selectDepartment || 'Seleziona almeno un reparto.');
        return false;
      }
      return true;
    }
    if (num === 2) {
      const checked = scope.querySelectorAll('[data-weekday-chip].is-selected');
      if (!checked.length) {
        window.DashboardFeedback?.error('Oops!', L.selectWeekday || 'Seleziona almeno un giorno della settimana.');
        return false;
      }
      return true;
    }
    if (num === 3) {
      const start = scope.querySelector('input[data-field="start_time"]')?.value;
      const end = scope.querySelector('input[data-field="end_time"]')?.value;
      if (!start || !end) {
        window.DashboardFeedback?.error('Oops!', L.setHours || 'Imposta l\'orario di inizio e fine.');
        return false;
      }
      return true;
    }
    return true;
  }

  function syncHiddenSelect(scope, chipSelector, selectFieldName) {
    const select = scope.querySelector(`select[data-field="${selectFieldName}"]`);
    if (!select) return;
    const selected = new Set(
      Array.from(scope.querySelectorAll(`${chipSelector}.is-selected`)).map((chip) => chip.getAttribute('data-weekday-value'))
    );
    Array.from(select.options).forEach((option) => {
      option.selected = selected.has(option.value);
    });
  }

  function toggleChip(chip, scope, chipSelector, selectFieldName) {
    chip.classList.toggle('is-selected');
    syncHiddenSelect(scope, chipSelector, selectFieldName);
  }

  function initWizard(scope) {
    if (!scope || scope.__wizardInit) return;
    scope.__wizardInit = true;
    setActiveStep(scope, 1);

    scope.addEventListener('click', (evt) => {
      const stepToggle = evt.target.closest('[data-wizard-step-toggle]');
      if (stepToggle) {
        const stepEl = stepToggle.closest('[data-wizard-step]');
        const n = stepNumber(stepEl);
        const active = parseInt(scope.getAttribute('data-wizard-active-step') || '1', 10);
        if (n < active) {
          setActiveStep(scope, n);
        }
        return;
      }

      const nextBtn = evt.target.closest('[data-wizard-next]');
      if (nextBtn) {
        const active = parseInt(scope.getAttribute('data-wizard-active-step') || '1', 10);
        if (!validateStep(scope, active)) return;
        const target = parseInt(nextBtn.getAttribute('data-wizard-next') || '0', 10);
        if (target) setActiveStep(scope, target);
        return;
      }

      const backBtn = evt.target.closest('[data-wizard-back]');
      if (backBtn) {
        const target = parseInt(backBtn.getAttribute('data-wizard-back') || '0', 10);
        if (target) setActiveStep(scope, target);
        return;
      }

      const weekdayChip = evt.target.closest('[data-weekday-chip]');
      if (weekdayChip) {
        toggleChip(weekdayChip, scope, '[data-weekday-chip]', 'work_weekdays');
        return;
      }

      const restdayChip = evt.target.closest('[data-restday-chip]');
      if (restdayChip) {
        toggleChip(restdayChip, scope, '[data-restday-chip]', 'weekly_rest_weekdays');
        return;
      }
    });
  }

  function resetWizard(scope) {
    if (!scope) return;
    scope.querySelectorAll('[data-weekday-chip], [data-restday-chip]').forEach((chip) => chip.classList.remove('is-selected'));
    syncHiddenSelect(scope, '[data-weekday-chip]', 'work_weekdays');
    syncHiddenSelect(scope, '[data-restday-chip]', 'weekly_rest_weekdays');
    setActiveStep(scope, 1);
  }

  document.addEventListener('DOMContentLoaded', () => {
    initWizard(root());
  });

  document.addEventListener('click', (evt) => {
    if (evt.target.closest && evt.target.closest('.settings-shift-reset')) {
      resetWizard(root());
    }
  });

  // Re-init after the settings panel content becomes visible/re-rendered.
  const observer = new MutationObserver(() => {
    initWizard(root());
  });
  document.addEventListener('DOMContentLoaded', () => {
    const target = document.querySelector('[data-settings-panel="shifts"]');
    if (target) observer.observe(target, { childList: true, subtree: true });
  });
})();
