(() => {
  const apiUrl = window.DashboardConfig?.apiUsers;
  const companyApiUrl = window.DashboardConfig?.apiCompanies;
  const plannerData = window.DashboardPlannerData || {};
  const currentUserRole = String(window.DashboardCurrentUser?.role || '').trim();
  const feedback = window.DashboardFeedback;

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

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
  const isFr = locale.startsWith('fr');
  const isIt = locale.startsWith('it');
  const tr = (en, fr, it) => (isFr ? fr : (isIt ? it : en));

  function hoursBetweenTimes(startRaw, endRaw) {
    const start = String(startRaw || '').slice(0, 5);
    const end = String(endRaw || '').slice(0, 5);
    if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end)) return 0;
    const [sh, sm] = start.split(':').map((v) => parseInt(v, 10));
    const [eh, em] = end.split(':').map((v) => parseInt(v, 10));
    let delta = ((eh * 60) + em) - ((sh * 60) + sm);
    if (delta <= 0) delta += 24 * 60;
    return Math.round((delta / 60) * 100) / 100;
  }


  function isUsersPanelActive() {
    const panel = document.querySelector('.settings-panel[data-settings-panel="users"]');
    return !!panel && !panel.hidden;
  }

  function getCreateCard() { return document.querySelector('[data-user-create-row]'); }
  function getRoleFieldValue(scope) {
    if (!scope) return 'employee';
    return String(scope.querySelector('[data-field="role"]')?.value || 'employee').trim();
  }

  function roleAllowsMultipleDepartments(role) {
    return String(role || '').trim() === 'department_manager';
  }

  function enforceDepartmentSelectionMode(scope, changedDepartmentId) {
    if (!scope) return;
    const singleSelect = scope.querySelector('select[data-field="department_id"]');
    if (singleSelect) return;
    const select = scope.querySelector('select[data-field="department_ids"]');
    if (!select) return;

    const role = getRoleFieldValue(scope);
    if (roleAllowsMultipleDepartments(role)) {
      syncDepartmentDropdown(select);
      return;
    }

    const options = Array.from(select.options || []);
    const selectedIds = options
      .filter((option) => option.selected)
      .map((option) => parseInt(option.value || '0', 10) || 0)
      .filter((id) => id > 0);

    const keepId = Number.isInteger(changedDepartmentId) && changedDepartmentId > 0
      ? changedDepartmentId
      : (selectedIds[selectedIds.length - 1] || 0);

    options.forEach((option) => {
      const id = parseInt(option.value || '0', 10) || 0;
      option.selected = keepId > 0 && id === keepId;
    });

    syncDepartmentDropdown(select);
  }

  function getSelectedDepartmentIds(scope) {
    if (!scope) return [];
    const single = scope.querySelector('select[data-field="department_id"]');
    if (single) {
      const id = parseInt(single.value || '0', 10) || 0;
      return id > 0 ? [id] : [];
    }
    const multi = scope.querySelector('select[data-field="department_ids"]');
    if (!multi) return [];
    const selected = Array.from(multi.selectedOptions || [])
      .map((option) => parseInt(option.value || '0', 10) || 0)
      .filter((id, index, array) => id > 0 && array.indexOf(id) === index);

    if (roleAllowsMultipleDepartments(getRoleFieldValue(scope))) {
      return selected;
    }

    return selected.length ? [selected[selected.length - 1]] : [];
  }

  function resetCreateUserForm() {
    const card = getCreateCard(); if (!card) return;
    ['first_name','last_name','email','role','password'].forEach((f) => {
      const el = card.querySelector('[data-field="' + f + '"]'); if (!el) return;
      if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
    });
    const singleDepartment = card.querySelector('select[data-field="department_id"]');
    if (singleDepartment) {
      singleDepartment.selectedIndex = 0;
    }
    const departments = card.querySelector('select[data-field="department_ids"]');
    if (departments) {
      Array.from(departments.options || []).forEach((option) => {
        option.selected = false;
      });
      departments.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function getDepartmentDropdownLabel(select) {
    const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const isFr = locale.startsWith('fr');
    const isIt = locale.startsWith('it');
    const selected = Array.from(select.selectedOptions || []).map((opt) => String(opt.textContent || '').trim()).filter((v) => v !== '');
    if (!selected.length) {
      return isFr ? 'Selectionner departement(s)' : (isIt ? 'Seleziona dipartimento/i' : 'Select department(s)');
    }
    if (selected.length === 1) {
      return selected[0];
    }
    return isFr ? `${selected.length} departements selectionnes` : (isIt ? `${selected.length} dipartimenti selezionati` : `${selected.length} departments selected`);
  }

  function syncDepartmentDropdown(select) {
    if (!select) return;
    const root = select.closest('.settings-multiselect');
    if (!root) return;
    const trigger = root.querySelector('.settings-multiselect-trigger');
    const checkboxes = root.querySelectorAll('input[data-multiselect-option]');
    if (trigger) {
      trigger.textContent = getDepartmentDropdownLabel(select);
    }
    checkboxes.forEach((checkbox) => {
      const value = parseInt(String(checkbox.getAttribute('data-multiselect-option') || '0'), 10) || 0;
      const option = Array.from(select.options || []).find((opt) => (parseInt(opt.value || '0', 10) || 0) === value);
      checkbox.checked = !!option?.selected;
    });
  }

  function closeAllDepartmentDropdowns() {
    document.querySelectorAll('.settings-multiselect.is-open').forEach((root) => {
      root.classList.remove('is-open');
      const trigger = root.querySelector('.settings-multiselect-trigger');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
  }

  function initDepartmentDropdowns() {
    const panel = document.querySelector('.settings-panel[data-settings-panel="users"]');
    if (!panel) return;
    panel.querySelectorAll('select[data-field="department_ids"][multiple]').forEach((select) => {
      if (select.closest('.settings-multiselect')) return;
      const host = document.createElement('div');
      host.className = 'settings-multiselect';

      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'settings-multiselect-trigger';
      trigger.setAttribute('aria-expanded', 'false');
      trigger.textContent = getDepartmentDropdownLabel(select);

      const menu = document.createElement('div');
      menu.className = 'settings-multiselect-menu';

      Array.from(select.options || []).forEach((option) => {
        const value = parseInt(option.value || '0', 10) || 0;
        if (value <= 0) return;
        const row = document.createElement('label');
        row.className = 'settings-multiselect-option';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.setAttribute('data-multiselect-option', String(value));
        checkbox.checked = !!option.selected;
        const text = document.createElement('span');
        text.textContent = String(option.textContent || '').trim();
        row.appendChild(checkbox);
        row.appendChild(text);
        menu.appendChild(row);
      });

      select.parentNode.insertBefore(host, select);
      host.appendChild(trigger);
      host.appendChild(menu);
      host.appendChild(select);
      select.classList.add('settings-native-multiselect-hidden');

      trigger.addEventListener('click', (ev) => {
        ev.preventDefault();
        const open = host.classList.contains('is-open');
        closeAllDepartmentDropdowns();
        if (!open) {
          host.classList.add('is-open');
        }
        trigger.setAttribute('aria-expanded', host.classList.contains('is-open') ? 'true' : 'false');
      });

      menu.addEventListener('change', (ev) => {
        const input = ev.target;
        if (!(input instanceof HTMLInputElement)) return;
        const value = parseInt(String(input.getAttribute('data-multiselect-option') || '0'), 10) || 0;
        if (!value) return;
        const option = Array.from(select.options || []).find((opt) => (parseInt(opt.value || '0', 10) || 0) === value);
        if (option) {
          option.selected = !!input.checked;
          const scope = select.closest('.settings-list-item-wrap[data-user-id], [data-user-create-row]');
          if (scope) {
            enforceDepartmentSelectionMode(scope, value);
          }
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });

      select.addEventListener('change', () => syncDepartmentDropdown(select));
      syncDepartmentDropdown(select);
    });
  }

  function bindRoleSelectionRules() {
    document.addEventListener('change', (ev) => {
      const roleSelect = ev.target;
      if (!(roleSelect instanceof HTMLSelectElement)) return;
      if (roleSelect.getAttribute('data-field') !== 'role') return;
      const scope = roleSelect.closest('.settings-list-item-wrap[data-user-id], [data-user-create-row]');
      if (!scope) return;
      enforceDepartmentSelectionMode(scope);
    });
  }

  function collectCreateData() {
    const c = getCreateCard(); if (!c) return null;
    const settingsCompanyId = document.querySelector('[data-settings-company-select]')?.value || '';
    const companyIdFromRow = c.querySelector('[data-field="company_id"]')?.value || '';
    const departmentIds = getSelectedDepartmentIds(c);
    return {
      first_name: c.querySelector('[data-field="first_name"]')?.value.trim() || '',
      last_name: c.querySelector('[data-field="last_name"]')?.value.trim() || '',
      email: c.querySelector('[data-field="email"]')?.value.trim() || '',
      role: c.querySelector('[data-field="role"]')?.value || 'employee',
      department_id: departmentIds[0] || null,
      department_ids: departmentIds,
      company_id: companyIdFromRow || settingsCompanyId || null,
      password: c.querySelector('[data-field="password"]')?.value || ''
    };
  }

  function validateDepartmentsForRole(role, departmentIds) {
    if (role !== 'department_manager') return true;
    if (Array.isArray(departmentIds) && departmentIds.length > 0) return true;
    const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const isFr = locale.startsWith('fr');
    const isIt = locale.startsWith('it');
    notifyError(isFr ? 'Selectionnez au moins un departement pour le responsable de departement.' : (isIt ? 'Seleziona almeno un dipartimento per il capo dipartimento.' : 'Select at least one department for a department manager.'));
    return false;
  }

  async function createUser() {
    const data = collectCreateData(); if (!data) return notifyError('Form not found.');
    if (!data.first_name || !data.last_name || !data.email) return notifyError('Fill required fields.');
    if (!validateDepartmentsForRole(data.role, data.department_ids)) return;
    try {
      const res = await AppAPI.users.create(apiUrl, data);
      if (res?.ok) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('users', 'Done', 'User created successfully.');
        } else {
          notifySuccess('User created successfully.');
          location.reload();
        }
      } else {
        notifyError('Create failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) { console.error(e); notifyError('Error creating user.'); }
  }

  function getUserCard(el) { return el.closest && el.closest('.settings-list-item-wrap[data-user-id]'); }

  function getUserDrawer(card) {
    return card ? card.querySelector('.settings-edit-drawer') : null;
  }

  function closeAllDrawers() {
    document.querySelectorAll('[data-user-id] .settings-edit-drawer').forEach((drawer) => {
      drawer.hidden = true;
    });
  }

  function openDrawer(card) {
    if (!card) return;
    closeAllDrawers();
    const drawer = getUserDrawer(card);
    if (drawer) drawer.hidden = false;
  }

  function closeDrawer(card) {
    const drawer = getUserDrawer(card);
    if (drawer) drawer.hidden = true;
  }

  async function saveUser(card) {
    if (!card) return;
    const id = parseInt(card.dataset.userId || '0', 10);
    const payload = {
      id,
      first_name: card.querySelector('[data-field="first_name"]')?.value || '',
      last_name: card.querySelector('[data-field="last_name"]')?.value || '',
      email: card.querySelector('[data-field="email"]')?.value || '',
      role: card.querySelector('[data-field="role"]')?.value || 'employee',
      department_id: null,
      department_ids: getSelectedDepartmentIds(card),
      status: card.querySelector('[data-field="status"]')?.value || 'active',
    };
    payload.department_id = payload.department_ids[0] || null;
    if (!validateDepartmentsForRole(payload.role, payload.department_ids)) return;
    const pwd = card.querySelector('[data-field="password"]')?.value || '';
    if (pwd) payload.password = pwd;
    try {
      const res = await AppAPI.users.update(apiUrl, payload);
      if (res?.ok) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('users', 'Done', 'User updated successfully.');
        } else {
          notifySuccess('User updated successfully.');
          location.reload();
        }
      } else {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) { console.error(e); notifyError('Error saving user.'); }
  }

  async function deleteUser(card) {
    if (!card) return;
    const id = parseInt(card.dataset.userId || '0', 10); if (!id) return;
    const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const isFr = locale.startsWith('fr');
    const isIt = locale.startsWith('it');
    const tr = (en, fr, it) => (isFr ? fr : (isIt && it ? it : en));
    const canDelete = feedback?.confirm ? await feedback.confirm(tr('Delete this user?', 'Supprimer cet utilisateur ?', 'Eliminare questo utente?'), tr('Confirm deletion', 'Confirmer la suppression', 'Conferma eliminazione')) : false;
    if (!feedback?.confirm) {
      notifyError('Confirmation dialog is not available.');
      return;
    }
    if (!canDelete) return;
    try {
      const res = await AppAPI.users.delete(apiUrl, id);
      if (res?.ok) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('users', 'Done', 'User deleted successfully.');
        } else {
          notifySuccess('User deleted successfully.');
          card.remove();
        }
      } else {
        notifyError('Delete failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) { console.error(e); notifyError('Error deleting user.'); }
  }

  async function saveCompanySignatureIp() {
    const input = document.querySelector('[data-company-signature-ip]');
    if (!input) return;

    const companyId = parseInt(input.dataset.companyId || '0', 10);
    if (!companyId || !companyApiUrl) {
      notifyError('Company configuration is not available.');
      return;
    }

    const ip = (input.value || '').trim();
    try {
      const res = await AppAPI.companies.setSignatureIp(companyApiUrl, companyId, ip);
      if (res?.ok) {
        notifySuccess('Company Wi-Fi IP saved.');
      } else {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error while saving company Wi-Fi IP.');
    }
  }

  document.addEventListener('click', (ev) => {
    const insideMultiselect = ev.target.closest && ev.target.closest('.settings-multiselect');
    if (!insideMultiselect) {
      closeAllDepartmentDropdowns();
    }
    const saveCompanyIpBtn = ev.target.closest && ev.target.closest('[data-company-signature-ip-save]');
    if (saveCompanyIpBtn) { ev.preventDefault(); saveCompanySignatureIp(); return; }
    const createBtn = ev.target.closest && ev.target.closest('.settings-user-create');
    if (createBtn) { ev.preventDefault(); createUser(); return; }
    const resetBtn = ev.target.closest && ev.target.closest('.settings-user-reset');
    if (resetBtn) { ev.preventDefault(); resetCreateUserForm(); return; }
    const editBtn = ev.target.closest && ev.target.closest('.settings-user-edit');
    if (editBtn) { ev.preventDefault(); const card = getUserCard(editBtn); openDrawer(card); initDepartmentDropdowns(); return; }
    const cancelBtn = ev.target.closest && ev.target.closest('.settings-user-cancel');
    if (cancelBtn) { ev.preventDefault(); const card = getUserCard(cancelBtn); closeDrawer(card); return; }
    const saveBtn = ev.target.closest && ev.target.closest('.settings-user-save');
    if (saveBtn) { ev.preventDefault(); const card = getUserCard(saveBtn); saveUser(card); return; }
    const delBtn = ev.target.closest && ev.target.closest('.settings-user-delete');
    if (delBtn) { ev.preventDefault(); const card = getUserCard(delBtn); deleteUser(card); return; }
  });

  document.addEventListener('mousedown', (ev) => {
    if (!isUsersPanelActive()) return;
    const option = ev.target.closest && ev.target.closest('option');
    if (!option) return;
    const select = option.parentElement;
    if (!select || select.tagName !== 'SELECT') return;
    if (select.getAttribute('data-field') !== 'department_ids' || !select.multiple) return;
    ev.preventDefault();
    option.selected = !option.selected;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  });

  initDepartmentDropdowns();
  bindRoleSelectionRules();
  resetCreateUserForm();
})();
