(function () {
  var modal = document.getElementById('modal-notifications');
  if (!modal || !window.AppAPI || !window.DashboardConfig || !window.DashboardConfig.apiDashboard) {
    return;
  }

  var config = window.DashboardConfig;
  var list = modal.querySelector('[data-notifications-list]');
  var labels = window.NotificationsLabels || {};

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
  }

  function label(key, fallback) {
    return labels[key] || fallback;
  }

  function formatDate(raw) {
    if (!raw) {
      return '';
    }
    var d = new Date(String(raw).replace(' ', 'T'));
    if (isNaN(d.getTime())) {
      return raw;
    }
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function updateBadge() {
    var unread = list.querySelectorAll('[data-notification-row][data-status="pending"]').length;
    document.querySelectorAll('[data-notifications-badge]').forEach(function (badge) {
      if (unread > 0) {
        badge.textContent = unread > 9 ? '9+' : String(unread);
        badge.hidden = false;
      } else {
        badge.hidden = true;
        badge.textContent = '0';
      }
    });
  }

  function renderRow(notification) {
    var isRead = notification.status === 'read';
    var row = document.createElement('article');
    row.className = 'notification-row' + (isRead ? ' is-read' : ' is-unread');
    row.setAttribute('data-notification-row', '');
    row.setAttribute('data-status', notification.status || 'pending');
    row.setAttribute('data-notification-id', String(notification.id));

    row.innerHTML =
      '<div class="notification-row-head">'
      + '<strong>' + escapeHtml(notification.title || '') + '</strong>'
      + '<span class="notification-row-date">' + escapeHtml(formatDate(notification.created_at)) + '</span>'
      + '</div>'
      + '<p class="notification-row-message">' + escapeHtml(notification.message || '') + '</p>'
      + '<div class="notification-row-actions">'
      + '<button type="button" class="admin-action-link admin-action-link-secondary notification-mark-read"'
      + (isRead ? ' hidden' : '') + '>' + escapeHtml(label('markRead', 'Mark as read')) + '</button>'
      + '<button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon-danger notification-delete"'
      + (isRead ? '' : ' disabled title="' + escapeHtml(label('deleteRequiresRead', 'Read it first to delete it.')) + '"') + '>'
      + escapeHtml(label('delete', 'Delete')) + '</button>'
      + '</div>';

    return row;
  }

  function render(notifications) {
    list.innerHTML = '';
    if (!notifications.length) {
      var empty = document.createElement('p');
      empty.className = 'notifications-empty';
      empty.textContent = label('empty', 'No notifications.');
      list.appendChild(empty);
      updateBadge();
      return;
    }
    notifications.forEach(function (notification) {
      list.appendChild(renderRow(notification));
    });
    updateBadge();
  }

  function load() {
    list.innerHTML = '<p class="notifications-empty">' + escapeHtml(label('loading', 'Loading…')) + '</p>';
    window.AppAPI.postJSON(config.apiDashboard, { action: 'list_notifications' })
      .then(function (result) {
        if (!result || !result.success) {
          list.innerHTML = '<p class="notifications-empty">' + escapeHtml(label('error', 'Error')) + '</p>';
          return;
        }
        render(result.notifications || []);
      })
      .catch(function () {
        list.innerHTML = '<p class="notifications-empty">' + escapeHtml(label('error', 'Error')) + '</p>';
      });
  }

  document.querySelectorAll('[data-modal-target="modal-notifications"]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      modal.hidden = false;
      load();
    });
  });
  modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      modal.hidden = true;
    });
  });

  list.addEventListener('click', function (event) {
    var row = event.target.closest('[data-notification-row]');
    if (!row) {
      return;
    }
    var notificationId = parseInt(row.getAttribute('data-notification-id') || '0', 10) || 0;
    if (!notificationId) {
      return;
    }

    if (event.target.closest('.notification-mark-read')) {
      window.AppAPI.postJSON(config.apiDashboard, { action: 'mark_notification_read', notification_id: notificationId })
        .then(function (result) {
          if (!result || !result.success) {
            return;
          }
          row.setAttribute('data-status', 'read');
          row.classList.remove('is-unread');
          row.classList.add('is-read');
          var markBtn = row.querySelector('.notification-mark-read');
          var deleteBtn = row.querySelector('.notification-delete');
          if (markBtn) {
            markBtn.hidden = true;
          }
          if (deleteBtn) {
            deleteBtn.disabled = false;
            deleteBtn.removeAttribute('title');
          }
          updateBadge();
        });
      return;
    }

    if (event.target.closest('.notification-delete')) {
      var deleteButton = event.target.closest('.notification-delete');
      if (deleteButton.disabled) {
        return;
      }
      window.AppAPI.postJSON(config.apiDashboard, { action: 'delete_notification', notification_id: notificationId })
        .then(function (result) {
          if (!result || !result.success) {
            return;
          }
          if (row.parentNode) {
            row.parentNode.removeChild(row);
          }
          if (!list.querySelector('[data-notification-row]')) {
            render([]);
          } else {
            updateBadge();
          }
        });
    }
  });
})();
