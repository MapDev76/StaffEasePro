(() => {
  const apiUrl = window.DashboardConfig?.apiDepartments;
  const feedback = window.DashboardFeedback;
  if (!apiUrl || !window.AppAPI) return;

  function notifyError(message) {
    if (feedback) {
      feedback.error('Oops!', message);
      return;
    }
    console.error(message);
  }

  function notifySuccess(message) {
    if (feedback) {
      feedback.success('Done', message);
      return;
    }
  }

  function getDefaultIcon() {
    const card = getCreateCard();
    const input = card ? card.querySelector('input[data-field="icon"]') : null;
    return (input?.defaultValue || input?.value || '🏷️').trim();
  }

  function getDefaultColor() {
    const card = getCreateCard();
    const input = card ? card.querySelector('input[data-field="color"]') : null;
    return (input?.defaultValue || input?.value || '#b98b12').trim();
  }

  function getColorPreviewInput(scope) {
    return scope ? scope.querySelector('input[data-color-preview]') : null;
  }

  function getIconPreviewInput(scope) {
    return scope ? scope.querySelector('input[data-icon-preview]') : null;
  }

  function buildIconUrl(input, iconValue) {
    if (!input || !iconValue || !/\.(svg|png|jpe?g|gif|webp|ico)$/i.test(iconValue)) return '';
    const base = input.dataset.iconBase || '/assets/icons/';
    return base + encodeURIComponent(iconValue);
  }

  function syncIconPreview(scope) {
    const iconInput = getIconPreviewInput(scope);
    if (!iconInput) return;
    const iconValue = (iconInput.value || '').trim();
    const iconUrl = buildIconUrl(iconInput, iconValue);
    if (iconUrl) {
      iconInput.style.setProperty('background-image', `url("${iconUrl}")`);
      iconInput.title = iconValue;
    } else {
      iconInput.style.removeProperty('background-image');
      iconInput.title = iconValue;
    }
  }

  function getCreateCard() {
    return document.querySelector('[data-dept-create-row]');
  }

  function getDepartmentCard(el) {
    return el.closest && el.closest('.settings-list-item-wrap[data-department-id]');
  }

  function getDepartmentDrawer(card) {
    return card ? card.querySelector('.settings-edit-drawer') : null;
  }

  function closeAllDepartmentDrawers() {
    document.querySelectorAll('[data-department-id] .settings-edit-drawer').forEach((drawer) => {
      drawer.hidden = true;
    });
  }

  function openDepartmentDrawer(card) {
    if (!card) return;
    closeAllDepartmentDrawers();
    const drawer = getDepartmentDrawer(card);
    if (drawer) drawer.hidden = false;
  }

  function closeDepartmentDrawer(card) {
    const drawer = getDepartmentDrawer(card);
    if (drawer) drawer.hidden = true;
  }

  function closeAllPickers(exceptPopover = null) {
    document.querySelectorAll('[data-dept-create-row] [data-picker-popover], [data-department-id] [data-picker-popover]').forEach((popover) => {
      const shouldKeepOpen = exceptPopover && popover === exceptPopover;
      popover.hidden = !shouldKeepOpen;
      const stack = popover.closest('.settings-picker-stack');
      if (stack) stack.classList.toggle('is-open', shouldKeepOpen);
      const toggle = stack ? stack.querySelector('[data-picker-toggle]') : null;
      if (toggle) toggle.setAttribute('aria-expanded', shouldKeepOpen ? 'true' : 'false');
    });
  }

  function togglePicker(scope, field) {
    if (!scope || !field) return;
    const popover = scope.querySelector(`[data-picker-popover="${field}"]`);
    const toggle = scope.querySelector(`[data-picker-toggle="${field}"]`);
    if (!popover || !toggle) return;
    const willOpen = popover.hidden;
    closeAllPickers(willOpen ? popover : null);
    popover.hidden = !willOpen;
    const stack = toggle.closest('.settings-picker-stack');
    if (stack) stack.classList.toggle('is-open', willOpen);
    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }

  function syncChoiceState(scope) {
    if (!scope) return;
    scope.querySelectorAll('[data-choice-field]').forEach((group) => {
      const field = group.getAttribute('data-choice-field');
      const input = scope.querySelector(`input[data-field="${field}"]`);
      const currentValue = (input?.value || '').trim();
      const previewInput = field === 'color' ? getColorPreviewInput(scope) : null;
      if (previewInput) {
        previewInput.value = '';
        previewInput.style.setProperty('--selected-color', currentValue || '#b98b12');
        previewInput.title = currentValue || '#b98b12';
      }
      group.querySelectorAll('[data-choice-value]').forEach((btn) => {
        const isSelected = btn.getAttribute('data-choice-value') === currentValue;
        btn.classList.toggle('is-selected', isSelected);
        btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
      });
    });
    syncIconPreview(scope);
  }

  function setChoiceValue(scope, field, value) {
    if (!scope || !field) return;
    const input = scope.querySelector(`input[data-field="${field}"]`);
    if (!input) return;
    input.value = value;
    if (field === 'color') {
      const previewInput = getColorPreviewInput(scope);
      if (previewInput) {
        previewInput.value = '';
        previewInput.style.setProperty('--selected-color', value || '#b98b12');
        previewInput.title = value || '#b98b12';
      }
    }
    syncChoiceState(scope);
    closeAllPickers();
  }

  function resetCreateDepartmentForm() {
    const card = getCreateCard();
    if (!card) return;
    const defaults = {
      'input[data-field="name"]': '',
      'input[data-field="icon"]': getDefaultIcon(),
      'input[data-field="color"]': getDefaultColor(),
    };
    Object.entries(defaults).forEach(([selector, value]) => {
      const input = card.querySelector(selector);
      if (input) input.value = value;
    });

    const headSelect = card.querySelector('select[data-field="head_user_id"]');
    if (headSelect) headSelect.value = '';

    syncChoiceState(card);
  }

  async function createDepartment(card) {
    if (!card) return;
    const name = card.querySelector('input[data-field="name"]')?.value.trim() || '';
    const icon = card.querySelector('input[data-field="icon"]')?.value.trim() || getDefaultIcon();
    const color = card.querySelector('input[data-field="color"]')?.value.trim() || getDefaultColor();
    const headUserIdRaw = card.querySelector('select[data-field="head_user_id"]')?.value || '';
    const headUserId = parseInt(headUserIdRaw, 10) || 0;
    const companyField = card.querySelector('[data-field="company_id"]');
    const companyId = parseInt(companyField?.value || '0', 10) || 0;

    if (!name) return notifyError('Enter department name.');
    if (!companyId) return notifyError('Choose a company.');

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'create',
        company_id: companyId,
        name,
        icon,
        color,
        head_user_id: headUserId > 0 ? headUserId : null,
      });
      if (!res?.ok) {
        notifyError('Create failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('departments', 'Done', 'Department created successfully.');
      } else {
        notifySuccess('Department created successfully.');
        location.reload();
      }
    } catch (e) {
      console.error(e);
      notifyError('Error creating department.');
    }
  }

  async function saveDepartment(card) {
    if (!card) return;
    const id = parseInt(card.dataset.departmentId || '0', 10) || 0;
    const companyId = parseInt(card.dataset.companyId || '0', 10) || 0;
    if (!id) return;

    const name = card.querySelector('input[data-field="name"]')?.value.trim() || '';
    const icon = card.querySelector('input[data-field="icon"]')?.value.trim() || getDefaultIcon();
    const color = card.querySelector('input[data-field="color"]')?.value.trim() || getDefaultColor();
    const headUserIdRaw = card.querySelector('select[data-field="head_user_id"]')?.value || '';
    const headUserId = parseInt(headUserIdRaw, 10) || 0;

    if (!name) return notifyError('Enter department name.');

    try {
      const res = await AppAPI.departments.update(apiUrl, { id, company_id: companyId, name, icon, color, head_user_id: headUserId > 0 ? headUserId : null });
      if (res?.ok) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('departments', 'Done', 'Department updated successfully.');
        } else {
          notifySuccess('Department updated successfully.');
          location.reload();
        }
      } else {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error saving department.');
    }
  }

  async function deleteDepartment(card) {
    if (!card) return;
    const id = parseInt(card.dataset.departmentId || '0', 10) || 0;
    const companyId = parseInt(card.dataset.companyId || '0', 10) || 0;
    if (!id) return;
    const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const isFr = locale.startsWith('fr');
    const isIt = locale.startsWith('it');
    const tr = (en, fr, it) => (isFr ? fr : (isIt && it ? it : en));
    const canDelete = feedback?.confirm ? await feedback.confirm(tr('Delete this department?', 'Supprimer ce département ?', 'Eliminare questo dipartimento?'), tr('Confirm deletion', 'Confirmer la suppression', 'Conferma eliminazione')) : false;
    if (!feedback?.confirm) {
      notifyError('Confirmation dialog is not available.');
      return;
    }
    if (!canDelete) return;

    try {
      const res = await AppAPI.postJSON(apiUrl, { action: 'delete', id, company_id: companyId });
      if (res?.ok) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('departments', 'Done', 'Department deleted successfully.');
        } else {
          notifySuccess('Department deleted successfully.');
          card.remove();
        }
      } else {
        notifyError('Delete failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error deleting department.');
    }
  }

  document.addEventListener('click', (ev) => {
    const pickerToggle = ev.target.closest && ev.target.closest('[data-picker-toggle]');
    if (pickerToggle) {
      ev.preventDefault();
      const scope = pickerToggle.closest('[data-department-id], [data-dept-create-row]');
      const field = pickerToggle.getAttribute('data-picker-toggle');
      togglePicker(scope, field);
      return;
    }

    const choiceBtn = ev.target.closest && ev.target.closest('[data-choice-field] [data-choice-value]');
    if (choiceBtn) {
      ev.preventDefault();
      const group = choiceBtn.closest('[data-choice-field]');
      const scope = choiceBtn.closest('[data-department-id], [data-dept-create-row]');
      const field = group?.getAttribute('data-choice-field');
      const value = choiceBtn.getAttribute('data-choice-value') || '';
      if (scope && field && value) {
        setChoiceValue(scope, field, value);
      }
      return;
    }

    const createBtn = ev.target.closest && ev.target.closest('.settings-dept-create');
    if (createBtn) {
      ev.preventDefault();
      createDepartment(getCreateCard());
      return;
    }

    const resetBtn = ev.target.closest && ev.target.closest('.settings-dept-reset');
    if (resetBtn) {
      ev.preventDefault();
      resetCreateDepartmentForm();
      return;
    }

    const saveBtn = ev.target.closest && ev.target.closest('.settings-dept-save');
    if (saveBtn) {
      ev.preventDefault();
      saveDepartment(getDepartmentCard(saveBtn));
      return;
    }

    const editBtn = ev.target.closest && ev.target.closest('.settings-dept-edit');
    if (editBtn) {
      ev.preventDefault();
      openDepartmentDrawer(getDepartmentCard(editBtn));
      return;
    }

    const cancelBtn = ev.target.closest && ev.target.closest('.settings-dept-cancel');
    if (cancelBtn) {
      ev.preventDefault();
      closeDepartmentDrawer(getDepartmentCard(cancelBtn));
      return;
    }

    const deleteBtn = ev.target.closest && ev.target.closest('.settings-dept-delete');
    if (deleteBtn) {
      ev.preventDefault();
      deleteDepartment(getDepartmentCard(deleteBtn));
      return;
    }

    if (!ev.target.closest('.settings-picker-stack')) {
      closeAllPickers();
    }
  });

  document.querySelectorAll('[data-dept-create-row], [data-department-id]').forEach((scope) => {
    syncChoiceState(scope);
  });

  resetCreateDepartmentForm();
})();
