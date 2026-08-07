(function () {
  var config = window.DashboardConfig || {};
  if (!config.apiCompanies || !config.apiDashboard || !window.AppAPI) {
    return;
  }

  function notifyError(message) {
    var feedback = window.DashboardFeedback;
    if (feedback && feedback.error) {
      feedback.error('Oops!', message);
    } else {
      console.error(message);
    }
  }

  function notifySuccess(message) {
    var feedback = window.DashboardFeedback;
    if (feedback && feedback.success) {
      feedback.success('Done', message);
    }
  }

  document.addEventListener('click', function (event) {
    var toggleBtn = event.target.closest('.dashboard-company-toggle-active');
    if (toggleBtn) {
      event.preventDefault();
      event.stopPropagation();
      var companyId = parseInt(toggleBtn.dataset.companyId || '0', 10) || 0;
      if (!companyId) {
        return;
      }
      var nextActive = Number(toggleBtn.getAttribute('data-company-active') || '1') === 1 ? 0 : 1;
      toggleBtn.disabled = true;
      window.AppAPI.postJSON(config.apiCompanies, { action: 'set_active', company_id: companyId, is_active: nextActive })
        .then(function (result) {
          toggleBtn.disabled = false;
          if (!result || !result.ok) {
            notifyError('Status update failed: ' + ((result && result.error) || 'unknown'));
            return;
          }
          toggleBtn.setAttribute('data-company-active', String(nextActive));
          toggleBtn.textContent = nextActive === 1
            ? (toggleBtn.getAttribute('data-label-when-active') || toggleBtn.textContent)
            : (toggleBtn.getAttribute('data-label-when-inactive') || toggleBtn.textContent);
          notifySuccess(nextActive === 1 ? 'Company activated successfully.' : 'Company deactivated successfully.');
        })
        .catch(function (error) {
          toggleBtn.disabled = false;
          notifyError('Status update failed.');
          console.error(error);
        });
      return;
    }

    var deleteBtn = event.target.closest('.dashboard-connection-delete');
    if (deleteBtn) {
      event.preventDefault();
      event.stopPropagation();
      var connectionId = parseInt(deleteBtn.dataset.connectionId || '0', 10) || 0;
      if (!connectionId) {
        return;
      }
      if (!window.confirm(deleteBtn.getAttribute('data-confirm-message') || 'Delete this entry?')) {
        return;
      }
      deleteBtn.disabled = true;
      window.AppAPI.postJSON(config.apiDashboard, { action: 'delete_connection', connection_id: connectionId })
        .then(function (result) {
          if (!result || !result.success) {
            deleteBtn.disabled = false;
            notifyError('Delete failed: ' + ((result && result.error) || 'unknown'));
            return;
          }
          var row = deleteBtn.closest('[data-connection-row]');
          if (row && row.parentNode) {
            row.parentNode.removeChild(row);
          }
          notifySuccess('Entry deleted successfully.');
        })
        .catch(function (error) {
          deleteBtn.disabled = false;
          notifyError('Error deleting entry.');
          console.error(error);
        });
    }
  });
})();
