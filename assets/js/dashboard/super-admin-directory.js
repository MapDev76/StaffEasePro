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

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
  }

  function renderCompanyConnections(panel, connections) {
    var body = panel.querySelector('[data-company-connections-body]');
    if (!body) {
      return;
    }
    if (!connections.length) {
      body.innerHTML = '<tr><td colspan="5">' + escapeHtml(panel.getAttribute('data-label-empty') || '-') + '</td></tr>';
      return;
    }
    var labelActive = panel.getAttribute('data-label-active') || 'Active';
    var labelDone = panel.getAttribute('data-label-done') || 'Done';
    var labelDelete = panel.getAttribute('data-label-delete') || 'Delete';
    var confirmDelete = panel.getAttribute('data-confirm-delete') || 'Delete this entry?';
    body.innerHTML = connections.map(function (conn) {
      var status = conn.is_active ? labelActive : labelDone;
      return '<tr data-connection-row="' + Number(conn.connection_id || 0) + '">'
        + '<td>' + escapeHtml(conn.user_name) + '</td>'
        + '<td>' + escapeHtml(conn.department_name || '-') + '</td>'
        + '<td>' + escapeHtml(conn.time_ago) + '</td>'
        + '<td>' + escapeHtml(status) + '</td>'
        + '<td><button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon-danger dashboard-connection-delete" data-connection-id="'
        + Number(conn.connection_id || 0) + '" data-confirm-message="' + escapeHtml(confirmDelete) + '">'
        + escapeHtml(labelDelete) + '</button></td>'
        + '</tr>';
    }).join('');
  }

  function showCompanyConnectionsError(panel) {
    var body = panel.querySelector('[data-company-connections-body]');
    if (body) {
      body.innerHTML = '<tr><td colspan="5">' + escapeHtml(panel.getAttribute('data-label-error') || 'Error') + '</td></tr>';
    }
  }

  function loadCompanyConnections(toggleBtn, panel) {
    var companyId = parseInt(toggleBtn.dataset.companyId || '0', 10) || 0;
    if (!companyId) {
      return;
    }
    window.AppAPI.postJSON(config.apiDashboard, { action: 'company_connections', company_id: companyId })
      .then(function (result) {
        if (!result || !result.success) {
          showCompanyConnectionsError(panel);
          return;
        }
        renderCompanyConnections(panel, result.connections || []);
      })
      .catch(function (error) {
        showCompanyConnectionsError(panel);
        console.error(error);
      });
  }

  // The company card is wrapped in a link: keep clicks on the notice select
  // from navigating away before a template can be chosen.
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-company-notice]') && !event.target.closest('.dashboard-company-notice-send')) {
      event.preventDefault();
      event.stopPropagation();
    }
    if (event.target.closest('[data-company-trial-edit]') && !event.target.closest('.dashboard-company-trial-save') && !event.target.closest('.dashboard-company-trial-no-expiry-toggle')) {
      event.preventDefault();
      event.stopPropagation();
    }
  });



  document.addEventListener('click', function (event) {
    var noExpiryBtn = event.target.closest('.dashboard-company-trial-no-expiry-toggle');
    if (noExpiryBtn) {
      event.preventDefault();
      event.stopPropagation();
      var isNoExpiry = noExpiryBtn.getAttribute('data-no-expiry') === '1';
      var nextNoExpiry = !isNoExpiry;
      noExpiryBtn.setAttribute('data-no-expiry', nextNoExpiry ? '1' : '0');
      noExpiryBtn.setAttribute('aria-pressed', nextNoExpiry ? 'true' : 'false');
      noExpiryBtn.classList.toggle('is-active', nextNoExpiry);
      var toggleBox = noExpiryBtn.closest('[data-company-trial-edit]');
      var toggleDateInput = toggleBox ? toggleBox.querySelector('[data-trial-date]') : null;
      if (toggleDateInput) {
        toggleDateInput.disabled = nextNoExpiry;
      }
      return;
    }

    var trialSaveBtn = event.target.closest('.dashboard-company-trial-save');
    if (trialSaveBtn) {
      event.preventDefault();
      event.stopPropagation();
      var trialCompanyId = parseInt(trialSaveBtn.dataset.companyId || '0', 10) || 0;
      var trialBox = trialSaveBtn.closest('[data-company-trial-edit]');
      var dateInput = trialBox ? trialBox.querySelector('[data-trial-date]') : null;
      var noExpiryToggle = trialBox ? trialBox.querySelector('[data-trial-no-expiry]') : null;
      if (!trialCompanyId || !dateInput || !noExpiryToggle) {
        return;
      }
      var noExpiry = noExpiryToggle.getAttribute('data-no-expiry') === '1';
      if (!noExpiry && !dateInput.value) {
        notifyError('Choose a date or check "no expiry".');
        return;
      }
      trialSaveBtn.disabled = true;
      window.AppAPI.postJSON(config.apiDashboard, {
        action: 'set_company_trial',
        company_id: trialCompanyId,
        trial_ends_at: dateInput.value,
        no_expiry: noExpiry ? 1 : 0,
      })
        .then(function (result) {
          trialSaveBtn.disabled = false;
          if (!result || !result.success) {
            notifyError((result && result.error) || 'Error');
            return;
          }
          notifySuccess('Saved.');
          window.setTimeout(function () { window.location.reload(); }, 700);
        })
        .catch(function (error) {
          trialSaveBtn.disabled = false;
          notifyError('Error');
          console.error(error);
        });
      return;
    }

    var noticeBtn = event.target.closest('.dashboard-company-notice-send');
    if (noticeBtn) {
      event.preventDefault();
      event.stopPropagation();
      var noticeCompanyId = parseInt(noticeBtn.dataset.companyId || '0', 10) || 0;
      var noticeBox = noticeBtn.closest('[data-company-notice]');
      var select = noticeBox ? noticeBox.querySelector('[data-notice-template]') : null;
      var template = select ? select.value : '';
      if (!noticeCompanyId || !template) {
        return;
      }
      if (!window.confirm(noticeBtn.getAttribute('data-confirm-message') || template)) {
        return;
      }
      noticeBtn.disabled = true;
      window.AppAPI.postJSON(config.apiDashboard, {
        action: 'send_company_notice',
        company_id: noticeCompanyId,
        template: template,
      })
        .then(function (result) {
          noticeBtn.disabled = false;
          if (!result || !result.success) {
            notifyError((result && result.error) || 'Error');
            return;
          }
          notifySuccess(result.message || 'Sent.');
        })
        .catch(function (error) {
          noticeBtn.disabled = false;
          notifyError('Error');
          console.error(error);
        });
      return;
    }

    var decideBtn = event.target.closest('.dashboard-approval-decide');
    if (decideBtn) {
      event.preventDefault();
      event.stopPropagation();
      var approvalCompanyId = parseInt(decideBtn.dataset.companyId || '0', 10) || 0;
      var decision = decideBtn.getAttribute('data-decision') || '';
      if (!approvalCompanyId || !decision) {
        return;
      }
      if (!window.confirm(decideBtn.getAttribute('data-confirm-message') || decision)) {
        return;
      }
      var approvalCard = decideBtn.closest('[data-approval-row]');
      var buttons = approvalCard ? approvalCard.querySelectorAll('.dashboard-approval-decide') : [decideBtn];
      buttons.forEach(function (b) { b.disabled = true; });

      window.AppAPI.postJSON(config.apiDashboard, {
        action: 'decide_company_approval',
        company_id: approvalCompanyId,
        decision: decision,
      })
        .then(function (result) {
          if (!result || !result.success) {
            buttons.forEach(function (b) { b.disabled = false; });
            notifyError((result && result.error) || 'Error');
            return;
          }
          notifySuccess(result.decision === 'approved' ? 'Request approved.' : 'Request rejected.');
          // The decided request leaves the pending list; drop the whole section
          // once it was the last one so no empty box is left behind.
          if (approvalCard && approvalCard.parentNode) {
            var section = approvalCard.closest('[data-approvals-section]');
            approvalCard.parentNode.removeChild(approvalCard);
            if (section && section.querySelectorAll('[data-approval-row]').length === 0) {
              section.parentNode.removeChild(section);
            }
          }
          // The company card still shows the old state: reload to refresh it.
          window.setTimeout(function () { window.location.reload(); }, 900);
        })
        .catch(function (error) {
          buttons.forEach(function (b) { b.disabled = false; });
          notifyError('Error');
          console.error(error);
        });
      return;
    }

    var connectionsToggleBtn = event.target.closest('.dashboard-company-connections-toggle');
    if (connectionsToggleBtn) {
      event.preventDefault();
      event.stopPropagation();
      var card = connectionsToggleBtn.closest('.dashboard-company-card');
      var panel = card && card.querySelector('[data-company-connections-panel]');
      if (!panel) {
        return;
      }
      if (panel.hasAttribute('hidden')) {
        panel.removeAttribute('hidden');
        connectionsToggleBtn.setAttribute('aria-expanded', 'true');
        loadCompanyConnections(connectionsToggleBtn, panel);
      } else {
        panel.setAttribute('hidden', '');
        connectionsToggleBtn.setAttribute('aria-expanded', 'false');
      }
      return;
    }

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
