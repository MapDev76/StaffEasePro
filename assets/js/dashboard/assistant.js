(() => {
  const config = window.DashboardConfig;
  const labels = window.AssistantLabels || {};
  const AUTO_MODE_STORAGE_KEY = 'staffease_assistant_auto_mode';

  const trigger = document.querySelector('[data-assistant-open]');
  const panel = document.getElementById('assistant-panel');
  if (!trigger || !panel) return;

  const closeBtn = panel.querySelector('[data-assistant-close]');

  trigger.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) {
      const chatInput = panel.querySelector('[data-assistant-input]');
      if (chatInput) {
        chatInput.focus();
        // Chat mode only — history is fetched from the server. In tutorial
        // mode (no chatInput) there is nothing to load.
        // eslint-disable-next-line no-use-before-define
        if (typeof loadHistory === 'function') loadHistory();
      }
    }
  });

  closeBtn?.addEventListener('click', () => {
    panel.hidden = true;
  });

  // --- Tutorial mode: static menu, no AI configured yet ---
  const tutorial = panel.querySelector('[data-assistant-tutorial]');
  if (tutorial) {
    const menu = tutorial.querySelector('[data-assistant-tutorial-menu]');

    tutorial.addEventListener('click', (evt) => {
      const topicBtn = evt.target.closest('[data-assistant-tutorial-topic]');
      if (topicBtn) {
        const topicId = topicBtn.getAttribute('data-assistant-tutorial-topic');
        const stepsEl = tutorial.querySelector('[data-assistant-tutorial-steps="' + topicId + '"]');
        if (menu) menu.hidden = true;
        if (stepsEl) stepsEl.hidden = false;
        return;
      }

      const backBtn = evt.target.closest('[data-assistant-tutorial-back]');
      if (backBtn) {
        tutorial.querySelectorAll('[data-assistant-tutorial-steps]').forEach((el) => { el.hidden = true; });
        if (menu) menu.hidden = false;
      }
    });

    return;
  }

  // --- Chat mode: AI is configured ---
  if (!config || !config.apiAssistant) return;

  const messagesEl = panel.querySelector('[data-assistant-messages]');
  const emptyEl = panel.querySelector('[data-assistant-empty]');
  const form = panel.querySelector('[data-assistant-form]');
  const input = panel.querySelector('[data-assistant-input]');
  const sendBtn = panel.querySelector('[data-assistant-send]');
  const clearBtn = panel.querySelector('[data-assistant-clear]');
  const autoModeCheckbox = panel.querySelector('[data-assistant-auto-mode]');
  const quickQuestionsEl = panel.querySelector('[data-assistant-quick-questions]');
  if (!form || !input || !sendBtn || !messagesEl) return;

  if (autoModeCheckbox) {
    autoModeCheckbox.checked = localStorage.getItem(AUTO_MODE_STORAGE_KEY) === '1';
    autoModeCheckbox.addEventListener('change', () => {
      localStorage.setItem(AUTO_MODE_STORAGE_KEY, autoModeCheckbox.checked ? '1' : '0');
    });
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
  }

  function scrollToBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function appendMessage(role, text) {
    if (emptyEl) emptyEl.hidden = true;
    const row = document.createElement('div');
    row.className = 'assistant-message assistant-message-' + (role === 'user' ? 'user' : 'assistant');
    row.innerHTML = '<div class="assistant-message-bubble"></div>';
    row.querySelector('.assistant-message-bubble').textContent = text;
    messagesEl.appendChild(row);
    scrollToBottom();
    return row;
  }

  function appendThinking() {
    const row = document.createElement('div');
    row.className = 'assistant-message assistant-message-assistant assistant-message-thinking';
    row.setAttribute('data-assistant-thinking', '');
    row.innerHTML = '<div class="assistant-message-bubble">' + escapeHtml(labels.thinking || 'Thinking…') + '</div>';
    messagesEl.appendChild(row);
    scrollToBottom();
    return row;
  }

  function removePendingRow() {
    messagesEl.querySelector('[data-assistant-pending-row]')?.remove();
  }

  function appendPendingAction(pendingAction) {
    removePendingRow();
    if (!pendingAction || !pendingAction.summary) return;

    const row = document.createElement('div');
    row.className = 'assistant-message assistant-message-assistant';
    row.setAttribute('data-assistant-pending-row', '');
    row.innerHTML =
      '<div class="assistant-pending-card">' +
        '<p class="assistant-pending-summary"></p>' +
        '<div class="assistant-pending-actions">' +
          '<button type="button" class="assistant-pending-confirm" data-assistant-pending-confirm></button>' +
          '<button type="button" class="assistant-pending-cancel" data-assistant-pending-cancel></button>' +
        '</div>' +
      '</div>';
    row.querySelector('.assistant-pending-summary').textContent = pendingAction.summary;
    row.querySelector('[data-assistant-pending-confirm]').textContent = labels.confirm || 'Confirm';
    row.querySelector('[data-assistant-pending-cancel]').textContent = labels.cancel || 'Cancel';
    messagesEl.appendChild(row);
    scrollToBottom();
  }

  async function resolvePending(confirmed) {
    removePendingRow();
    const thinkingRow = appendThinking();
    try {
      const response = await fetch(config.apiAssistant, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: confirmed ? 'confirm_pending' : 'cancel_pending' }),
      });
      const data = await response.json().catch(() => ({}));
      thinkingRow.remove();
      appendMessage('assistant', (data && data.reply) || labels.error || 'Something went wrong, please try again.');
    } catch (err) {
      thinkingRow.remove();
      appendMessage('assistant', labels.error || 'Something went wrong, please try again.');
    }
  }

  messagesEl.addEventListener('click', (evt) => {
    if (evt.target.closest('[data-assistant-pending-confirm]')) {
      resolvePending(true);
    } else if (evt.target.closest('[data-assistant-pending-cancel]')) {
      resolvePending(false);
    }
  });

  async function loadHistory() {
    try {
      const response = await fetch(config.apiAssistant, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'history' }),
      });
      const data = await response.json().catch(() => ({}));
      if (data.ok && Array.isArray(data.messages) && data.messages.length) {
        messagesEl.innerHTML = '';
        data.messages.forEach((msg) => appendMessage(msg.role, msg.content));
      }
      if (data.ok && data.pending_action) {
        appendPendingAction(data.pending_action);
      }
    } catch (err) {
      // Silent: empty state remains visible.
    }
  }

  async function sendMessage(text) {
    removePendingRow();
    appendMessage('user', text);
    const thinkingRow = appendThinking();
    sendBtn.disabled = true;
    try {
      const response = await fetch(config.apiAssistant, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'send',
          message: text,
          auto_mode: !!(autoModeCheckbox && autoModeCheckbox.checked),
        }),
      });
      const data = await response.json().catch(() => ({}));
      thinkingRow.remove();
      if (!data.ok) {
        appendMessage('assistant', labels.error || 'Something went wrong, please try again.');
        return;
      }
      appendMessage('assistant', data.reply || '');
      if (data.pending_action) {
        appendPendingAction(data.pending_action);
      }
    } catch (err) {
      thinkingRow.remove();
      appendMessage('assistant', labels.error || 'Something went wrong, please try again.');
    } finally {
      sendBtn.disabled = false;
    }
  }

  clearBtn?.addEventListener('click', async () => {
    await fetch(config.apiAssistant, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'clear' }),
    }).catch(() => {});
    messagesEl.innerHTML = '';
    if (emptyEl) {
      messagesEl.appendChild(emptyEl);
      emptyEl.hidden = false;
    }
  });

  quickQuestionsEl?.addEventListener('click', (evt) => {
    const chip = evt.target.closest('[data-assistant-quick-question]');
    if (!chip) return;
    sendMessage(chip.textContent.trim());
  });

  form.addEventListener('submit', (evt) => {
    evt.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    input.style.height = 'auto';
    sendMessage(text);
  });

  input.addEventListener('keydown', (evt) => {
    if (evt.key === 'Enter' && !evt.shiftKey) {
      evt.preventDefault();
      form.requestSubmit();
    }
  });

  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  });
})();
