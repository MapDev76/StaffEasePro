(() => {
  const L = window.WizardLabels || {};

  function fmt(template, replacements) {
    let result = template;
    Object.keys(replacements || {}).forEach((key) => {
      result = result.replace('{' + key + '}', replacements[key]);
    });
    return result;
  }

  function root() {
    return document.querySelector('[data-planning-wizard]');
  }

  function apiShiftsUrl() {
    return (window.DashboardConfig && window.DashboardConfig.apiShifts) || '';
  }

  async function postJSON(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || L.requestFailed || 'Request failed');
    }
    return data;
  }

  function feedbackError(message) {
    if (window.DashboardFeedback) window.DashboardFeedback.error('Oops!', message);
    else console.error(message);
  }

  function feedbackSuccess(message) {
    if (window.DashboardFeedback) window.DashboardFeedback.success(L.done || 'Done', message);
  }

  function stepEls(scope) {
    return Array.from(scope.querySelectorAll('[data-pw-step]'));
  }

  function stepNumber(el) {
    return parseInt(el.getAttribute('data-pw-step') || '0', 10) || 0;
  }

  function setActiveStep(scope, num) {
    stepEls(scope).forEach((stepEl) => {
      const n = stepNumber(stepEl);
      stepEl.classList.toggle('is-active', n === num);
      stepEl.classList.toggle('is-done', n < num);
      const body = stepEl.querySelector('[data-pw-step-body]');
      if (body) body.hidden = n !== num;
    });
    scope.setAttribute('data-pw-active-step', String(num));
  }

  function activeStep(scope) {
    return parseInt(scope.getAttribute('data-pw-active-step') || '1', 10);
  }

  function selectedDepartmentId(scope) {
    const select = scope.querySelector('[data-pw-department]');
    return select ? parseInt(select.value || '0', 10) : 0;
  }

  function fillStepSummary(scope, num, text) {
    const el = scope.querySelector(`[data-pw-step-summary="${num}"]`);
    if (el) el.textContent = text;
  }

  async function loadShiftsForDepartment(scope) {
    const departmentId = selectedDepartmentId(scope);
    const list = scope.querySelector('[data-pw-shift-list]');
    if (!list) return;
    if (!departmentId) {
      list.innerHTML = '';
      return;
    }
    list.innerHTML = `<p class="crud-modal-subtitle">${escapeHtml(L.loading || 'Loading…')}</p>`;
    try {
      const data = await postJSON(apiShiftsUrl(), { action: 'list', department_id: departmentId });
      const shifts = Array.isArray(data.shifts) ? data.shifts : [];
      if (!shifts.length) {
        list.innerHTML = `<div class="crud-empty-state">${escapeHtml(L.noShiftsDepartment || 'No shifts in this department.')}</div>`;
        return;
      }
      list.innerHTML = shifts.map((shift) => {
        const kind = String(shift.kind || 'work');
        return `<label class="settings-assignment-open-shift-chip">
          <input type="checkbox" data-pw-shift-id="${parseInt(shift.id, 10)}" data-pw-shift-kind="${kind}" checked>
          <span>${escapeHtml(shift.name || L.kindWork || 'Shift')} <small>${escapeHtml(kindLabel(kind))}</small></span>
        </label>`;
      }).join('');
    } catch (err) {
      list.innerHTML = `<div class="crud-empty-state">${escapeHtml(L.errorLoadingShifts || 'Error loading shifts.')}</div>`;
    }
  }

  function kindLabel(kind) {
    switch (kind) {
      case 'rest': return L.kindRest || 'Rest';
      case 'vacation': return L.kindVacation || 'Vacation';
      case 'sick': return L.kindSick || 'Sick leave';
      case 'overtime': return L.kindOvertime || 'Overtime';
      default: return L.kindWork || 'Work';
    }
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value == null ? '' : value);
    return div.innerHTML;
  }

  function selectedShiftIds(scope, kindFilter) {
    return Array.from(scope.querySelectorAll('[data-pw-shift-id]:checked'))
      .filter((el) => !kindFilter || el.getAttribute('data-pw-shift-kind') === kindFilter)
      .map((el) => parseInt(el.getAttribute('data-pw-shift-id'), 10))
      .filter((id) => id > 0);
  }

  async function checkCoverage(scope) {
    const departmentId = selectedDepartmentId(scope);
    const rangeStart = scope.querySelector('[data-pw-range-start]')?.value;
    const rangeEnd = scope.querySelector('[data-pw-range-end]')?.value;
    const shiftIds = selectedShiftIds(scope, null);
    const reportEl = scope.querySelector('[data-pw-coverage-report]');
    if (!departmentId || !rangeStart || !rangeEnd || !shiftIds.length) {
      feedbackError(L.selectDepartmentPeriodShift || 'Select department, period, and at least one shift.');
      return false;
    }
    if (reportEl) reportEl.innerHTML = `<p class="crud-modal-subtitle">${escapeHtml(L.checkingCoverage || 'Checking coverage…')}</p>`;
    try {
      const data = await postJSON(apiShiftsUrl(), {
        action: 'ensure_period_coverage',
        department_id: departmentId,
        range_start: rangeStart,
        range_end: rangeEnd,
        shift_ids: shiftIds,
      });
      const gaps = (data.dates || []).filter((d) => d.missing_shift_ids.length > 0);
      if (reportEl) {
        if (!gaps.length) {
          reportEl.innerHTML = `<p class="crud-modal-subtitle">${escapeHtml(L.coverageComplete || 'Full coverage: every date in the period already has occurrences for the chosen shifts.')}</p>`;
        } else {
          const items = gaps.slice(0, 20).map((g) => `<li>${escapeHtml(fmt(L.coverageGapItem || '{date}: missing {count} shifts', { date: g.date, count: g.missing_shift_ids.length }))}</li>`).join('');
          const summary = escapeHtml(fmt(L.coverageGapsSummary || '{gaps} of {total} dates without full coverage:', { gaps: gaps.length, total: data.summary.total_dates }));
          reportEl.innerHTML = `<p class="crud-modal-subtitle">${summary}</p><ul>${items}</ul>`;
        }
      }
      return true;
    } catch (err) {
      if (reportEl) reportEl.innerHTML = `<div class="crud-empty-state">${escapeHtml(L.errorCheckingCoverage || 'Error checking coverage.')}</div>`;
      return false;
    }
  }

  function loadEmployeesForDepartment(scope) {
    const departmentId = selectedDepartmentId(scope);
    const list = scope.querySelector('[data-pw-employee-list]');
    if (!list) return;
    Array.from(list.querySelectorAll('[data-pw-employee-row]')).forEach((row) => {
      const rowDept = parseInt(row.getAttribute('data-department-id') || '0', 10);
      row.hidden = rowDept !== departmentId;
    });
  }

  function weekdayChipsHtml(userId) {
    const days = L.weekdaysShort || ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return days.map((label, idx) => `<button type="button" class="shift-weekday-chip is-selected" data-pw-workday-chip data-weekday-value="${idx}" data-employee-id="${userId}">${escapeHtml(label)}</button>`).join('');
  }

  function employeeRestWeekdaysPayload(scope) {
    const result = {};
    Array.from(scope.querySelectorAll('[data-pw-employee-row]:not([hidden])')).forEach((row) => {
      const checkbox = row.querySelector('[data-pw-employee-include]');
      if (!checkbox || !checkbox.checked) return;
      const userId = parseInt(checkbox.getAttribute('data-employee-id'), 10);
      const restDays = Array.from(row.querySelectorAll('[data-pw-workday-chip]:not(.is-selected)'))
        .map((chip) => parseInt(chip.getAttribute('data-weekday-value'), 10));
      result[userId] = restDays;
    });
    return result;
  }

  function includedEmployeeIds(scope) {
    return Array.from(scope.querySelectorAll('[data-pw-employee-include]:checked'))
      .map((el) => parseInt(el.getAttribute('data-employee-id'), 10))
      .filter((id) => id > 0);
  }

  async function runAutoAssign(scope) {
    const departmentId = selectedDepartmentId(scope);
    const rangeStart = scope.querySelector('[data-pw-range-start]')?.value;
    const rangeEnd = scope.querySelector('[data-pw-range-end]')?.value;
    const workShiftIds = selectedShiftIds(scope, 'work');
    const employeeIds = includedEmployeeIds(scope);
    const resultEl = scope.querySelector('[data-pw-result]');

    if (!employeeIds.length) {
      feedbackError(L.includeEmployee || 'Include at least one employee in the plan.');
      return;
    }
    if (!workShiftIds.length) {
      feedbackError(L.selectWorkShift || 'Select at least one work shift to assign.');
      return;
    }

    if (resultEl) resultEl.innerHTML = `<p class="crud-modal-subtitle">${escapeHtml(L.assigningInProgress || 'Assigning…')}</p>`;
    try {
      const data = await postJSON(apiShiftsUrl(), {
        action: 'auto_assign_period',
        department_id: departmentId,
        range_start: rangeStart,
        range_end: rangeEnd,
        work_shift_ids: workShiftIds,
        employee_ids: employeeIds,
        employee_rest_weekdays: employeeRestWeekdaysPayload(scope),
      });
      renderResult(scope, data);
      feedbackSuccess(fmt(L.assignSummaryShort || '{assigned} shifts assigned, {conflicts} conflicts.', {
        assigned: data.assigned_count,
        conflicts: data.conflicts.length,
      }));
      setActiveStep(scope, 5);
    } catch (err) {
      const message = L.errorAutoAssign || 'Error during automatic assignment.';
      if (resultEl) resultEl.innerHTML = `<div class="crud-empty-state">${escapeHtml(message)}</div>`;
      feedbackError(err.message || message);
    }
  }

  function renderResult(scope, data) {
    const resultEl = scope.querySelector('[data-pw-result]');
    if (!resultEl) return;
    const summaryRows = (data.employee_summary || []).map((row) => {
      const name = scope.querySelector(`[data-pw-employee-include][data-employee-id="${row.user_id}"]`)?.getAttribute('data-employee-name') || `#${row.user_id}`;
      return `<tr><td>${escapeHtml(name)}</td><td>${row.hours_in_period}h</td></tr>`;
    }).join('');
    const conflictRows = (data.conflicts || []).map((c) => `<li>${escapeHtml(fmt(L.conflictItem || '{date} — shift #{id}: no employee available', { date: c.work_date, id: c.shift_id }))}</li>`).join('');
    const summaryText = fmt(L.assignSummary || '{assigned} shifts assigned, {rest} rest days recorded, {conflicts} conflicts.', {
      assigned: data.assigned_count,
      rest: data.rest_assigned_count,
      conflicts: (data.conflicts || []).length,
    });
    resultEl.innerHTML = `
      <p class="crud-modal-subtitle">${escapeHtml(summaryText)}</p>
      ${summaryRows ? `<div class="table-wrap"><table class="admin-table"><thead><tr><th>${escapeHtml(L.employeeCol || 'Employee')}</th><th>${escapeHtml(L.hoursInPeriodCol || 'Hours in period')}</th></tr></thead><tbody>${summaryRows}</tbody></table></div>` : ''}
      ${conflictRows ? `<p class="crud-modal-subtitle" style="margin-top:.6rem"><strong>${escapeHtml(L.conflictsTitle || 'Conflicts / uncovered shifts:')}</strong></p><ul>${conflictRows}</ul>` : ''}
    `;
  }

  function initWizard(scope) {
    if (!scope || scope.__pwInit) return;
    scope.__pwInit = true;
    setActiveStep(scope, 1);

    scope.addEventListener('change', (evt) => {
      if (evt.target.matches('[data-pw-department]')) {
        loadShiftsForDepartment(scope);
        loadEmployeesForDepartment(scope);
      }
    });

    scope.addEventListener('click', async (evt) => {
      const stepToggle = evt.target.closest('[data-pw-step-toggle]');
      if (stepToggle) {
        const stepEl = stepToggle.closest('[data-pw-step]');
        const n = stepNumber(stepEl);
        if (n < activeStep(scope)) setActiveStep(scope, n);
        return;
      }

      const next1 = evt.target.closest('[data-pw-next="2"]');
      if (next1) {
        const departmentId = selectedDepartmentId(scope);
        const rangeStart = scope.querySelector('[data-pw-range-start]')?.value;
        const rangeEnd = scope.querySelector('[data-pw-range-end]')?.value;
        if (!departmentId || !rangeStart || !rangeEnd) {
          feedbackError(L.selectDepartmentPeriod || 'Select department and period.');
          return;
        }
        fillStepSummary(scope, 1, `${rangeStart} → ${rangeEnd}`);
        await loadShiftsForDepartment(scope);
        loadEmployeesForDepartment(scope);
        setActiveStep(scope, 2);
        return;
      }

      const checkCoverageBtn = evt.target.closest('[data-pw-check-coverage]');
      if (checkCoverageBtn) {
        await checkCoverage(scope);
        return;
      }

      const next2 = evt.target.closest('[data-pw-next="3"]');
      if (next2) {
        const shiftIds = selectedShiftIds(scope, null);
        if (!shiftIds.length) {
          feedbackError(L.selectAtLeastOneShift || 'Select at least one shift.');
          return;
        }
        fillStepSummary(scope, 2, fmt(L.shiftsSelectedCount || '{n} shifts selected', { n: shiftIds.length }));
        setActiveStep(scope, 3);
        return;
      }

      const next3 = evt.target.closest('[data-pw-next="4"]');
      if (next3) {
        const employeeIds = includedEmployeeIds(scope);
        if (!employeeIds.length) {
          feedbackError(L.includeAtLeastOneEmployee || 'Include at least one employee.');
          return;
        }
        fillStepSummary(scope, 3, fmt(L.employeesIncludedCount || '{n} employees included', { n: employeeIds.length }));
        setActiveStep(scope, 4);
        return;
      }

      const back = evt.target.closest('[data-pw-back]');
      if (back) {
        setActiveStep(scope, parseInt(back.getAttribute('data-pw-back'), 10));
        return;
      }

      const workdayChip = evt.target.closest('[data-pw-workday-chip]');
      if (workdayChip) {
        workdayChip.classList.toggle('is-selected');
        return;
      }

      const runBtn = evt.target.closest('[data-pw-run]');
      if (runBtn) {
        await runAutoAssign(scope);
        return;
      }
    });

    // Employee include checkbox toggles the weekday chip row enabled look.
    scope.addEventListener('change', (evt) => {
      if (evt.target.matches('[data-pw-employee-include]')) {
        const row = evt.target.closest('[data-pw-employee-row]');
        if (row) row.classList.toggle('is-included', evt.target.checked);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const scope = root();
    initWizard(scope);
    if (scope) {
      const list = scope.querySelector('[data-pw-employee-list]');
      if (list) {
        Array.from(list.querySelectorAll('[data-pw-employee-row]')).forEach((row) => {
          const checkbox = row.querySelector('[data-pw-employee-include]');
          const userId = checkbox ? checkbox.getAttribute('data-employee-id') : null;
          const chipsHost = row.querySelector('[data-pw-workday-chips]');
          if (chipsHost && userId) chipsHost.innerHTML = weekdayChipsHtml(userId);
        });
      }
    }
  });

  const observer = new MutationObserver(() => {
    initWizard(root());
  });
  document.addEventListener('DOMContentLoaded', () => {
    const target = document.querySelector('[data-settings-panel="assignment-planner"]');
    if (target) observer.observe(target, { childList: true, subtree: true });
  });
})();
