(function () {
  function generateSecurePassword(length) {
    length = length || 12;
    var lower = 'abcdefghijkmnpqrstuvwxyz';
    var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    var digits = '23456789';
    var all = lower + upper + digits;
    var cryptoObj = window.crypto || window.msCrypto;
    var values = new Uint32Array(length);
    cryptoObj.getRandomValues(values);
    var result = '';
    for (var i = 0; i < length; i++) {
      result += all[values[i] % all.length];
    }
    if (!/[A-Za-z]/.test(result)) {
      result = 'A' + result.slice(1);
    }
    if (!/[0-9]/.test(result)) {
      result = result.slice(0, -1) + '5';
    }
    return result;
  }

  document.addEventListener('click', function (event) {
    var genBtn = event.target.closest('[data-generate-password]');
    if (!genBtn) {
      return;
    }
    event.preventDefault();
    var wrapper = genBtn.closest('.password-field-with-generate');
    var input = wrapper ? wrapper.querySelector('input') : null;
    if (!input) {
      return;
    }
    var generated = generateSecurePassword(12);
    input.value = generated;
    input.type = 'text';

    var alsoId = genBtn.getAttribute('data-generate-password-also');
    if (alsoId) {
      var alsoInput = document.getElementById(alsoId);
      if (alsoInput) {
        alsoInput.value = generated;
        alsoInput.type = 'text';
      }
    }
  });

  // Self-contained open/close for the change-password modal: on the
  // dashboard route, assets/js/dashboard.js already handles generic
  // [data-modal-target]/[data-modal-close] wiring, but that script is not
  // loaded on my-space, so this modal needs its own minimal open/close
  // logic to work on both routes without depending on dashboard.js.
  var changePasswordModal = document.getElementById('modal-change-password');
  if (changePasswordModal) {
    document.querySelectorAll('[data-modal-target="modal-change-password"]').forEach(function (trigger) {
      trigger.addEventListener('click', function (event) {
        event.preventDefault();
        changePasswordModal.hidden = false;
      });
    });
    changePasswordModal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        changePasswordModal.hidden = true;
      });
    });
  }

  var form = document.getElementById('change-password-form');
  if (!form || !window.AppAPI || !window.DashboardConfig || !window.DashboardConfig.apiDashboard) {
    return;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var current = document.getElementById('change-password-current');
    var next = document.getElementById('change-password-new');
    var confirmField = document.getElementById('change-password-new-confirm');
    var submitBtn = document.getElementById('change-password-submit');
    submitBtn.disabled = true;

    window.AppAPI.postJSON(window.DashboardConfig.apiDashboard, {
      action: 'change_password',
      current_password: current.value,
      new_password: next.value,
      new_password_confirm: confirmField.value,
    }).then(function (result) {
      submitBtn.disabled = false;
      var feedback = window.DashboardFeedback;
      if (!result || !result.success) {
        var message = (result && result.error) || 'Error';
        if (feedback && feedback.error) {
          feedback.error('Oops!', message);
        } else {
          window.alert(message);
        }
        return;
      }
      if (feedback && feedback.success) {
        feedback.success('Done', 'Password updated successfully.');
      }
      form.reset();
      if (changePasswordModal) {
        changePasswordModal.hidden = true;
      }
    }).catch(function (error) {
      submitBtn.disabled = false;
      console.error(error);
    });
  });
})();
