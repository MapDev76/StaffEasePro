(function () {
  var form = document.getElementById('send-notification-form');
  if (!form || !window.AppAPI || !window.DashboardConfig || !window.DashboardConfig.apiDashboard) {
    return;
  }

  var config = window.DashboardConfig;
  var sendAllCheckbox = document.getElementById('send-notification-all');
  var recipientsBox = document.querySelector('[data-notification-recipients]');
  var companySelect = document.getElementById('send-notification-company');
  var requireResponseCheckbox = document.getElementById('send-notification-require-response');

  function syncCompanyFilter() {
    if (!companySelect) {
      return;
    }
    var companyId = companySelect.value;
    document.querySelectorAll('[data-notification-recipient-row]').forEach(function (row) {
      var rowCompanyId = row.getAttribute('data-company-id') || '';
      row.hidden = !!companyId && rowCompanyId !== companyId;
    });
  }
  if (companySelect) {
    companySelect.addEventListener('change', syncCompanyFilter);
    syncCompanyFilter();
  }

  function notifyError(message) {
    var feedback = window.DashboardFeedback;
    if (feedback && feedback.error) {
      feedback.error('Oops!', message);
    } else {
      window.alert(message);
    }
  }

  function notifySuccess(message) {
    var feedback = window.DashboardFeedback;
    if (feedback && feedback.success) {
      feedback.success('Done', message);
    }
  }

  if (sendAllCheckbox && recipientsBox) {
    var syncRecipientsVisibility = function () {
      recipientsBox.hidden = sendAllCheckbox.checked;
    };
    sendAllCheckbox.addEventListener('change', syncRecipientsVisibility);
    syncRecipientsVisibility();
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    var title = document.getElementById('send-notification-title').value.trim();
    var message = document.getElementById('send-notification-message').value.trim();
    var sendToAll = !!(sendAllCheckbox && sendAllCheckbox.checked);
    var recipientIds = [];
    if (!sendToAll && recipientsBox) {
      recipientsBox.querySelectorAll('[data-notification-recipient]:checked').forEach(function (input) {
        var id = parseInt(input.value, 10);
        if (id) {
          recipientIds.push(id);
        }
      });
      if (!recipientIds.length) {
        notifyError(window.NotificationsLabels && window.NotificationsLabels.noRecipients || 'Select at least one employee.');
        return;
      }
    }

    if (companySelect && !companySelect.value) {
      notifyError('Seleziona un\'azienda.');
      return;
    }

    var submitBtn = document.getElementById('send-notification-submit');
    submitBtn.disabled = true;

    window.AppAPI.postJSON(config.apiDashboard, {
      action: 'send_general_notifications',
      title: title,
      message: message,
      send_to_all: sendToAll ? 1 : 0,
      recipient_ids: recipientIds,
      requires_response: (requireResponseCheckbox && requireResponseCheckbox.checked) ? 1 : 0,
      company_id: companySelect ? parseInt(companySelect.value, 10) || 0 : undefined,
    })
      .then(function (result) {
        submitBtn.disabled = false;
        if (!result || !result.success) {
          notifyError((result && result.error) || 'Error');
          return;
        }
        notifySuccess((window.NotificationsLabels && window.NotificationsLabels.sentSuccess) || 'Notification sent.');
        form.reset();
        if (sendAllCheckbox && recipientsBox) {
          sendAllCheckbox.checked = true;
          recipientsBox.hidden = true;
        }
        var modal = document.getElementById('modal-send-notification');
        if (modal) {
          modal.hidden = true;
        }
      })
      .catch(function (error) {
        submitBtn.disabled = false;
        notifyError('Error');
        console.error(error);
      });
  });
})();
