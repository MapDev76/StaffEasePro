(() => {
  const apiUrl = window.DashboardConfig?.apiCompanies;
  const feedback = window.DashboardFeedback;
  if (!apiUrl || !window.AppAPI) return;

  const panel = document.querySelector('[data-settings-panel="companies"]');
  if (!panel) return;

  function notifyError(message) {
    if (feedback?.error) {
      feedback.error('Oops!', message);
      return;
    }
    console.error(message);
  }

  function notifySuccess(message) {
    if (feedback?.success) {
      feedback.success('Done', message);
    }
  }

  function collectPayload(scope) {
    const get = (field) => scope.querySelector(`[data-field="${field}"]`)?.value?.trim() || '';
    const logoFileInput = scope.querySelector('[data-field="logo_file"]');
    const logoFile = logoFileInput && logoFileInput.files && logoFileInput.files.length > 0
      ? logoFileInput.files[0]
      : null;
    return {
      name: get('name'),
      type: get('type') || 'other',
      address: get('address'),
      city: get('city'),
      zip_code: get('zip_code'),
      phone: get('phone'),
      email: get('email'),
      logo_path: get('logo_path'),
      logo_file: logoFile,
      signature_ip: get('signature_ip'),
    };
  }

  function clearCreateForm(row) {
    row.querySelectorAll('[data-field]').forEach((el) => {
      if (el.tagName === 'SELECT') {
        if (el.getAttribute('data-field') === 'type') {
          el.value = 'other';
          return;
        }
        el.selectedIndex = 0;
        return;
      }
      if (el.type === 'file') {
        el.value = '';
        return;
      }
      el.value = '';
    });
  }

  function closeAllDrawers() {
    panel.querySelectorAll('.settings-edit-drawer').forEach((drawer) => {
      drawer.hidden = true;
    });
  }

  function getCard(target) {
    return target.closest('.settings-list-item-wrap[data-company-id]');
  }

  async function createCompany(row) {
    const payload = collectPayload(row);
    if (!payload.name) {
      notifyError('Enter company name.');
      return;
    }

    try {
      const res = await AppAPI.companies.create(apiUrl, payload);
      if (!res?.ok) {
        notifyError('Create failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('companies', 'Done', 'Company created successfully.');
      } else {
        notifySuccess('Company created successfully.');
        location.reload();
      }
    } catch (error) {
      notifyError('Error creating company.');
      console.error(error);
    }
  }

  async function saveCompany(card) {
    const id = parseInt(card?.dataset.companyId || '0', 10) || 0;
    if (!id) return;

    const drawer = card.querySelector('.settings-edit-drawer');
    if (!drawer) return;

    const payload = collectPayload(drawer);
    if (!payload.name) {
      notifyError('Enter company name.');
      return;
    }

    try {
      const res = await AppAPI.companies.update(apiUrl, { id, ...payload });
      if (!res?.ok) {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('companies', 'Done', 'Company updated successfully.');
      } else {
        notifySuccess('Company updated successfully.');
        location.reload();
      }
    } catch (error) {
      notifyError('Error saving company.');
      console.error(error);
    }
  }

  async function deleteCompany(card) {
    const id = parseInt(card?.dataset.companyId || '0', 10) || 0;
    if (!id) return;

    if (!feedback?.confirm) {
      notifyError('Confirmation dialog is not available.');
      return;
    }

    const _locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const _isFr = _locale.startsWith('fr');
    const _isIt = _locale.startsWith('it');
    const _tr = (en, fr, it) => (_isFr ? fr : (_isIt && it ? it : en));
    const confirmed = await feedback.confirm(_tr('Delete this company?', 'Supprimer cette entreprise ?', 'Eliminare questa azienda?'), _tr('Confirm deletion', 'Confirmer la suppression', 'Conferma eliminazione'));
    if (!confirmed) return;

    try {
      const res = await AppAPI.companies.delete(apiUrl, id);
      if (!res?.ok) {
        notifyError('Delete failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('companies', 'Done', 'Company deleted successfully.');
      } else {
        notifySuccess('Company deleted successfully.');
        card.remove();
      }
    } catch (error) {
      notifyError('Error deleting company.');
      console.error(error);
    }
  }

  async function toggleCompanyActive(card, button) {
    const id = parseInt(card?.dataset.companyId || '0', 10) || 0;
    if (!id || !button) return;

    const currentActive = Number(button.getAttribute('data-company-active') || '1') === 1;
    const nextActive = currentActive ? 0 : 1;

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'set_active',
        company_id: id,
        is_active: nextActive,
      });
      if (!res?.ok) {
        notifyError('Status update failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('companies', 'Done', nextActive === 1 ? 'Company activated successfully.' : 'Company deactivated successfully.');
      } else {
        notifySuccess(nextActive === 1 ? 'Company activated successfully.' : 'Company deactivated successfully.');
        location.reload();
      }
    } catch (error) {
      notifyError('Error updating company status.');
      console.error(error);
    }
  }

  panel.addEventListener('click', (event) => {
    const createButton = event.target.closest('.settings-company-create');
    if (createButton) {
      const createRow = panel.querySelector('[data-company-create-row]');
      if (createRow) createCompany(createRow);
      return;
    }

    const resetButton = event.target.closest('.settings-company-reset');
    if (resetButton) {
      const createRow = panel.querySelector('[data-company-create-row]');
      if (createRow) clearCreateForm(createRow);
      return;
    }

    const editButton = event.target.closest('.settings-company-edit');
    if (editButton) {
      const card = getCard(editButton);
      if (!card) return;
      const drawer = card.querySelector('.settings-edit-drawer');
      if (!drawer) return;
      const isOpen = !drawer.hidden;
      closeAllDrawers();
      drawer.hidden = isOpen;
      return;
    }

    const cancelButton = event.target.closest('.settings-company-cancel');
    if (cancelButton) {
      const card = getCard(cancelButton);
      if (!card) return;
      const drawer = card.querySelector('.settings-edit-drawer');
      if (drawer) drawer.hidden = true;
      return;
    }

    const saveButton = event.target.closest('.settings-company-save');
    if (saveButton) {
      const card = getCard(saveButton);
      if (card) saveCompany(card);
      return;
    }

    const deleteButton = event.target.closest('.settings-company-delete');
    if (deleteButton) {
      const card = getCard(deleteButton);
      if (card) deleteCompany(card);
      return;
    }

    const toggleButton = event.target.closest('.settings-company-toggle');
    if (toggleButton) {
      const card = getCard(toggleButton);
      if (card) toggleCompanyActive(card, toggleButton);
    }
  });
})();
