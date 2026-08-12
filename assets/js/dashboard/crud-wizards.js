(() => {
  const L = window.WizardLabels || {};

  function buildWizard(rootSelector, prefix) {
    const root = document.querySelector(rootSelector);
    if (!root || root.__wizardInit) return;
    root.__wizardInit = true;

    const stepAttr = `data-${prefix}-step`;
    const toggleAttr = `data-${prefix}-step-toggle`;
    const bodyAttr = `data-${prefix}-step-body`;
    const summaryAttr = `data-${prefix}-step-summary`;
    const nextAttr = `data-${prefix}-next`;
    const backAttr = `data-${prefix}-back`;

    function steps() {
      return Array.from(root.querySelectorAll(`[${stepAttr}]`));
    }
    function stepNum(el) {
      return parseInt(el.getAttribute(stepAttr) || '0', 10) || 0;
    }
    function setActive(num) {
      steps().forEach((stepEl) => {
        const n = stepNum(stepEl);
        stepEl.classList.toggle('is-active', n === num);
        stepEl.classList.toggle('is-done', n < num);
        const body = stepEl.querySelector(`[${bodyAttr}]`);
        if (body) body.hidden = n !== num;
        const summaryEl = stepEl.querySelector(`[${summaryAttr}]`);
        if (summaryEl && n < num) {
          const firstInput = stepEl.querySelector('input[type="text"], input[type="email"], select');
          summaryEl.textContent = firstInput && firstInput.value ? String(firstInput.value).slice(0, 40) : '';
        } else if (summaryEl) {
          summaryEl.textContent = '';
        }
      });
      root.setAttribute(`data-${prefix}-active-step`, String(num));
    }
    function active() {
      return parseInt(root.getAttribute(`data-${prefix}-active-step`) || '1', 10);
    }
    function validate(num) {
      const stepEl = steps().find((s) => stepNum(s) === num);
      if (!stepEl) return true;
      const requiredFields = Array.from(stepEl.querySelectorAll('[data-wizard-required]'));
      for (const field of requiredFields) {
        if (!field.value || !field.value.trim()) {
          window.DashboardFeedback?.error('Oops!', L.fillRequiredFields || 'Compila i campi obbligatori prima di proseguire.');
          field.focus();
          return false;
        }
      }
      return true;
    }

    setActive(1);

    root.addEventListener('click', (evt) => {
      const toggle = evt.target.closest(`[${toggleAttr}]`);
      if (toggle) {
        const stepEl = toggle.closest(`[${stepAttr}]`);
        const n = stepNum(stepEl);
        if (n < active()) setActive(n);
        return;
      }
      const nextBtn = evt.target.closest(`[${nextAttr}]`);
      if (nextBtn) {
        if (!validate(active())) return;
        const target = parseInt(nextBtn.getAttribute(nextAttr) || '0', 10);
        if (target) setActive(target);
        return;
      }
      const backBtn = evt.target.closest(`[${backAttr}]`);
      if (backBtn) {
        const target = parseInt(backBtn.getAttribute(backAttr) || '0', 10);
        if (target) setActive(target);
        return;
      }
    });

    root.__wizardReset = () => setActive(1);
  }

  function initAll() {
    buildWizard('[data-company-create-row]', 'cw');
    buildWizard('[data-dept-create-row]', 'dw');
  }

  document.addEventListener('DOMContentLoaded', initAll);

  document.addEventListener('click', (evt) => {
    if (evt.target.closest && evt.target.closest('.settings-company-reset')) {
      document.querySelector('[data-company-create-row]')?.__wizardReset?.();
    }
    if (evt.target.closest && evt.target.closest('.settings-dept-reset')) {
      document.querySelector('[data-dept-create-row]')?.__wizardReset?.();
    }
  });

  const observer = new MutationObserver(() => {
    // Re-init lazily in case the settings panel content is re-rendered after a
    // successful create (fresh DOM nodes replace the ones we attached to).
    const cw = document.querySelector('[data-company-create-row]');
    const dw = document.querySelector('[data-dept-create-row]');
    if (cw && !cw.__wizardInit) buildWizard('[data-company-create-row]', 'cw');
    if (dw && !dw.__wizardInit) buildWizard('[data-dept-create-row]', 'dw');
  });
  document.addEventListener('DOMContentLoaded', () => {
    const companiesPanel = document.querySelector('[data-settings-panel="companies"]');
    if (companiesPanel) observer.observe(companiesPanel, { childList: true, subtree: true });
    const deptPanel = document.querySelector('[data-settings-panel="departments"]');
    if (deptPanel) observer.observe(deptPanel, { childList: true, subtree: true });
  });
})();
