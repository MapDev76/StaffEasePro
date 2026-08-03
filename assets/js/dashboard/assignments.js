(() => {
  const apiUrl = window.DashboardConfig?.apiDashboard;
  const locale = String(document.documentElement.getAttribute('lang') || 'en').toLowerCase();
  const isFr = locale.startsWith('fr');
  const isIt = locale.startsWith('it');
  const tr = (enText, frText, itText) => (isFr ? frText : (isIt && itText ? itText : enText));
  const feedback = window.DashboardFeedback;
  const iconsBase = String((window.DashboardConfig && window.DashboardConfig.iconsBase) || '/assets/icons/');
  const RULES_STORAGE_KEY = 'staffease:auto-assign-rules:v1';
  const WEEKDAY_FORMATTER = new Intl.DateTimeFormat(isFr ? 'fr-FR' : (isIt ? 'it-IT' : 'en-US'), { weekday: 'short' });
  const WEEKDAY_OPTIONS = [1, 2, 3, 4, 5, 6, 0].map((value) => {
    const baseDate = new Date(2024, 0, 1 + value, 12, 0, 0, 0);
    const label = WEEKDAY_FORMATTER.format(baseDate).replace('.', '');
    return { value, label: label.charAt(0).toUpperCase() + label.slice(1) };
  });
  const employeeModal = document.querySelector('[data-assignment-employee-modal]');
  const employeeModalTitle = employeeModal?.querySelector('[data-assignment-modal-title]') || null;
  const employeeModalSubtitle = employeeModal?.querySelector('[data-assignment-modal-subtitle]') || null;
  const employeeModalWeekdays = employeeModal?.querySelector('[data-assignment-modal-weekdays]') || null;
  const employeeModalSpecialDate = employeeModal?.querySelector('[data-assignment-modal-special-date]') || null;
  const employeeModalSpecialFrom = employeeModal?.querySelector('[data-assignment-modal-special-from]') || null;
  const employeeModalSpecialTo = employeeModal?.querySelector('[data-assignment-modal-special-to]') || null;
  const employeeModalSpecialReason = employeeModal?.querySelector('[data-assignment-modal-special-reason]') || null;
  const employeeModalSpecialList = employeeModal?.querySelector('[data-assignment-modal-special-list]') || null;
  const employeeModalMonthUnavailable = employeeModal?.querySelector('[data-assignment-modal-month-unavailable]') || null;
  const employeeModalRulesMonth = employeeModal?.querySelector('[data-assignment-modal-rules-month]') || null;
  const employeeModalShifts = employeeModal?.querySelector('[data-assignment-modal-shifts]') || null;
  const employeeModalShiftsSummary = employeeModal?.querySelector('[data-assignment-modal-shifts-summary]') || null;
  const employeeModalShiftsModal = employeeModal?.querySelector('[data-assignment-modal-shifts-modal]') || null;
  const employeeModalShiftsModalTitle = employeeModal?.querySelector('[data-assignment-modal-shifts-modal-title]') || null;
  const employeeModalShiftsOpenBtn = employeeModal?.querySelector('[data-assignment-modal-open-shifts]') || null;
  const employeeModalShiftsCloseBtn = employeeModal?.querySelector('[data-assignment-modal-shifts-close]') || null;
  const employeeModalShiftsSelectAllBtn = employeeModal?.querySelector('[data-assignment-modal-shifts-select-all]') || null;
  const employeeModalShiftsClearBtn = employeeModal?.querySelector('[data-assignment-modal-shifts-clear-selection]') || null;
  const employeeModalShiftsUnassignSelectedBtn = employeeModal?.querySelector('[data-assignment-modal-shifts-unassign-selected]') || null;
  const employeeModalShiftsUnassignAllBtn = employeeModal?.querySelector('[data-assignment-modal-shifts-unassign-all]') || null;
  const employeeModalMonthWeekdays = employeeModal?.querySelector('[data-assignment-modal-month-weekdays]') || null;
  const employeeModalRotateToggle = employeeModal?.querySelector('[data-assignment-modal-rotate-toggle]') || null;
  const employeeModalRotationPreview = employeeModal?.querySelector('[data-assignment-modal-rotation-preview]') || null;
  const employeeModalSaveMonthOverride = employeeModal?.querySelector('[data-assignment-modal-save-month-override]') || null;
  const employeeModalClearMonthOverride = employeeModal?.querySelector('[data-assignment-modal-clear-month-override]') || null;
  const employeeModalWeekly = employeeModal?.querySelector('[data-assignment-modal-weekly]') || null;
  const employeeModalPeriodMode = employeeModal?.querySelector('[data-assignment-modal-period-mode]') || null;
  const employeeModalPeriodMonth = employeeModal?.querySelector('[data-assignment-modal-period-month]') || null;
  const employeeModalOpenFrom = employeeModal?.querySelector('[data-assignment-modal-open-from]') || null;
  const employeeModalOpenTo = employeeModal?.querySelector('[data-assignment-modal-open-to]') || null;
  const employeeModalShiftMode = employeeModal?.querySelector('[data-assignment-modal-shift-mode]') || null;
  const employeeModalOpenShift = employeeModal?.querySelector('[data-assignment-modal-open-shift]') || null;
  const employeeModalOpenShiftList = employeeModal?.querySelector('[data-assignment-modal-open-shift-list]') || null;
  const employeeModalOpenList = employeeModal?.querySelector('[data-assignment-modal-open-list]') || null;
  const employeeModalOpenClear = employeeModal?.querySelector('[data-assignment-modal-open-clear]') || null;
  const employeeModalOpenReselect = employeeModal?.querySelector('[data-assignment-modal-open-reselect]') || null;
  const employeeModalOpenAssign = employeeModal?.querySelector('[data-assignment-modal-open-assign]') || null;
  const employeeModalOpenClearAssigned = employeeModal?.querySelector('[data-assignment-modal-open-clear-assigned]') || null;
  const employeeModalOpenReassign = employeeModal?.querySelector('[data-assignment-modal-open-reassign]') || null;
  const employeeModalAbsenceFrom = employeeModal?.querySelector('[data-assignment-modal-absence-from]') || null;
  const employeeModalAbsenceTo = employeeModal?.querySelector('[data-assignment-modal-absence-to]') || null;
  const employeeModalAbsenceType = employeeModal?.querySelector('[data-assignment-modal-absence-type]') || null;
  const globalAutoAssignShiftList = document.querySelector('[data-auto-assign-shift-list]');
  const globalAutoAssignRestStrategy = document.querySelector('[data-auto-assign-rest-strategy]');
  const globalAutoAssignPriorityList = document.querySelector('[data-auto-assign-priority-list]');
  const globalAutoAssignPriorityStrict = document.querySelector('[data-auto-assign-priority-strict]');
  const globalAutoAssignForecast = document.querySelector('[data-auto-assign-forecast]');
  const globalAutoAssignForecastSummary = document.querySelector('[data-auto-assign-forecast-summary]');
  const globalAutoAssignImpact = document.querySelector('[data-auto-assign-impact]');
  const globalAutoAssignForecastTips = document.querySelector('[data-auto-assign-forecast-tips]');
  const globalAutoAssignPreviewModal = document.querySelector('[data-auto-assign-preview-modal]');
  const globalAutoAssignPreviewGrid = document.querySelector('[data-auto-assign-preview-grid]');
  const assignmentPlannerPanel = document.querySelector('[data-assignment-planner-panel]');
  const assignmentPlannerScope = document.querySelector('[data-assignment-planner-scope]');
  const assignmentPlannerDepartment = document.querySelector('[data-assignment-planner-department]');
  const assignmentPlannerUser = document.querySelector('[data-assignment-planner-user]');
  const assignmentPlannerDepartmentWrap = document.querySelector('[data-assignment-planner-department-wrap]');
  const assignmentPlannerUserWrap = document.querySelector('[data-assignment-planner-user-wrap]');
  const assignmentPlannerRangeStart = document.querySelector('[data-assignment-planner-range-start]');
  const assignmentPlannerRangeEnd = document.querySelector('[data-assignment-planner-range-end]');
  const assignmentPlannerMinEmployees = document.querySelector('[data-assignment-planner-min-employees]');
  const assignmentPlannerMaxEmployees = document.querySelector('[data-assignment-planner-max-employees]');
  const assignmentPlannerMinRestDays = document.querySelector('[data-assignment-planner-min-rest-days]');
  const assignmentPlannerMaxRestDays = document.querySelector('[data-assignment-planner-max-rest-days]');
  const assignmentPlannerMinWorkDays = document.querySelector('[data-assignment-planner-min-work-days]');
  const assignmentPlannerMaxWorkDays = document.querySelector('[data-assignment-planner-max-work-days]');
  const assignmentPlannerRestMode = document.querySelector('[data-assignment-planner-rest-mode]');
  const assignmentPlannerShiftList = document.querySelector('[data-assignment-planner-shift-list]');
  const assignmentPlannerForecastSummary = document.querySelector('[data-assignment-planner-forecast-summary]');
  const assignmentPlannerForecastImpact = document.querySelector('[data-assignment-planner-forecast-impact]');
  const shiftCatalogNode = document.querySelector('[data-assignment-shift-catalog]');
  const shiftCatalog = (() => {
    if (!shiftCatalogNode) return [];
    try {
      const parsed = JSON.parse(shiftCatalogNode.textContent || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (_error) {
      return [];
    }
  })();
  let activeEmployeeUserId = 0;
  let activeEmployeeUserName = '';
  let activeEmployeeDepartmentId = 0;
  let activeEmployeeDepartmentName = '';
  let employeeModalSelectedShiftIds = new Set();
  let employeeModalShiftRows = [];
  let selectedOpenSlotKeys = new Set();
  let selectedOpenShiftFilterIds = new Set();
  let lastGlobalForecastResponse = null;
  let isRunningConfirmedAutoAssign = false;

  const AUTO_ASSIGN_POLICY_PRESETS = {
    balanced: {
      minEmployees: 1,
      maxEmployees: 3,
      minRestDays: 1,
      maxRestDays: 2,
      minWorkDays: 4,
      maxWorkDays: 6,
      restStrategy: 'staggered',
    },
    coverage: {
      minEmployees: 1,
      maxEmployees: 4,
      minRestDays: 0,
      maxRestDays: 1,
      minWorkDays: 5,
      maxWorkDays: 7,
      restStrategy: 'fixed',
    },
    wellbeing: {
      minEmployees: 1,
      maxEmployees: 3,
      minRestDays: 2,
      maxRestDays: 3,
      minWorkDays: 3,
      maxWorkDays: 5,
      restStrategy: 'staggered',
    },
  };

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function isIconAsset(icon) {
    return /\.(svg|png|jpe?g|gif|webp|ico)$/i.test(String(icon || ''));
  }

  function renderShiftIcon(icon) {
    const value = String(icon || '').trim();
    if (!value) return '';
    if (isIconAsset(value)) {
      return `<img src="${iconsBase}${encodeURIComponent(value)}" aria-hidden="true" class="settings-icon-inline-image">`;
    }
    return escapeHtml(value);
  }

  function notifyError(message) {
    if (feedback) {
      feedback.error(tr('Oops!', 'Erreur', 'Errore'), message);
      return;
    }
    console.error(message);
  }

  function notifySuccess(message) {
    if (feedback) {
      feedback.success(tr('Done', 'Termine', 'Fatto'), message);
      return;
    }
  }

  function isAssignmentsPanelActive() {
    const panel = document.querySelector('.settings-panel[data-settings-panel="assignments"]');
    return !!panel && !panel.hidden;
  }

  function isAssignmentPlannerPanelActive() {
    const panel = document.querySelector('.settings-panel[data-settings-panel="assignment-planner"]');
    return !!panel && !panel.hidden;
  }

  function syncAssignmentPlannerScopeUi() {
    if (!assignmentPlannerScope) return;
    const scope = String(assignmentPlannerScope.value || 'department').toLowerCase();
    if (assignmentPlannerDepartmentWrap) {
      assignmentPlannerDepartmentWrap.hidden = scope === 'employee';
    }
    if (assignmentPlannerUserWrap) {
      assignmentPlannerUserWrap.hidden = scope !== 'employee';
    }
  }

  function getAssignmentPlannerSelectedShiftIds() {
    return Array.from(assignmentPlannerShiftList?.querySelectorAll('[data-assignment-planner-shift-id]:checked') || [])
      .map((input) => parseInt(String(input.getAttribute('data-assignment-planner-shift-id') || '0'), 10) || 0)
      .filter((value) => value > 0);
  }

  function buildAssignmentPlannerPayload(actionName) {
    const scope = String(assignmentPlannerScope?.value || 'department').toLowerCase();
    const range = normalizeCurrentMonthRange(
      assignmentPlannerRangeStart?.value || '',
      assignmentPlannerRangeEnd?.value || ''
    );
    const selectedShiftIds = getAssignmentPlannerSelectedShiftIds();

    const minEmployees = parseInt(String(assignmentPlannerMinEmployees?.value || '1'), 10) || 1;
    const maxEmployees = parseInt(String(assignmentPlannerMaxEmployees?.value || '3'), 10) || 3;
    const minRestDays = parseInt(String(assignmentPlannerMinRestDays?.value || '1'), 10) || 1;
    const maxRestDays = parseInt(String(assignmentPlannerMaxRestDays?.value || '2'), 10) || 2;
    const minWorkDays = parseInt(String(assignmentPlannerMinWorkDays?.value || '4'), 10) || 4;
    const maxWorkDays = parseInt(String(assignmentPlannerMaxWorkDays?.value || '6'), 10) || 6;
    const boundedMinEmployees = Math.max(0, Math.min(minEmployees, maxEmployees));
    const boundedMaxEmployees = Math.max(1, Math.max(minEmployees, maxEmployees));
    const boundedMinRestDays = Math.min(6, Math.max(0, Math.min(minRestDays, maxRestDays)));
    const boundedMaxRestDays = Math.min(6, Math.max(0, Math.max(minRestDays, maxRestDays)));
    const boundedMinWorkDays = Math.min(7, Math.max(1, Math.min(minWorkDays, maxWorkDays)));
    const boundedMaxWorkDays = Math.min(7, Math.max(1, Math.max(minWorkDays, maxWorkDays)));
    const restDistributionModeRaw = String(assignmentPlannerRestMode?.value || 'fixed').toLowerCase();
    const restDistributionMode = ['fixed', 'staggered', 'random'].includes(restDistributionModeRaw)
      ? restDistributionModeRaw
      : 'fixed';

    const payload = {
      action: actionName,
      range_mode: 'custom',
      range_start: range.start,
      range_end: range.end,
      allowed_shift_ids: selectedShiftIds,
      min_employees_per_shift_day: boundedMinEmployees,
      max_employees_per_shift_day: boundedMaxEmployees,
      min_rest_days_per_week: boundedMinRestDays,
      max_rest_days_per_week: boundedMaxRestDays,
      min_work_days_per_week: boundedMinWorkDays,
      max_work_days_per_week: boundedMaxWorkDays,
      rest_distribution_mode: restDistributionMode,
      allow_reassign_conflicts: true,
      priority_department_strict_internal: scope === 'department',
    };

    if (!selectedShiftIds.length) {
      throw new Error(tr('Select at least one shift template.', 'Selectionnez au moins un modele de poste.', 'Seleziona almeno un modello di turno.'));
    }

    if (scope === 'employee') {
      const targetUserId = parseInt(String(assignmentPlannerUser?.value || '0'), 10) || 0;
      if (targetUserId <= 0) {
        throw new Error(tr('Select one employee.', 'Selectionnez un employe.', 'Seleziona un dipendente.'));
      }
      payload.target_user_id = targetUserId;
    } else if (scope === 'department') {
      const departmentId = parseInt(String(assignmentPlannerDepartment?.value || '0'), 10) || 0;
      if (departmentId > 0) {
        payload.priority_department_id = departmentId;
      }
    }

    return { scope, payload };
  }

  function renderAssignmentPlannerForecast(response) {
    if (!assignmentPlannerForecastSummary || !assignmentPlannerForecastImpact) {
      return;
    }

    const isOk = !!(response && (response.success || response.ok));
    const forecast = response && typeof response.forecast === 'object' ? response.forecast : null;
    if (!isOk || !forecast) {
      assignmentPlannerForecastSummary.textContent = tr('Forecast unavailable.', 'Prevision indisponible.', 'Previsione non disponibile.');
      assignmentPlannerForecastImpact.innerHTML = '';
      return;
    }

    const slotsOpen = Number(forecast.slots_open || 0);
    const predictedRemainingAtMin = Number(forecast.predicted_remaining_at_min || 0);
    const uncoveredDays = Number(forecast.uncovered_days_open || 0);
    const coveredAtMin = Number(forecast.covered_at_min_groups || 0);

    assignmentPlannerForecastSummary.textContent = String(response.summary || tr('Coverage forecast ready.', 'Prevision de couverture prete.', 'Previsione di copertura pronta.'));
    assignmentPlannerForecastImpact.innerHTML = [
      `<span class="settings-auto-assign-impact-chip ${slotsOpen > 0 ? 'is-warning' : 'is-positive'}">${escapeHtml(tr('Open slots now', 'Postes ouverts', 'Turni aperti ora'))}: ${slotsOpen}</span>`,
      `<span class="settings-auto-assign-impact-chip ${predictedRemainingAtMin > 0 ? 'is-warning' : 'is-positive'}">${escapeHtml(tr('Projected open after run', 'Ouverts projetes apres execution', 'Aperti previsti dopo l\'esecuzione'))}: ${predictedRemainingAtMin}</span>`,
      `<span class="settings-auto-assign-impact-chip ${uncoveredDays > 0 ? 'is-warning' : 'is-positive'}">${escapeHtml(tr('Uncovered days', 'Jours non couverts', 'Giorni non coperti'))}: ${uncoveredDays}</span>`,
      `<span class="settings-auto-assign-impact-chip is-positive">${escapeHtml(tr('Shift-days already covered', 'Jours-poste deja couverts', 'Giorni-turno già coperti'))}: ${coveredAtMin}</span>`
    ].join('');
  }

  async function runAssignmentPlannerForecast() {
    if (!apiUrl || !window.AppAPI) return;
    const { payload } = buildAssignmentPlannerPayload('auto_assign_forecast');
    if (assignmentPlannerForecastSummary) {
      assignmentPlannerForecastSummary.textContent = tr('Calculating forecast...', 'Calcul de la prevision...', 'Calcolo della previsione...');
    }
    const response = await AppAPI.postJSON(apiUrl, payload);
    if (!(response && (response.success || response.ok))) {
      throw new Error(response?.error || tr('Forecast failed.', 'La prevision a echoue.', 'Previsione non riuscita.'));
    }
    renderAssignmentPlannerForecast(response);
    return response;
  }

  async function runAssignmentPlannerAutoAssign() {
    if (!apiUrl || !window.AppAPI) return;
    const { payload } = buildAssignmentPlannerPayload('auto_assign_open');
    const response = await AppAPI.postJSON(apiUrl, payload);
    if (!(response && (response.success || response.ok))) {
      throw new Error(response?.error || tr('Auto-assignment failed.', 'L\'affectation automatique a echoue.', 'Assegnazione automatica non riuscita.'));
    }

    const assignedCount = Number(response.assigned_count || 0);
    const note = response?.note ? ` ${String(response.note)}` : '';
    notifySuccess(`${tr('Automatic assignment completed.', 'Affectation automatique terminee.', 'Assegnazione automatica completata.')} ${assignedCount} ${tr('slots assigned.', 'affectations creees.', 'assegnazioni create.')}${note}`);

    if (feedback && typeof feedback.reloadSettingsTabWithSuccess === 'function') {
      feedback.reloadSettingsTabWithSuccess(
        'assignment-planner',
        tr('Done', 'Termine', 'Fatto'),
        tr('Automatic assignment completed.', 'Affectation automatique terminee.', 'Assegnazione automatica completata.')
      );
      return;
    }
    location.reload();
  }

  function getPlannerRuntimeSnapshot() {
    const runtime = window.DashboardPlannerRuntime;
    if (!runtime || typeof runtime.getEvents !== 'function') {
      return { events: [], departments: [] };
    }
    const events = Array.isArray(runtime.getEvents()) ? runtime.getEvents() : [];
    const departments = typeof runtime.getDepartments === 'function' && Array.isArray(runtime.getDepartments())
      ? runtime.getDepartments()
      : [];
    return { events, departments };
  }

  function refreshAssignmentEmployeeIndexStats() {
    const indexNode = document.querySelector('[data-assignment-employee-index]');
    if (!indexNode) return;

    const employeeButtons = Array.from(indexNode.querySelectorAll('[data-assignment-employee-open]'));
    if (!employeeButtons.length) return;

    const monthPrefix = `${currentMonthKey()}-`;
    const { events } = getPlannerRuntimeSnapshot();
    const statsByUser = new Map();

    employeeButtons.forEach((button) => {
      const userId = parseInt(String(button.getAttribute('data-user-id') || '0'), 10) || 0;
      const primaryDepartmentId = parseInt(String(button.getAttribute('data-user-department-id') || '0'), 10) || 0;
      if (!userId) return;
      statsByUser.set(userId, {
        primaryDepartmentId,
        crossDepartmentAssigned: 0,
        restDone: 0,
        workedDays: new Set(),
      });
    });

    events.forEach((event) => {
      const userId = parseInt(String(event?.user_id || '0'), 10) || 0;
      if (!userId || !statsByUser.has(userId)) return;
      const status = String(event?.status || '').toLowerCase();
      if (status === 'cancelled' || status === 'open') return;

      const workDate = normalizeDateKey(String(event?.work_date || ''));
      if (!workDate || !workDate.startsWith(monthPrefix)) return;

      const shiftKind = String(event?.shift_kind || 'work').toLowerCase();
      const departmentId = parseInt(String(event?.department_id || '0'), 10) || 0;
      const stat = statsByUser.get(userId);
      if (!stat) return;

      if (shiftKind === 'rest') {
        stat.restDone += 1;
        return;
      }

      if (shiftKind === 'work') {
        stat.workedDays.add(workDate);
        if (departmentId > 0 && stat.primaryDepartmentId > 0 && departmentId !== stat.primaryDepartmentId) {
          stat.crossDepartmentAssigned += 1;
        }
      }
    });

    employeeButtons.forEach((button) => {
      const userId = parseInt(String(button.getAttribute('data-user-id') || '0'), 10) || 0;
      const stats = statsByUser.get(userId);
      if (!stats) return;
      const smallNode = button.querySelector('small');
      if (!smallNode) return;
      const workedDaysCount = stats.workedDays.size;
      const crossClass = stats.crossDepartmentAssigned > 0 ? 'is-positive' : 'is-neutral';
      const restClass = stats.restDone <= 0 ? 'is-negative' : stats.restDone <= 2 ? 'is-warning' : 'is-positive';
      const workedClass = workedDaysCount < 12 ? 'is-warning' : workedDaysCount > 24 ? 'is-negative' : 'is-positive';
      smallNode.classList.add('settings-assignment-employee-stats');
      smallNode.innerHTML = [
        `<span class="settings-assignment-employee-stat ${crossClass}">${escapeHtml(tr('Other dept', 'Autre departement', 'Altro dipartimento'))}: ${stats.crossDepartmentAssigned}</span>`,
        `<span class="settings-assignment-employee-stat ${restClass}">${escapeHtml(tr('Rest done', 'Repos faits', 'Riposi effettuati'))}: ${stats.restDone}</span>`,
        `<span class="settings-assignment-employee-stat ${workedClass}">${escapeHtml(tr('Worked days', 'Jours travailles', 'Giorni lavorati'))}: ${workedDaysCount}</span>`
      ].join(' ');
    });
  }

  function getAssignmentsListWrap() {
    return document.querySelector('[data-assignment-list-wrap]');
  }

  function getAssignmentsListToggle() {
    return document.querySelector('[data-assignment-list-toggle]');
  }

  function setAssignmentsListVisible(visible) {
    const wrap = getAssignmentsListWrap();
    const toggle = getAssignmentsListToggle();
    if (!wrap || !toggle) return;
    wrap.hidden = !visible;
    toggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
    toggle.textContent = visible
      ? tr('Hide daily assignments list', 'Masquer la liste quotidienne des affectations', 'Nascondi lista quotidiana assegnazioni')
      : tr('Show daily assignments list', 'Afficher la liste quotidienne des affectations', 'Mostra lista quotidiana assegnazioni');
  }

  function todayKey() {
    return new Date().toISOString().slice(0, 10);
  }

  function currentMonthStartKey() {
    const today = todayKey();
    return `${today.slice(0, 7)}-01`;
  }

  function currentMonthKey() {
    return todayKey().slice(0, 7);
  }

  function currentMonthEndKey() {
    const today = new Date();
    const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    return dateKeyFromDate(end);
  }

  function normalizeMonthKey(value) {
    const normalized = String(value || '').trim();
    return /^\d{4}-\d{2}$/.test(normalized) ? normalized : '';
  }

  function monthStartKey(monthKey) {
    const normalized = normalizeMonthKey(monthKey) || currentMonthKey();
    return `${normalized}-01`;
  }

  function monthEndKey(monthKey) {
    const normalized = normalizeMonthKey(monthKey) || currentMonthKey();
    const [yearRaw, monthRaw] = normalized.split('-');
    const year = parseInt(yearRaw, 10);
    const monthIndex = parseInt(monthRaw, 10);
    if (!Number.isFinite(year) || !Number.isFinite(monthIndex) || monthIndex < 1 || monthIndex > 12) {
      return currentMonthEndKey();
    }
    const end = new Date(year, monthIndex, 0);
    return dateKeyFromDate(end);
  }

  function addMonths(monthKey, delta) {
    const normalized = normalizeMonthKey(monthKey) || currentMonthKey();
    const [yearRaw, monthRaw] = normalized.split('-');
    const baseDate = new Date(parseInt(yearRaw, 10), parseInt(monthRaw, 10) - 1, 1);
    baseDate.setMonth(baseDate.getMonth() + delta);
    return `${baseDate.getFullYear()}-${String(baseDate.getMonth() + 1).padStart(2, '0')}`;
  }

  function isPastDateKey(dateKey) {
    return !!dateKey && dateKey < todayKey();
  }

  function normalizeCurrentMonthRange(start, end) {
    const minDate = currentMonthStartKey();
    const safeStart = start && start >= minDate ? start : minDate;
    const safeEnd = end && end >= safeStart ? end : safeStart;
    return { start: safeStart, end: safeEnd };
  }

  function normalizeDateRange(fromDate, toDate) {
    const from = normalizeDateKey(fromDate);
    const to = normalizeDateKey(toDate);
    if (!from || !to) return null;
    return from <= to ? { start: from, end: to } : { start: to, end: from };
  }

  function getActiveRulesMonthKey() {
    const selected = normalizeMonthKey(employeeModalRulesMonth?.value || '');
    return selected || currentMonthKey();
  }

  function resolveEmployeePeriodRange() {
    const mode = String(employeeModalPeriodMode?.value || 'current');
    const currentMonth = currentMonthKey();
    const selectedMonth = normalizeMonthKey(employeeModalPeriodMonth?.value || '') || currentMonth;

    if (mode === 'month') {
      return {
        mode,
        month: selectedMonth,
        start: monthStartKey(selectedMonth),
        end: monthEndKey(selectedMonth),
      };
    }

    if (mode === 'future') {
      const start = monthStartKey(currentMonth);
      const manualEnd = normalizeDateKey(employeeModalOpenTo?.value || '');
      const defaultEnd = monthEndKey(addMonths(currentMonth, 12));
      const end = manualEnd && manualEnd >= start ? manualEnd : defaultEnd;
      return {
        mode,
        month: selectedMonth,
        start,
        end,
      };
    }

    return {
      mode: 'current',
      month: currentMonth,
      start: monthStartKey(currentMonth),
      end: monthEndKey(currentMonth),
    };
  }

  function syncEmployeePeriodInputs() {
    const period = resolveEmployeePeriodRange();
    if (employeeModalPeriodMonth) {
      employeeModalPeriodMonth.value = period.month;
      employeeModalPeriodMonth.disabled = period.mode !== 'month';
    }
    if (employeeModalOpenFrom) {
      employeeModalOpenFrom.value = period.start;
    }
    if (employeeModalOpenTo) {
      employeeModalOpenTo.value = period.end;
      employeeModalOpenTo.disabled = period.mode === 'current' || period.mode === 'month';
    }
    if (employeeModalOpenFrom) {
      employeeModalOpenFrom.disabled = true;
    }
  }

  function getAssignableWorkShiftsForEmployee() {
    return shiftCatalog
      .filter((item) => String(item?.kind || '').toLowerCase() === 'work')
      .sort((a, b) => {
        const aDeptId = Number(a?.department_id || 0);
        const bDeptId = Number(b?.department_id || 0);
        const aOwnDept = aDeptId === Number(activeEmployeeDepartmentId || 0) ? 1 : 0;
        const bOwnDept = bDeptId === Number(activeEmployeeDepartmentId || 0) ? 1 : 0;
        if (aOwnDept !== bOwnDept) {
          return bOwnDept - aOwnDept;
        }
        const aDeptName = String(a?.department_name || '');
        const bDeptName = String(b?.department_name || '');
        const deptCompare = aDeptName.localeCompare(bDeptName);
        if (deptCompare !== 0) {
          return deptCompare;
        }
        return String(a?.name || '').localeCompare(String(b?.name || ''));
      });
  }

  function getOpenShiftSelection() {
    const allShiftIds = getAssignableWorkShiftsForEmployee()
      .map((item) => parseInt(String(item?.id || '0'), 10) || 0)
      .filter((value) => value > 0);
    const selectedIds = Array.from(selectedOpenShiftFilterIds)
      .map((value) => parseInt(String(value || '0'), 10) || 0)
      .filter((value) => value > 0);
    const allowedShiftIds = selectedIds.length ? selectedIds : allShiftIds;
    return { mode: 'some', scopeShiftId: 0, allowedShiftIds };
  }

  function getGlobalShiftSelection() {
    const selectedShiftIds = Array.from(globalAutoAssignShiftList?.querySelectorAll('[data-auto-assign-shift-id]:checked') || [])
      .map((input) => parseInt(String(input.getAttribute('data-auto-assign-shift-id') || '0'), 10) || 0)
      .filter((value) => value > 0);

    return {
      mode: 'selected',
      scopeShiftId: 0,
      allowedShiftIds: selectedShiftIds,
    };
  }

  function getGlobalPriorityDepartmentSelection() {
    const selectedButton = globalAutoAssignPriorityList?.querySelector('[data-auto-assign-priority-department-id][aria-pressed="true"]') || null;
    const id = parseInt(String(selectedButton?.getAttribute('data-auto-assign-priority-department-id') || '0'), 10) || 0;
    const label = String(selectedButton?.textContent || '').trim();
    return { id, label };
  }

  function getGlobalAutoAssignParams() {
    const range = normalizeCurrentMonthRange(
      document.querySelector('[data-auto-assign-range-start]')?.value || '',
      document.querySelector('[data-auto-assign-range-end]')?.value || ''
    );
    const minEmployeesRaw = parseInt(document.querySelector('[data-auto-assign-min-employees]')?.value || '1', 10);
    const maxEmployeesRaw = parseInt(document.querySelector('[data-auto-assign-max-employees]')?.value || '3', 10);
    const minRestDaysRaw = parseInt(document.querySelector('[data-auto-assign-min-rest-days]')?.value || '1', 10);
    const maxRestDaysRaw = parseInt(document.querySelector('[data-auto-assign-max-rest-days]')?.value || '2', 10);
    const minWorkDaysRaw = parseInt(document.querySelector('[data-auto-assign-min-work-days]')?.value || '4', 10);
    const maxWorkDaysRaw = parseInt(document.querySelector('[data-auto-assign-max-work-days]')?.value || '6', 10);
    const minEmployeesPerShiftDay = Number.isFinite(minEmployeesRaw) ? Math.max(0, minEmployeesRaw) : 1;
    const maxEmployeesPerShiftDay = Number.isFinite(maxEmployeesRaw) ? Math.max(1, maxEmployeesRaw) : 3;
    const normalizedMin = Math.min(minEmployeesPerShiftDay, maxEmployeesPerShiftDay);
    const normalizedMax = Math.max(minEmployeesPerShiftDay, maxEmployeesPerShiftDay);
    const normalizedMinRestDays = Number.isFinite(minRestDaysRaw) ? Math.min(6, Math.max(0, minRestDaysRaw)) : 1;
    const normalizedMaxRestDays = Number.isFinite(maxRestDaysRaw) ? Math.min(6, Math.max(0, maxRestDaysRaw)) : 2;
    const boundedMinRestDays = Math.min(normalizedMinRestDays, normalizedMaxRestDays);
    const boundedMaxRestDays = Math.max(normalizedMinRestDays, normalizedMaxRestDays);
    const normalizedMinWorkDays = Number.isFinite(minWorkDaysRaw) ? Math.min(7, Math.max(1, minWorkDaysRaw)) : 4;
    const normalizedMaxWorkDays = Number.isFinite(maxWorkDaysRaw) ? Math.min(7, Math.max(1, maxWorkDaysRaw)) : 6;
    const boundedMinWorkDays = Math.min(normalizedMinWorkDays, normalizedMaxWorkDays);
    const boundedMaxWorkDays = Math.max(normalizedMinWorkDays, normalizedMaxWorkDays);
    const globalShiftSelection = getGlobalShiftSelection();
    const priorityDepartment = getGlobalPriorityDepartmentSelection();
    const restStrategyRaw = String(globalAutoAssignRestStrategy?.value || 'fixed').toLowerCase();
    const restDistributionMode = ['fixed', 'staggered', 'random'].includes(restStrategyRaw) ? restStrategyRaw : 'fixed';
    return {
      range,
      normalizedMin,
      normalizedMax,
      boundedMinRestDays,
      boundedMaxRestDays,
      boundedMinWorkDays,
      boundedMaxWorkDays,
      globalShiftSelection,
      priorityDepartment,
      priorityDepartmentStrictInternal: !!(globalAutoAssignPriorityStrict && globalAutoAssignPriorityStrict.checked),
      restDistributionMode,
    };
  }

  function evaluateCurrentOpenCoverage(rangeStart, rangeEnd, allowedShiftIds) {
    const { events } = getPlannerRuntimeSnapshot();
    const range = normalizeDateRange(rangeStart, rangeEnd);
    if (!range) {
      return { currentOpenSlots: 0, currentUncoveredDays: 0 };
    }

    const allowed = new Set((Array.isArray(allowedShiftIds) ? allowedShiftIds : [])
      .map((id) => parseInt(String(id || '0'), 10) || 0)
      .filter((id) => id > 0));
    const groups = new Map();
    const uncoveredDays = new Set();

    events.forEach((event) => {
      const workDate = normalizeDateKey(String(event?.work_date || ''));
      if (!workDate || workDate < range.start || workDate > range.end) return;
      const shiftKind = String(event?.shift_kind || 'work').toLowerCase();
      if (shiftKind !== 'work') return;
      const shiftId = parseInt(String(event?.shift_id || '0'), 10) || 0;
      if (allowed.size > 0 && (!shiftId || !allowed.has(shiftId))) return;
      const departmentId = parseInt(String(event?.department_id || '0'), 10) || 0;
      const userId = parseInt(String(event?.user_id || '0'), 10) || 0;
      const status = String(event?.status || '').toLowerCase();
      const assignmentSource = String(event?.assignment_source || '').toLowerCase();
      const groupKey = `${workDate}|${departmentId}|${shiftId}`;
      if (!groups.has(groupKey)) {
        groups.set(groupKey, {
          workDate,
          hasAssignedEmployee: false,
          hasOpenSlot: false,
        });
      }
      const group = groups.get(groupKey);
      if (!group) return;
      if (userId > 0) {
        group.hasAssignedEmployee = true;
      }
      if (status === 'open' || assignmentSource === 'open' || !!event?.is_virtual_open) {
        group.hasOpenSlot = true;
      }
    });

    let currentOpenSlots = 0;
    groups.forEach((group) => {
      if (group.hasAssignedEmployee) return;
      if (!group.hasOpenSlot) return;
      currentOpenSlots += 1;
      uncoveredDays.add(group.workDate);
    });

    return {
      currentOpenSlots,
      currentUncoveredDays: uncoveredDays.size,
    };
  }

  function estimateProjectedUncoveredDays(currentUncoveredDays, predictedRemainingOpenSlots) {
    const normalizedCurrent = Math.max(0, Number(currentUncoveredDays || 0));
    const normalizedRemaining = Math.max(0, Number(predictedRemainingOpenSlots || 0));
    if (normalizedRemaining <= 0) return 0;
    if (normalizedCurrent <= 0) return 0;
    return Math.min(normalizedCurrent, normalizedRemaining);
  }

  function renderForecastImpact(data) {
    if (!globalAutoAssignImpact) return;
    const raw = data?.forecast && typeof data.forecast === 'object' ? data.forecast : null;
    if (!raw) {
      globalAutoAssignImpact.innerHTML = '';
      return;
    }
    const predictedRemainingAtMin = Number(raw.predicted_remaining_at_min || 0);
    const currentOpenSlots = Number(raw.slots_open || 0);
    const projectedCovered = Math.max(0, currentOpenSlots - predictedRemainingAtMin);
    const currentUncoveredDays = Number(raw.uncovered_days_open || 0);
    const predictedUncoveredDays = estimateProjectedUncoveredDays(currentUncoveredDays, predictedRemainingAtMin);
    const uncoveredDaysDelta = currentUncoveredDays - predictedUncoveredDays;
    const params = getGlobalAutoAssignParams();

    const chips = [
      {
        label: `${tr('Open now', 'Ouverts maintenant', 'Aperti ora')}: ${currentOpenSlots}`,
        className: currentOpenSlots > 0 ? 'is-warning' : 'is-positive',
      },
      {
        label: `${tr('Projected covered', 'Couverture projetee', 'Copertura prevista')}: +${projectedCovered}`,
        className: projectedCovered > 0 ? 'is-positive' : 'is-negative',
      },
      {
        className: params.priorityDepartment.id > 0 ? 'is-warning' : 'is-positive',
        label: params.priorityDepartment.id > 0
          ? `${tr('Priority dept', 'Departement prioritaire', 'Dipartimento prioritario')}: ${params.priorityDepartment.label || '#' + params.priorityDepartment.id}`
          : tr('Priority dept: none', 'Departement prioritaire: aucun', 'Dipartimento prioritario: nessuno'),
      },
      {
        className: params.priorityDepartmentStrictInternal ? 'is-warning' : 'is-positive',
        label: params.priorityDepartmentStrictInternal
          ? tr('Priority dept protected from external staff', 'Departement prioritaire protege des employes externes', 'Dipartimento prioritario protetto dal personale esterno')
          : tr('Priority dept can use external staff', 'Departement prioritaire peut utiliser des employes externes', 'Il dipartimento prioritario può usare personale esterno'),
      },
      {
        label: `${tr('Projected open after run', 'Ouverts projetes apres execution', 'Aperti previsti dopo l\'esecuzione')}: ${predictedRemainingAtMin}`,
        className: predictedRemainingAtMin > 0 ? 'is-warning' : 'is-positive',
      },
      {
        label: `${tr('Uncovered days delta', 'Delta jours non couverts', 'Delta giorni non coperti')}: ${uncoveredDaysDelta >= 0 ? '+' : ''}${uncoveredDaysDelta}`,
        className: uncoveredDaysDelta > 0 ? 'is-positive' : uncoveredDaysDelta < 0 ? 'is-negative' : 'is-warning',
      },
      {
        label: `${tr('Policy window', 'Fenetre policy', 'Finestra policy')}: ${params.boundedMinWorkDays}-${params.boundedMaxWorkDays} ${tr('work', 'travail', 'lavoro')} / ${params.boundedMinRestDays}-${params.boundedMaxRestDays} ${tr('rest', 'repos', 'riposo')}`,
        className: 'is-warning',
      },
    ];

    globalAutoAssignImpact.innerHTML = chips
      .map((chip) => `<span class="settings-auto-assign-impact-chip ${chip.className}">${escapeHtml(String(chip.label || ''))}</span>`)
      .join('');
  }

  function closeAutoAssignPreviewModal() {
    if (globalAutoAssignPreviewModal) {
      globalAutoAssignPreviewModal.hidden = true;
    }
  }

  function renderAutoAssignPreviewModal(forecastResponse) {
    if (!globalAutoAssignPreviewModal || !globalAutoAssignPreviewGrid) return false;
    const raw = forecastResponse?.forecast && typeof forecastResponse.forecast === 'object' ? forecastResponse.forecast : null;
    if (!raw) return false;
    const currentOpenSlots = Number(raw.slots_open || 0);
    const projectedOpenSlots = Number(raw.predicted_remaining_at_min || 0);
    const currentUncoveredDays = Number(raw.uncovered_days_open || 0);
    const projectedUncoveredDays = estimateProjectedUncoveredDays(currentUncoveredDays, projectedOpenSlots);
    const currentNeedToMinimum = Number(raw.required_to_minimum || 0);
    const projectedCovered = Math.max(0, currentOpenSlots - projectedOpenSlots);
    const rows = [
      {
        label: tr('Open slots', 'Postes ouverts', 'Turni aperti'),
        before: currentOpenSlots,
        after: projectedOpenSlots,
        delta: projectedOpenSlots - currentOpenSlots,
        positiveWhenLower: true,
      },
      {
        label: tr('Uncovered days', 'Jours non couverts', 'Giorni non coperti'),
        before: currentUncoveredDays,
        after: projectedUncoveredDays,
        delta: projectedUncoveredDays - currentUncoveredDays,
        positiveWhenLower: true,
      },
      {
        label: tr('Assignments expected', 'Affectations attendues', 'Assegnazioni previste'),
        before: 0,
        after: projectedCovered,
        delta: projectedCovered,
        positiveWhenLower: false,
      },
      {
        label: tr('Need to reach minimum', 'Besoin min', 'Fabbisogno minimo'),
        before: currentNeedToMinimum,
        after: projectedOpenSlots,
        delta: projectedOpenSlots - currentNeedToMinimum,
        positiveWhenLower: true,
      },
    ];

    globalAutoAssignPreviewGrid.innerHTML = rows.map((row) => {
      const deltaValue = Number(row.delta || 0);
      const className = deltaValue === 0
        ? 'is-warning'
        : row.positiveWhenLower
          ? (deltaValue < 0 ? 'is-positive' : 'is-negative')
          : (deltaValue > 0 ? 'is-positive' : 'is-negative');
      const deltaLabel = `${deltaValue > 0 ? '+' : ''}${deltaValue}`;
      return `
        <div class="settings-auto-assign-preview-row">
          <span class="settings-auto-assign-preview-row-label">${escapeHtml(row.label)}</span>
          <span class="settings-auto-assign-preview-row-values">${escapeHtml(String(row.before))} -> ${escapeHtml(String(row.after))}</span>
          <span class="settings-auto-assign-preview-row-delta ${className}">${escapeHtml(deltaLabel)}</span>
        </div>
      `;
    }).join('');
    globalAutoAssignPreviewModal.hidden = false;
    return true;
  }

  function resolveMatchingPolicyPreset() {
    const minEmployees = parseInt(document.querySelector('[data-auto-assign-min-employees]')?.value || '0', 10) || 0;
    const maxEmployees = parseInt(document.querySelector('[data-auto-assign-max-employees]')?.value || '0', 10) || 0;
    const minRestDays = parseInt(document.querySelector('[data-auto-assign-min-rest-days]')?.value || '0', 10) || 0;
    const maxRestDays = parseInt(document.querySelector('[data-auto-assign-max-rest-days]')?.value || '0', 10) || 0;
    const minWorkDays = parseInt(document.querySelector('[data-auto-assign-min-work-days]')?.value || '0', 10) || 0;
    const maxWorkDays = parseInt(document.querySelector('[data-auto-assign-max-work-days]')?.value || '0', 10) || 0;
    const restStrategy = String(globalAutoAssignRestStrategy?.value || 'fixed').toLowerCase();
    const presetKey = Object.keys(AUTO_ASSIGN_POLICY_PRESETS).find((key) => {
      const preset = AUTO_ASSIGN_POLICY_PRESETS[key];
      return preset
        && preset.minEmployees === minEmployees
        && preset.maxEmployees === maxEmployees
        && preset.minRestDays === minRestDays
        && preset.maxRestDays === maxRestDays
        && preset.minWorkDays === minWorkDays
        && preset.maxWorkDays === maxWorkDays
        && preset.restStrategy === restStrategy;
    });
    return presetKey || '';
  }

  function updatePolicyPresetUi(activePreset) {
    const buttons = Array.from(document.querySelectorAll('[data-auto-assign-preset]'));
    buttons.forEach((button) => {
      const key = String(button.getAttribute('data-auto-assign-preset') || '');
      const isActive = key !== '' && key === activePreset;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function applyPolicyPreset(presetKey) {
    const preset = AUTO_ASSIGN_POLICY_PRESETS[presetKey];
    if (!preset) return;
    const minEmployeesInput = document.querySelector('[data-auto-assign-min-employees]');
    const maxEmployeesInput = document.querySelector('[data-auto-assign-max-employees]');
    const minRestDaysInput = document.querySelector('[data-auto-assign-min-rest-days]');
    const maxRestDaysInput = document.querySelector('[data-auto-assign-max-rest-days]');
    const minWorkDaysInput = document.querySelector('[data-auto-assign-min-work-days]');
    const maxWorkDaysInput = document.querySelector('[data-auto-assign-max-work-days]');
    if (minEmployeesInput) minEmployeesInput.value = String(preset.minEmployees);
    if (maxEmployeesInput) maxEmployeesInput.value = String(preset.maxEmployees);
    if (minRestDaysInput) minRestDaysInput.value = String(preset.minRestDays);
    if (maxRestDaysInput) maxRestDaysInput.value = String(preset.maxRestDays);
    if (minWorkDaysInput) minWorkDaysInput.value = String(preset.minWorkDays);
    if (maxWorkDaysInput) maxWorkDaysInput.value = String(preset.maxWorkDays);
    if (globalAutoAssignRestStrategy) {
      globalAutoAssignRestStrategy.value = preset.restStrategy;
    }
    updatePolicyPresetUi(presetKey);
    refreshGlobalForecast();
  }

  function renderGlobalForecast(data) {
    if (!globalAutoAssignForecast) return;
    const status = String(data?.status || 'unknown');
    const raw = data?.forecast && typeof data.forecast === 'object' ? data.forecast : null;
    const tips = [];
    let summary = '';

    if (raw) {
      const shiftDaysTotal = Number(raw.shift_days_total || 0);
      const uncoveredByOpen = Number(raw.uncovered_days_open || 0);
      const coveredAtMinGroups = Number(raw.covered_at_min_groups || 0);
      const requiredToMinimum = Number(raw.required_to_minimum || 0);
      const predictedRemainingAtMin = Number(raw.predicted_remaining_at_min || 0);
      const predictedSurplusCapacity = Number(raw.predicted_surplus_capacity || 0);
      const openSlots = Number(raw.slots_open || 0);
      const restMode = String(data?.rest_distribution_mode || 'fixed');
      const crossSuggestions = Array.isArray(data?.cross_department_suggestions) ? data.cross_department_suggestions : [];
      const uncoveredDays = Array.isArray(data?.uncovered_days) ? data.uncovered_days : [];
      const shiftCreationSuggestions = Array.isArray(data?.shift_creation_suggestions) ? data.shift_creation_suggestions : [];

      if (status === 'shortage') {
        summary = tr('Potential shortage detected for selected range.', 'Risque de manque detecte pour la plage selectionnee.', 'Possibile carenza rilevata per il periodo selezionato.');
      } else if (status === 'surplus') {
        summary = tr('Potential surplus detected for selected range.', 'Potentiel surplus detecte pour la plage selectionnee.', 'Possibile surplus rilevato per il periodo selezionato.');
      } else {
        summary = tr('Coverage looks balanced for selected range.', 'La couverture semble equilibree pour la plage selectionnee.', 'La copertura appare bilanciata per il periodo selezionato.');
      }

      if (Number(raw.employees_in_scope || 0) <= 0) {
        tips.push(tr('No active employees in scope. Add employees or expand scope.', 'Aucun employe actif dans le scope. Ajoutez des employes ou elargissez le scope.', 'Nessun dipendente attivo nell\'ambito. Aggiungi dipendenti o amplia l\'ambito.'));
      }
      if (status === 'shortage') {
        tips.push(tr('Reduce weekly rest days or increase max work days for this run.', 'Reduisez les jours de repos hebdomadaires ou augmentez les jours max de travail pour ce calcul.', 'Riduci i giorni di riposo settimanali o aumenta i giorni massimi di lavoro per questa esecuzione.'));
        tips.push(tr('Expand range and include more shift chips to rebalance load.', 'Elargissez la plage et incluez plus de chips de poste pour reequilibrer la charge.', 'Amplia il periodo e includi più turni per riequilibrare il carico.'));
      } else if (status === 'surplus') {
        tips.push(tr('Surplus detected: create extra shifts in departments with demand.', 'Surplus detecte: creez des postes supplementaires dans les departements en demande.', 'Surplus rilevato: crea turni aggiuntivi nei dipartimenti con maggiore domanda.'));
        if (predictedSurplusCapacity > 0) {
          tips.push(tr('Estimated extra assignable capacity', 'Capacite supplementaire estimable', 'Capacità extra assegnabile stimata') + `: ${predictedSurplusCapacity}`);
        }
        if (openSlots > 0) {
          tips.push(tr('Open slots may remain because assignment is department-scoped.', 'Des postes ouverts peuvent rester car l\'affectation est limitee au departement.', 'Alcuni turni aperti possono restare perché l\'assegnazione è limitata al dipartimento.'));
        }
      } else {
        tips.push(tr('Coverage is near target. Run auto-assign and re-check remaining open slots.', 'La couverture est proche de la cible. Lancez l\'auto-assignation puis recontrolez les postes ouverts restants.', 'La copertura è vicina all\'obiettivo. Avvia l\'assegnazione automatica e ricontrolla i turni aperti restanti.'));
      }

      if (crossSuggestions.length > 0) {
        crossSuggestions.slice(0, 4).forEach((item) => {
          const dateValue = String(item?.work_date || '--');
          const deptName = String(item?.department_name || tr('Department', 'Departement', 'Dipartimento'));
          const openCount = Number(item?.open_count || 0);
          const candidateNames = Array.isArray(item?.candidates)
            ? item.candidates.map((c) => String(c?.name || '').trim()).filter((v) => v !== '')
            : [];
          if (candidateNames.length > 0) {
            tips.push(`${tr('Suggestion', 'Suggestion', 'Suggerimento')} ${dateValue} - ${deptName} (${openCount} ${tr('open', 'ouverts', 'aperti')}): ${candidateNames.join(', ')}`);
          }
        });
      }

      if (uncoveredDays.length > 0) {
        const topDays = uncoveredDays
          .slice(0, 5)
          .map((item) => `${String(item?.work_date || '--')} (${Number(item?.open_count || 0)})`)
          .join(', ');
        tips.push(`${tr('Most uncovered days', 'Jours les plus decouverts', 'Giorni più scoperti')}: ${topDays}`);
      }

      if (shiftCreationSuggestions.length > 0) {
        shiftCreationSuggestions.slice(0, 4).forEach((item) => {
          const deptName = String(item?.department_name || tr('Department', 'Departement', 'Dipartimento'));
          const shiftName = String(item?.shift_name || tr('Shift', 'Poste', 'Turno'));
          const openCount = Number(item?.open_slots || 0);
          tips.push(`${tr('Create extra shifts', 'Creer des postes en plus', 'Crea turni aggiuntivi')}: ${deptName} - ${shiftName} (+${openCount})`);
        });
      }

      if (restMode === 'fixed') {
        tips.push(tr('Fixed rest keeps routine stable but can cluster uncovered days.', 'Le repos fixe stabilise la routine mais peut concentrer les jours non couverts.', 'Il riposo fisso stabilizza la routine ma può concentrare i giorni non coperti.'));
      } else if (restMode === 'staggered') {
        tips.push(tr('Staggered rest helps distribute rest days across the week.', 'La rotation scalaire aide a repartir les repos dans la semaine.', 'La rotazione scalare aiuta a distribuire i riposi nella settimana.'));
      } else {
        tips.push(tr('Random rest may improve distribution but varies at each run.', 'La rotation aleatoire peut mieux repartir mais varie a chaque execution.', 'La rotazione casuale può migliorare la distribuzione ma varia a ogni esecuzione.'));
      }
    } else {
      summary = String(data?.summary || '').trim() || tr('Forecast unavailable.', 'Prevision indisponible.', 'Previsione non disponibile.');
      (Array.isArray(data?.recommendations) ? data.recommendations : []).forEach((item) => tips.push(String(item || '')));
    }

    if (globalAutoAssignForecastSummary) {
      globalAutoAssignForecastSummary.textContent = summary || tr('Forecast unavailable.', 'Prevision indisponible.', 'Previsione non disponibile.');
      globalAutoAssignForecastSummary.style.color = status === 'shortage' ? '#b42318' : status === 'surplus' ? '#1d4ed8' : '';
    }
    renderForecastImpact(data);
    if (data?.ok || data?.success) {
      lastGlobalForecastResponse = data;
    }
    if (globalAutoAssignForecastTips) {
      if (!tips.length) {
        globalAutoAssignForecastTips.innerHTML = '';
      } else {
        globalAutoAssignForecastTips.innerHTML = tips.map((tip) => `<li>${escapeHtml(String(tip || ''))}</li>`).join('');
      }
    }
  }

  async function refreshGlobalForecast() {
    if (!apiUrl || !window.AppAPI || !globalAutoAssignForecast) return;
    const params = getGlobalAutoAssignParams();
    if (!params.globalShiftSelection.allowedShiftIds.length) {
      renderGlobalForecast({
        status: 'shortage',
        summary: tr('Select at least one shift chip to run forecast.', 'Selectionnez au moins un poste pour la prevision.', 'Seleziona almeno un turno per calcolare la previsione.'),
        metrics: [],
        recommendations: [tr('Enable one or more shift chips before auto-assign.', 'Activez un ou plusieurs chips de poste avant l\'affectation automatique.', 'Attiva uno o più turni prima dell\'assegnazione automatica.')],
      });
      lastGlobalForecastResponse = null;
      return;
    }
    if (globalAutoAssignForecastSummary) {
      globalAutoAssignForecastSummary.textContent = tr('Calculating forecast...', 'Calcul de la prevision...', 'Calcolo della previsione...');
    }
    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'auto_assign_forecast',
        scope_shift_id: 0,
        allowed_shift_ids: params.globalShiftSelection.allowedShiftIds,
        range_start: params.range.start,
        range_end: params.range.end,
        min_employees_per_shift_day: params.normalizedMin,
        max_employees_per_shift_day: params.normalizedMax,
        min_rest_days_per_week: params.boundedMinRestDays,
        max_rest_days_per_week: params.boundedMaxRestDays,
        min_work_days_per_week: params.boundedMinWorkDays,
        max_work_days_per_week: params.boundedMaxWorkDays,
        rest_distribution_mode: params.restDistributionMode,
        allow_cross_department_fallback: true,
        priority_department_id: params.priorityDepartment.id,
        priority_department_strict_internal: params.priorityDepartmentStrictInternal,
      });
      if (res?.ok || res?.success) {
        renderGlobalForecast(res);
      } else {
        renderGlobalForecast({
          status: 'unknown',
          summary: tr('Forecast failed.', 'La prevision a echoue.', 'Previsione non riuscita.'),
          metrics: [],
          recommendations: [String(res?.error || tr('Unknown error.', 'Erreur inconnue.', 'Errore sconosciuto.'))],
        });
      }
    } catch (error) {
      console.error(error);
      renderGlobalForecast({
        status: 'unknown',
        summary: tr('Forecast unavailable.', 'Prevision indisponible.', 'Previsione non disponibile.'),
        metrics: [],
        recommendations: [tr('Retry after updating period and limits.', 'Reessayez apres avoir ajuste la periode et les limites.', 'Riprova dopo aver aggiornato periodo e limiti.')],
      });
    }
  }

  function getActiveAssignmentsMonthKey() {
    const period = resolveEmployeePeriodRange();
    if (period.mode === 'month') {
      return normalizeMonthKey(period.month) || currentMonthKey();
    }
    return currentMonthKey();
  }

  async function fetchEmployeeAssignmentsByMonth(userId, monthKey) {
    if (!apiUrl || !window.AppAPI) return [];
    const normalizedUserId = parseInt(String(userId || '0'), 10) || 0;
    const normalizedMonth = normalizeMonthKey(monthKey) || currentMonthKey();
    if (!normalizedUserId) return [];

    const res = await AppAPI.postJSON(apiUrl, {
      action: 'employee_assignments',
      target_user_id: normalizedUserId,
      target_month: normalizedMonth,
    });

    if (!res?.ok && !res?.success) {
      throw new Error(res?.error || 'Unable to load employee assignments.');
    }

    const rows = Array.isArray(res.assignments) ? res.assignments : [];
    return rows.map((item) => ({
      assignmentId: String(item?.assignment_id || '0'),
      workDate: String(item?.work_date || '--'),
      shiftName: String(item?.shift_name || 'Shift'),
      shiftIcon: String(item?.shift_icon || '🕒'),
      shiftKind: String(item?.shift_kind || 'work'),
      departmentName: String(item?.department_name || '--'),
      status: String(item?.status || 'assigned'),
      startTime: String(item?.start_time || '--:--'),
      endTime: String(item?.end_time || '--:--'),
    }));
  }

  function getDateKeysInRange(startKey, endKey) {
    const range = normalizeDateRange(startKey, endKey);
    if (!range) return [];
    const list = [];
    const cursor = new Date(`${range.start}T12:00:00`);
    const end = new Date(`${range.end}T12:00:00`);
    while (cursor <= end) {
      list.push(dateKeyFromDate(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }
    return list;
  }

  function loadRules() {
    try {
      const raw = localStorage.getItem(RULES_STORAGE_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (_error) {
      return {};
    }
  }

  function saveRules(rules) {
    try {
      localStorage.setItem(RULES_STORAGE_KEY, JSON.stringify(rules || {}));
    } catch (_error) {
      // Ignore storage failures and keep UI functional.
    }
  }

  function toRuleReasonLabel(reason) {
    switch (String(reason || 'special')) {
      case 'rest':
        return tr('Weekly rest', 'Repos hebdomadaire', 'Riposo settimanale');
      case 'leave':
        return tr('Leave', 'Conge', 'Permesso');
      case 'vacation':
        return tr('Vacation', 'Vacances', 'Vacanza');
      case 'sick':
        return tr('Sick leave', 'Conge maladie', 'Malattia');
      default:
        return tr('Special day', 'Jour special', 'Giorno speciale');
    }
  }

  function toShiftKindLabel(kind) {
    var normalized = String(kind || 'work').toLowerCase();
    if (normalized === 'rest' || normalized === 'vacation' || normalized === 'sick') {
      return toRuleReasonLabel(normalized);
    }
    if (normalized === 'work') {
      return tr('Work', 'Travail', 'Lavoro');
    }
    return normalized;
  }

  function getRuleRows() {
    return Array.from(document.querySelectorAll('[data-auto-rule-user-id]'));
  }

  function readRuleFromRow(row) {
    if (!row) return null;
    const userId = String(row.dataset.autoRuleUserId || '').trim();
    if (!userId) return null;

    const scope = row.querySelector('[data-auto-rule-scope]')?.value || 'all';
    const offWeekdays = Array.from(row.querySelectorAll('[data-auto-rule-weekday]:checked'))
      .map((checkbox) => parseInt(checkbox.getAttribute('data-auto-rule-weekday') || '-1', 10))
      .filter((weekday) => weekday >= 0 && weekday <= 6);

    const specialDates = [];
    row.querySelectorAll('[data-auto-rule-special-list] [data-date][data-reason]').forEach((chip) => {
      const date = chip.getAttribute('data-date') || '';
      const reason = chip.getAttribute('data-reason') || 'special';
      if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        specialDates.push({ date, reason });
      }
    });

    return {
      userId,
      rule: {
        scope,
        off_weekdays: Array.from(new Set(offWeekdays)).sort(),
        special_dates: specialDates,
      },
    };
  }

  function renderSpecialDates(row, specialDates) {
    const wrap = row?.querySelector('[data-auto-rule-special-list]');
    if (!wrap) return;
    const list = Array.isArray(specialDates) ? specialDates : [];
    if (!list.length) {
      wrap.innerHTML = '';
      return;
    }

    wrap.innerHTML = list.map((item) => {
      const safeDate = String(item?.date || '');
      const safeReason = String(item?.reason || 'special');
      return `<span class="settings-auto-rule-chip" data-date="${safeDate}" data-reason="${safeReason}">${safeDate} • ${toRuleReasonLabel(safeReason)}<button type="button" data-auto-rule-remove-special="${safeDate}" aria-label="${tr('Remove unavailable date', 'Supprimer la date indisponible', 'Rimuovi la data non disponibile')}">×</button></span>`;
    }).join('');
  }

  function applyRulesToRows(rules) {
    getRuleRows().forEach((row) => {
      const userId = String(row.dataset.autoRuleUserId || '').trim();
      const userRule = (rules && rules[userId]) || null;
      const scope = userRule?.scope || 'all';
      const offWeekdays = new Set(Array.isArray(userRule?.off_weekdays) ? userRule.off_weekdays.map((x) => parseInt(x, 10)) : []);
      const specialDates = Array.isArray(userRule?.special_dates) ? userRule.special_dates : [];

      const scopeSelect = row.querySelector('[data-auto-rule-scope]');
      if (scopeSelect) {
        scopeSelect.value = scope;
      }

      row.querySelectorAll('[data-auto-rule-weekday]').forEach((checkbox) => {
        const weekday = parseInt(checkbox.getAttribute('data-auto-rule-weekday') || '-1', 10);
        checkbox.checked = offWeekdays.has(weekday);
      });

      renderSpecialDates(row, specialDates);
    });
  }

  function collectRulesFromRows() {
    const rules = {};
    getRuleRows().forEach((row) => {
      const payload = readRuleFromRow(row);
      if (payload) {
        rules[payload.userId] = payload.rule;
      }
    });
    return rules;
  }

  function monthsDistance(fromMonthKey, toMonthKey) {
    const f = String(fromMonthKey || '').split('-');
    const t2 = String(toMonthKey || '').split('-');
    if (f.length < 2 || t2.length < 2) return 0;
    return (parseInt(t2[0], 10) - parseInt(f[0], 10)) * 12 + (parseInt(t2[1], 10) - parseInt(f[1], 10));
  }

  function getEffectiveOffWeekdaysForMonth(rule, targetMonthKey) {
    const base = Array.isArray(rule?.off_weekdays)
      ? rule.off_weekdays.map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6)
      : [];
    const overrides = rule?.monthly_overrides;
    if (overrides && Array.isArray(overrides[targetMonthKey])) {
      return overrides[targetMonthKey].map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6);
    }
    if (rule?.rotating?.enabled && base.length > 0) {
      const startMonth = String(rule.rotating.start_month || currentMonthKey());
      const shift = monthsDistance(startMonth, targetMonthKey);
      if (shift > 0) {
        return base.map((d) => (d + shift) % 7);
      }
    }
    return base;
  }

  function getRuleForUser(userId) {
    const normalizedId = String(userId || '').trim();
    if (!normalizedId) {
      return { scope: 'all', off_weekdays: [], special_dates: [], monthly_overrides: {}, rotating: { enabled: false, start_month: currentMonthKey() } };
    }

    const rules = loadRules();
    if (rules[normalizedId]) {
      return {
        scope: String(rules[normalizedId].scope || 'all'),
        off_weekdays: Array.isArray(rules[normalizedId].off_weekdays) ? rules[normalizedId].off_weekdays.map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6) : [],
        special_dates: Array.isArray(rules[normalizedId].special_dates) ? rules[normalizedId].special_dates : [],
        monthly_overrides: (rules[normalizedId].monthly_overrides && typeof rules[normalizedId].monthly_overrides === 'object') ? rules[normalizedId].monthly_overrides : {},
        rotating: { enabled: !!(rules[normalizedId].rotating?.enabled), start_month: String(rules[normalizedId].rotating?.start_month || currentMonthKey()) },
      };
    }

    const row = getRuleRows().find((item) => String(item.dataset.autoRuleUserId || '') === normalizedId);
    return readRuleFromRow(row)?.rule || { scope: 'all', off_weekdays: [], special_dates: [], monthly_overrides: {}, rotating: { enabled: false, start_month: currentMonthKey() } };
  }

  function setRuleForUser(userId, nextRule) {
    const normalizedId = String(userId || '').trim();
    if (!normalizedId) return;
    const rules = loadRules();
    const monthlyOverridesRaw = (nextRule?.monthly_overrides && typeof nextRule.monthly_overrides === 'object') ? nextRule.monthly_overrides : {};
    const monthlyOverrides = {};
    for (const [month, days] of Object.entries(monthlyOverridesRaw)) {
      if (/^\d{4}-\d{2}$/.test(String(month)) && Array.isArray(days)) {
        monthlyOverrides[String(month)] = days.map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6);
      }
    }
    rules[normalizedId] = {
      scope: String(nextRule?.scope || 'all'),
      off_weekdays: Array.isArray(nextRule?.off_weekdays) ? Array.from(new Set(nextRule.off_weekdays.map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6))).sort() : [],
      special_dates: Array.isArray(nextRule?.special_dates) ? nextRule.special_dates.filter((x) => /^\d{4}-\d{2}-\d{2}$/.test(String(x?.date || ''))).map((x) => ({ date: String(x.date), reason: String(x.reason || 'special') })) : [],
      monthly_overrides: monthlyOverrides,
      rotating: {
        enabled: !!(nextRule?.rotating?.enabled),
        start_month: /^\d{4}-\d{2}$/.test(String(nextRule?.rotating?.start_month || '')) ? String(nextRule.rotating.start_month) : currentMonthKey(),
      },
    };
    saveRules(rules);
    applyRulesToRows(rules);
  }

  function getUnavailableDatesInMonth(rule, monthKey) {
    const monthStart = monthStartKey(monthKey);
    const monthEnd = monthEndKey(monthKey);
    const unavailable = new Map();
    const effectiveWeekdays = new Set(getEffectiveOffWeekdaysForMonth(rule, monthKey));
    getDateKeysInRange(monthStart, monthEnd).forEach((dateKey) => {
      const weekday = new Date(`${dateKey}T12:00:00`).getDay();
      if (effectiveWeekdays.has(weekday)) {
        unavailable.set(dateKey, 'rest');
      }
    });
    (Array.isArray(rule?.special_dates) ? rule.special_dates : []).forEach((item) => {
      const dateValue = normalizeDateKey(item?.date || '');
      if (!dateValue || dateValue < monthStart || dateValue > monthEnd) return;
      unavailable.set(dateValue, String(item?.reason || 'special'));
    });
    return Array.from(unavailable.entries())
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([date, reason]) => ({ date, reason }));
  }

  function renderSelectedMonthUnavailable(rule) {
    if (!employeeModalMonthUnavailable) return;
    const dates = getUnavailableDatesInMonth(rule, getActiveRulesMonthKey());
    if (!dates.length) {
      employeeModalMonthUnavailable.innerHTML = '<span class="crud-modal-subtitle">No unavailable dates in selected month.</span>';
      return;
    }
    employeeModalMonthUnavailable.innerHTML = dates
      .map((item) => `<span class="settings-auto-rule-chip">${item.date} • ${toRuleReasonLabel(item.reason)}</span>`)
      .join('');
  }

  function syncRuleRangeDefaults() {
    const monthKey = getActiveRulesMonthKey();
    const monthStart = monthStartKey(monthKey);
    const monthEnd = monthEndKey(monthKey);
    if (employeeModalRulesMonth && !normalizeMonthKey(employeeModalRulesMonth.value)) {
      employeeModalRulesMonth.value = monthKey;
    }
    if (employeeModalSpecialDate && !normalizeDateKey(employeeModalSpecialDate.value)) {
      employeeModalSpecialDate.value = monthStart;
    }
    if (employeeModalSpecialFrom && !normalizeDateKey(employeeModalSpecialFrom.value)) {
      employeeModalSpecialFrom.value = monthStart;
    }
    if (employeeModalSpecialTo && !normalizeDateKey(employeeModalSpecialTo.value)) {
      employeeModalSpecialTo.value = monthEnd;
    }
  }

  function syncAbsenceRangeDefaults() {
    const selectedMonth = getActiveRulesMonthKey();
    const monthStart = monthStartKey(selectedMonth);
    const monthEnd = monthEndKey(selectedMonth);
    if (employeeModalAbsenceFrom && !normalizeDateKey(employeeModalAbsenceFrom.value)) {
      employeeModalAbsenceFrom.value = monthStart;
    }
    if (employeeModalAbsenceTo && !normalizeDateKey(employeeModalAbsenceTo.value)) {
      employeeModalAbsenceTo.value = monthEnd;
    }
  }

  function populateOpenShiftFilter() {
    const options = getAssignableWorkShiftsForEmployee();
    const optionHtml = options
      .map((item) => {
        const id = parseInt(String(item?.id || '0'), 10) || 0;
        const icon = String(item?.icon || '🕒');
        const iconLabel = isIconAsset(icon) ? '•' : icon;
        const name = String(item?.name || tr('Shift', 'Poste', 'Turno'));
        const departmentName = String(item?.department_name || '');
        return `<option value="${id}">${iconLabel} ${name}${departmentName ? ` • ${departmentName}` : ''}</option>`;
      })
      .join('');
    if (employeeModalOpenShift) {
      employeeModalOpenShift.innerHTML = `<option value="0">${tr('All visible work shifts', 'Tous les postes de travail visibles', 'Tutti i turni di lavoro visibili')}</option>${optionHtml}`;
    }

    const availableIds = new Set(options.map((item) => parseInt(String(item?.id || '0'), 10) || 0));
    selectedOpenShiftFilterIds = new Set(Array.from(selectedOpenShiftFilterIds).filter((id) => availableIds.has(id)));
    if (selectedOpenShiftFilterIds.size === 0) {
      selectedOpenShiftFilterIds = new Set(Array.from(availableIds));
    }

    if (employeeModalOpenShiftList) {
      if (!options.length) {
        employeeModalOpenShiftList.innerHTML = `<span class="crud-modal-subtitle">${tr('No work shifts available in current scope.', 'Aucun poste de travail disponible dans le scope actuel.', 'Nessun turno di lavoro disponibile nell\'ambito attuale.')}</span>`;
      } else {
        employeeModalOpenShiftList.innerHTML = options.map((item) => {
          const id = parseInt(String(item?.id || '0'), 10) || 0;
          const checked = selectedOpenShiftFilterIds.has(id) ? 'checked' : '';
          const icon = renderShiftIcon(String(item?.icon || ''));
          const departmentName = String(item?.department_name || '');
          return `<label class="settings-assignment-open-shift-chip dashboard-print-department-chip"><input type="checkbox" data-assignment-modal-open-shift-id="${id}" ${checked}>${icon} ${escapeHtml(String(item?.name || tr('Shift', 'Poste', 'Turno')))}${departmentName ? ` • ${escapeHtml(departmentName)}` : ''}</label>`;
        }).join('');
      }
    }
  }

  function getAbsenceShiftIdByType(type) {
    const targetType = String(type || '').toLowerCase();
    const match = shiftCatalog.find((item) => Number(item?.department_id || 0) === Number(activeEmployeeDepartmentId || 0) && String(item?.kind || '').toLowerCase() === targetType);
    return parseInt(String(match?.id || '0'), 10) || 0;
  }

  function addUnavailableRangeForActiveEmployee() {
    if (!activeEmployeeUserId) return;
    const range = normalizeDateRange(employeeModalSpecialFrom?.value || '', employeeModalSpecialTo?.value || '');
    const reason = String(employeeModalSpecialReason?.value || 'special').trim() || 'special';
    if (!range) {
      notifyError('Choose a valid date range.');
      return;
    }
    const selectedMonth = getActiveRulesMonthKey();
    const monthStart = monthStartKey(selectedMonth);
    const monthEnd = monthEndKey(selectedMonth);
    const boundedStart = range.start < monthStart ? monthStart : range.start;
    const boundedEnd = range.end > monthEnd ? monthEnd : range.end;
    const boundedRange = normalizeDateRange(boundedStart, boundedEnd);
    if (!boundedRange) {
      notifyError('Range is outside current month.');
      return;
    }
    const currentRule = getRuleForUser(activeEmployeeUserId);
    const existing = new Map((currentRule.special_dates || []).map((item) => [String(item?.date || ''), String(item?.reason || 'special')]));
    getDateKeysInRange(boundedRange.start, boundedRange.end).forEach((dateKey) => existing.set(dateKey, reason));
    currentRule.special_dates = Array.from(existing.entries()).map(([date, itemReason]) => ({ date, reason: itemReason }));
    setRuleForUser(activeEmployeeUserId, currentRule);
    openEmployeeModal(activeEmployeeUserId, activeEmployeeUserName, activeEmployeeDepartmentId, activeEmployeeDepartmentName);
  }

  function resetRulesForActiveEmployee() {
    if (!activeEmployeeUserId) return;
    setRuleForUser(activeEmployeeUserId, { scope: 'all', off_weekdays: [], special_dates: [], monthly_overrides: {}, rotating: { enabled: false, start_month: currentMonthKey() } });
    openEmployeeModal(activeEmployeeUserId, activeEmployeeUserName, activeEmployeeDepartmentId, activeEmployeeDepartmentName);
  }

  function renderMonthWeekdaysForSelectedMonth(rule) {
    if (!employeeModalMonthWeekdays) return;
    const selectedMonth = getActiveRulesMonthKey();
    const effectiveWeekdays = new Set(getEffectiveOffWeekdaysForMonth(rule, selectedMonth));
    const hasOverride = !!(rule?.monthly_overrides && Array.isArray(rule.monthly_overrides[selectedMonth]));
    employeeModalMonthWeekdays.innerHTML = WEEKDAY_OPTIONS.map((option) => {
      const checked = effectiveWeekdays.has(option.value) ? 'checked' : '';
      return `<label class="${hasOverride ? 'is-month-override' : ''}"><input type="checkbox" data-assignment-modal-month-weekday="${option.value}" ${checked}>${option.label}</label>`;
    }).join('');
    if (employeeModalClearMonthOverride) employeeModalClearMonthOverride.disabled = !hasOverride;
  }

  function renderRotationPreview(rule) {
    if (!employeeModalRotationPreview) return;
    const isEnabled = !!(rule?.rotating?.enabled);
    if (employeeModalRotateToggle) {
      employeeModalRotateToggle.textContent = isEnabled
        ? tr('Disable rotation', 'Desactiver la rotation', 'Disattiva rotazione')
        : tr('Rotation (+1 day/month)', 'Rotation (+1 j/mois)', 'Rotazione (+1 giorno/mese)');
      employeeModalRotateToggle.classList.toggle('is-active', isEnabled);
    }
    if (!isEnabled) {
      employeeModalRotationPreview.hidden = true;
      employeeModalRotationPreview.innerHTML = '';
      return;
    }
    employeeModalRotationPreview.hidden = false;
    const base = Array.isArray(rule.off_weekdays) ? rule.off_weekdays.map((x) => parseInt(x, 10)).filter((x) => x >= 0 && x <= 6) : [];
    if (!base.length) {
      employeeModalRotationPreview.innerHTML = `<span class="crud-modal-subtitle">${tr('No base rest days defined.', 'Aucun jour de repos de base defini.', 'Nessun giorno di riposo base definito.')}</span>`;
      return;
    }
    const startMonth = String(rule.rotating?.start_month || currentMonthKey());
    const [sy, sm] = startMonth.split('-').map(Number);
    const monthFmt = new Intl.DateTimeFormat(isFr ? 'fr-FR' : (isIt ? 'it-IT' : 'en-US'), { month: 'short', year: 'numeric' });
    const items = [];
    for (let i = 0; i <= 3; i++) {
      const d = new Date(sy, sm - 1 + i, 1, 12, 0, 0);
      const label = monthFmt.format(d);
      const shiftedDays = base.map((day) => (day + i) % 7);
      const dayLabels = shiftedDays.map((v) => WEEKDAY_OPTIONS.find((opt) => opt.value === v)?.label || v).join(' + ');
      items.push(`<span class="settings-rotation-preview-item"><strong>${label}</strong> : ${dayLabels}</span>`);
    }
    employeeModalRotationPreview.innerHTML = items.join('');
  }

  function toggleRotation() {
    if (!activeEmployeeUserId) return;
    const rule = getRuleForUser(activeEmployeeUserId);
    rule.rotating = {
      enabled: !(rule.rotating?.enabled),
      start_month: currentMonthKey(),
    };
    setRuleForUser(activeEmployeeUserId, rule);
    renderRotationPreview(rule);
    renderMonthWeekdaysForSelectedMonth(rule);
    renderEmployeeWeeklyAvailability(rule);
    renderSelectedMonthUnavailable(rule);
    renderOpenSlotSelection();
  }

  function saveMonthWeekdayOverride() {
    if (!activeEmployeeUserId || !employeeModalMonthWeekdays) return;
    const selectedMonth = getActiveRulesMonthKey();
    if (!selectedMonth) return;
    const checkedWeekdays = Array.from(employeeModalMonthWeekdays.querySelectorAll('[data-assignment-modal-month-weekday]:checked'))
      .map((cb) => parseInt(cb.getAttribute('data-assignment-modal-month-weekday') || '-1', 10))
      .filter((v) => v >= 0 && v <= 6);
    const rule = getRuleForUser(activeEmployeeUserId);
    if (!rule.monthly_overrides || typeof rule.monthly_overrides !== 'object') rule.monthly_overrides = {};
    rule.monthly_overrides[selectedMonth] = checkedWeekdays;
    setRuleForUser(activeEmployeeUserId, rule);
    renderMonthWeekdaysForSelectedMonth(rule);
    renderRotationPreview(rule);
    renderEmployeeWeeklyAvailability(rule);
    renderSelectedMonthUnavailable(rule);
    renderOpenSlotSelection();
  }

  function clearMonthWeekdayOverride() {
    if (!activeEmployeeUserId) return;
    const selectedMonth = getActiveRulesMonthKey();
    if (!selectedMonth) return;
    const rule = getRuleForUser(activeEmployeeUserId);
    if (rule.monthly_overrides && rule.monthly_overrides[selectedMonth] !== undefined) {
      delete rule.monthly_overrides[selectedMonth];
      setRuleForUser(activeEmployeeUserId, rule);
      renderMonthWeekdaysForSelectedMonth(rule);
      renderRotationPreview(rule);
      renderEmployeeWeeklyAvailability(rule);
      renderSelectedMonthUnavailable(rule);
      renderOpenSlotSelection();
    }
  }

  async function assignAbsenceRange() {
    if (!activeEmployeeUserId) return;
    const range = normalizeDateRange(employeeModalAbsenceFrom?.value || '', employeeModalAbsenceTo?.value || '');
    if (!range) {
      notifyError('Choose a valid absence date range.');
      return;
    }
    const absenceType = String(employeeModalAbsenceType?.value || 'vacation').toLowerCase();
    const shiftId = getAbsenceShiftIdByType(absenceType);
    if (!shiftId) {
      notifyError('No shift template found for selected absence type in this department.');
      return;
    }
    const targetDates = getDateKeysInRange(range.start, range.end).filter((dateKey) => !isPastDateKey(dateKey));
    if (!targetDates.length) {
      notifyError('No editable dates found in selected range.');
      return;
    }

    // Free existing work assignments in the selected absence period first.
    const clearRes = await AppAPI.postJSON(apiUrl, {
      action: 'clear_assignments_scope',
      target_user_id: activeEmployeeUserId,
      scope_shift_id: 0,
      allowed_shift_ids: [],
      range_mode: 'custom',
      target_month: getActiveRulesMonthKey(),
      range_start: range.start,
      range_end: range.end,
    });
    if (!clearRes?.ok && !clearRes?.success) {
      notifyError('Unable to clear existing work assignments before absence assignment.');
      return;
    }

    let assignedCount = 0;
    for (const dateKey of targetDates) {
      const success = await assignShiftForEmployee(shiftId, dateKey, '', { silent: true });
      if (success) assignedCount += 1;
    }
    const skippedCount = targetDates.length - assignedCount;
    if (assignedCount > 0) {
      const successMessage = `${assignedCount} absence day(s) assigned.` + (skippedCount > 0 ? ` Skipped: ${skippedCount}.` : '');
      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('assignments', 'Done', successMessage);
      } else {
        notifySuccess(successMessage);
        location.reload();
      }
      return;
    }
    notifyError('No absence days were assigned.');
  }

  function dateKeyFromDate(dateObj) {
    const y = String(dateObj.getFullYear());
    const m = String(dateObj.getMonth() + 1).padStart(2, '0');
    const d = String(dateObj.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function getEmployeeModalField(selector) {
    return employeeModal?.querySelector(selector) || null;
  }

  function normalizeDateKey(value) {
    const normalized = String(value || '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(normalized) ? normalized : '';
  }

  function getVisibleCalendarRange() {
    const dates = Array.from(document.querySelectorAll('[data-calendar-date]'))
      .map((el) => normalizeDateKey(el.getAttribute('data-calendar-date')))
      .filter(Boolean)
      .sort();

    if (!dates.length) {
      const today = new Date();
      return { start: dateKeyFromDate(today), end: dateKeyFromDate(today) };
    }

    return { start: dates[0], end: dates[dates.length - 1] };
  }

  function getOpenSlotEntries() {
    const period = resolveEmployeePeriodRange();
    const todayKey = dateKeyFromDate(new Date());
    const rangeStart = period.start;
    const rangeEnd = period.end;
    const shiftSelection = getOpenShiftSelection();

    return Array.from(document.querySelectorAll('[data-calendar-date] .calendar-event.is-open[data-is-open-slot="1"]'))
      .map((card) => {
        const workDate = normalizeDateKey(card.getAttribute('data-work-date') || card.closest('[data-calendar-date]')?.getAttribute('data-calendar-date') || '');
        const shiftId = parseInt(card.getAttribute('data-shift-id') || '0', 10) || 0;
        const departmentId = parseInt(card.getAttribute('data-department-id') || '0', 10) || 0;
        const departmentName = String(card.getAttribute('data-department-name') || 'Department');
        const shiftKind = String(card.getAttribute('data-shift-kind') || 'work');
        const shiftName = String(card.querySelector('.calendar-event-title')?.textContent || 'Shift');
        const shiftTime = String(card.querySelector('.calendar-event-time')?.textContent || '');
        const key = `${shiftId}|${workDate}`;
        return { key, workDate, shiftId, departmentId, departmentName, shiftKind, shiftName, shiftTime };
      })
      .filter((slot) => {
        if (!slot.workDate || !slot.shiftId) return false;
        if (shiftSelection.mode === 'one' && shiftSelection.scopeShiftId > 0 && slot.shiftId !== shiftSelection.scopeShiftId) return false;
        if (shiftSelection.mode === 'some' && shiftSelection.allowedShiftIds.length > 0 && !shiftSelection.allowedShiftIds.includes(slot.shiftId)) return false;
        if (shiftSelection.mode === 'some' && shiftSelection.allowedShiftIds.length === 0) return false;
        if (slot.workDate < todayKey) return false;
        if (rangeStart && slot.workDate < rangeStart) return false;
        if (rangeEnd && slot.workDate > rangeEnd) return false;
        if (typeof isUserAvailableForDate === 'function' && !isUserAvailableForDate(activeEmployeeUserId, slot.workDate)) return false;
        return true;
      })
      .sort((a, b) => a.workDate.localeCompare(b.workDate) || a.shiftTime.localeCompare(b.shiftTime) || a.shiftName.localeCompare(b.shiftName));
  }

  function renderOpenSlotSelection() {
    const openList = getEmployeeModalField('[data-assignment-modal-open-list]');
    if (!openList) return;

    const slots = getOpenSlotEntries();
    if (!slots.length) {
      openList.innerHTML = '<div class="crud-empty-state">' + tr('No open shifts available for this employee in the selected range.', 'Aucun poste ouvert disponible pour cet employe dans la plage selectionnee.', 'Nessun turno aperto disponibile per questo dipendente nel periodo selezionato.') + '</div>';
      return;
    }

    openList.innerHTML = slots.map((slot) => {
      const isSelected = selectedOpenSlotKeys.has(slot.key);
      return `
        <button type="button" class="settings-assignment-open-slot-item ${isSelected ? 'is-selected' : ''}" data-assignment-modal-open-toggle="${slot.key}">
          <strong>${slot.workDate}</strong>
          <span>${slot.shiftName}</span>
          <small>${slot.shiftTime} • ${slot.departmentName}</small>
        </button>
      `;
    }).join('');
  }

  function syncOpenSlotRangeDefaults() {
    const openFrom = getEmployeeModalField('[data-assignment-modal-open-from]');
    const openTo = getEmployeeModalField('[data-assignment-modal-open-to]');
    if (!openFrom || !openTo) return;
    const visibleRange = getVisibleCalendarRange();
    if (!normalizeDateKey(openFrom.value)) openFrom.value = visibleRange.start;
    if (!normalizeDateKey(openTo.value)) openTo.value = visibleRange.end;
  }

  function clearOpenSlotSelection() {
    selectedOpenSlotKeys = new Set();
    renderOpenSlotSelection();
  }

  function reselectionOpenSlots() {
    const available = getOpenSlotEntries();
    selectedOpenSlotKeys = new Set(available.map((slot) => slot.key));
    renderOpenSlotSelection();
  }

  async function assignSelectedOpenSlots() {
    if (!selectedOpenSlotKeys.size) {
      notifyError('Select at least one open shift.');
      return;
    }

    const slots = getOpenSlotEntries().filter((slot) => selectedOpenSlotKeys.has(slot.key));
    if (!slots.length) {
      notifyError(tr('No valid open shifts found in the current range.', 'Aucun poste ouvert valide trouve dans la plage courante.', 'Nessun turno aperto valido trovato nel periodo corrente.'));
      return;
    }

    try {
      let assignedCount = 0;
      for (const slot of slots) {
        // Re-check availability before each assignment to avoid stale UI selections.
        if (typeof isUserAvailableForDate === 'function' && !isUserAvailableForDate(activeEmployeeUserId, slot.workDate)) {
          continue;
        }
        const success = await assignShiftForEmployee(slot.shiftId, slot.workDate, 'Shift assigned successfully.', { silent: true });
        if (success) {
          assignedCount += 1;
        }
      }
      if (assignedCount > 0) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', tr('Done', 'Termine', 'Fatto'), tr('Selected open shifts assigned successfully.', 'Les postes ouverts selectionnes ont ete assignes avec succes.', 'Turni aperti selezionati assegnati con successo.'));
        } else {
          notifySuccess(tr('Selected open shifts assigned successfully.', 'Les postes ouverts selectionnes ont ete assignes avec succes.', 'Turni aperti selezionati assegnati con successo.'));
          location.reload();
        }
      } else {
        notifyError(tr('No assignable open shifts were selected.', 'Aucun poste ouvert assignable n a ete selectionne.', 'Nessun turno aperto assegnabile selezionato.'));
      }
    } catch (error) {
      console.error(error);
      notifyError(tr('Error assigning selected open shifts.', 'Erreur lors de l\'affectation des postes ouverts selectionnes.', 'Errore durante l\'assegnazione dei turni aperti selezionati.'));
    }
  }

  function renderEmployeeWeeklyAvailability(rule) {
    if (!employeeModalWeekly) return;

    const offWeekdays = new Set(Array.isArray(rule?.off_weekdays) ? rule.off_weekdays.map((value) => parseInt(value, 10)) : []);
    const specialDateMap = new Map((Array.isArray(rule?.special_dates) ? rule.special_dates : []).map((item) => [String(item?.date || ''), String(item?.reason || 'special')]));

    const monthKey = getActiveRulesMonthKey();
    const monthStart = monthStartKey(monthKey);
    const monthEnd = monthEndKey(monthKey);
    const monthDays = getDateKeysInRange(monthStart, monthEnd);
    const weekBlocks = [];
    let currentWeek = [];
    monthDays.forEach((dateKey) => {
      const dateObj = new Date(`${dateKey}T12:00:00`);
      const weekday = dateObj.getDay();
      const specialReason = specialDateMap.get(dateKey) || '';
      const blocked = !!specialReason || offWeekdays.has(weekday);
      currentWeek.push({
        key: dateKey,
        label: WEEKDAY_OPTIONS.find((item) => item.value === weekday)?.label || '',
        blocked,
        reason: specialReason || (offWeekdays.has(weekday) ? 'rest' : ''),
      });
      if (currentWeek.length === 7) {
        weekBlocks.push(currentWeek);
        currentWeek = [];
      }
    });
    if (currentWeek.length) {
      weekBlocks.push(currentWeek);
    }

    employeeModalWeekly.innerHTML = weekBlocks.map((days, index) => {
      return `
        <article class="settings-assignment-week-card">
          <strong>${tr('Week', 'Semaine', 'Settimana')} ${index + 1}</strong>
          <div class="settings-assignment-week-days">
            ${days.map((day) => `
              <button
                type="button"
                class="settings-assignment-week-day ${day.blocked ? 'is-unavailable' : 'is-available'}"
                data-assignment-modal-week-day="${day.key}"
                data-assignment-modal-week-day-state="${day.blocked ? 'unavailable' : 'available'}"
                title="${day.reason ? toRuleReasonLabel(day.reason) : tr('Available', 'Disponible', 'Disponibile')}"
              >
                <small>${day.label}</small>
                <b>${day.key.slice(8)}</b>
              </button>
            `).join('')}
          </div>
        </article>
      `;
    }).join('');
  }

  async function assignShiftForEmployee(shiftId, workDate, successLabel, options = {}) {
    if (!apiUrl || !window.AppAPI || !activeEmployeeUserId || !shiftId || !workDate) return;

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'assign_shift',
        user_id: activeEmployeeUserId,
        shift_id: parseInt(String(shiftId), 10) || 0,
        work_date: workDate,
        status: 'assigned',
      });

      if (res?.ok || res?.success) {
        if (options?.silent) {
          return true;
        }
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', tr('Done', 'Termine', 'Fatto'), successLabel || tr('Shift assigned successfully.', 'Poste assigne avec succes.', 'Turno assegnato con successo.'));
        } else {
          notifySuccess(successLabel || tr('Shift assigned successfully.', 'Poste assigne avec succes.', 'Turno assegnato con successo.'));
          location.reload();
        }
      } else {
        if (options?.silent) {
          return false;
        }
        notifyError(tr('Assignment failed: ', 'Echec de l\'affectation : ', 'Assegnazione non riuscita: ') + (res?.error || tr('unknown', 'inconnue', 'sconosciuto')));
      }
    } catch (error) {
      if (options?.silent) {
        throw error;
      }
      console.error(error);
      notifyError(tr('Error assigning shift.', 'Erreur lors de l\'affectation du poste.', 'Errore durante l\'assegnazione del turno.'));
    }
  }

  function getAssignmentRowsForUser(userId) {
    const normalizedId = String(userId || '').trim();
    if (!normalizedId) return [];
    return Array.from(document.querySelectorAll(`[data-assignment-id][data-assignment-user-id="${normalizedId}"]`));
  }

  function closeEmployeeModal() {
    if (!employeeModal) return;
    closeAssignedShiftsModal();
    employeeModal.hidden = true;
    activeEmployeeUserId = 0;
    activeEmployeeUserName = '';
    activeEmployeeDepartmentId = 0;
    activeEmployeeDepartmentName = '';
    employeeModalSelectedShiftIds = new Set();
    employeeModalShiftRows = [];
  }

  function openAssignedShiftsModal() {
    if (!employeeModalShiftsModal || !activeEmployeeUserId) return;
    employeeModalShiftsModal.hidden = false;
    syncAssignedShiftsToolbar();
    employeeModalShiftsModal.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function closeAssignedShiftsModal() {
    if (!employeeModalShiftsModal) return;
    employeeModalShiftsModal.hidden = true;
  }

  function getVisibleEmployeeShiftRows() {
    return Array.from(employeeModalShifts?.querySelectorAll('[data-assignment-modal-shift-row]') || []);
  }

  function syncAssignedShiftsToolbar() {
    if (!employeeModalShifts) return;
    const rows = getVisibleEmployeeShiftRows();
    const total = rows.length;
    const selectedCount = rows.filter((row) => row.querySelector('[data-assignment-modal-shift-select]')?.checked).length;

    if (employeeModalShiftsSummary) {
      employeeModalShiftsSummary.textContent = total
        ? `${selectedCount}/${total} selected`
        : tr('No shifts assigned for selected month.', 'Aucun poste assigne pour le mois selectionne.', 'Nessun turno assegnato per il mese selezionato.');
    }
    if (employeeModalShiftsModalTitle) {
      employeeModalShiftsModalTitle.textContent = total
        ? `${selectedCount}/${total} selected`
        : tr('No shifts assigned for selected month.', 'Aucun poste assigne pour le mois selectionne.', 'Nessun turno assegnato per il mese selezionato.');
    }

    if (employeeModalShiftsSelectAllBtn) employeeModalShiftsSelectAllBtn.disabled = total === 0 || selectedCount === total;
    if (employeeModalShiftsClearBtn) employeeModalShiftsClearBtn.disabled = selectedCount === 0;
    if (employeeModalShiftsUnassignSelectedBtn) employeeModalShiftsUnassignSelectedBtn.disabled = selectedCount === 0;
    if (employeeModalShiftsUnassignAllBtn) employeeModalShiftsUnassignAllBtn.disabled = total === 0;
  }

  function setAllEmployeeShiftSelection(checked) {
    if (!employeeModalShifts) return;
    getVisibleEmployeeShiftRows().forEach((row) => {
      const checkbox = row.querySelector('[data-assignment-modal-shift-select]');
      if (checkbox) checkbox.checked = checked;
    });
    employeeModalSelectedShiftIds = new Set(
      checked
        ? getVisibleEmployeeShiftRows().map((row) => parseInt(row.getAttribute('data-assignment-id') || '0', 10)).filter((value) => value > 0)
        : []
    );
    syncAssignedShiftsToolbar();
  }

  function syncEmployeeShiftSelectionFromDom() {
    const selected = getVisibleEmployeeShiftRows()
      .filter((row) => row.querySelector('[data-assignment-modal-shift-select]')?.checked)
      .map((row) => parseInt(row.getAttribute('data-assignment-id') || '0', 10))
      .filter((value) => value > 0);
    employeeModalSelectedShiftIds = new Set(selected);
    syncAssignedShiftsToolbar();
  }

  async function unassignEmployeeShiftsBulk(unassignAll = false) {
    const rows = getVisibleEmployeeShiftRows();
    const targetRows = unassignAll ? rows : rows.filter((row) => row.querySelector('[data-assignment-modal-shift-select]')?.checked);
    const ids = targetRows
      .map((row) => parseInt(row.getAttribute('data-assignment-id') || '0', 10))
      .filter((value) => value > 0);

    if (!ids.length) {
      notifyError('Select at least one assigned shift first.');
      return;
    }

    const validRows = targetRows.filter((row) => !isPastDateKey(String(row.getAttribute('data-assignment-work-date') || '')));
    const validIds = validRows.map((row) => parseInt(row.getAttribute('data-assignment-id') || '0', 10)).filter((value) => value > 0);
    const skipped = ids.length - validIds.length;
    if (!validIds.length) {
      notifyError('Past dates are read-only and cannot be edited.');
      return;
    }

    if (employeeModalShiftsUnassignSelectedBtn) employeeModalShiftsUnassignSelectedBtn.disabled = true;
    if (employeeModalShiftsUnassignAllBtn) employeeModalShiftsUnassignAllBtn.disabled = true;
    if (employeeModalShiftsSelectAllBtn) employeeModalShiftsSelectAllBtn.disabled = true;

    let successCount = 0;
    let failureCount = 0;
    try {
      for (const assignmentId of validIds) {
        // Keep the flow simple and reliable: one verified API call per selected shift.
        // The dashboard refreshes after the batch completes.
        // eslint-disable-next-line no-await-in-loop
        const ok = await unassignAssignmentById(assignmentId);
        if (ok) {
          successCount += 1;
        } else {
          failureCount += 1;
        }
      }

      if (successCount > 0) {
        closeAssignedShiftsModal();
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', `${successCount} shift(s) unassigned successfully.`);
        } else {
          notifySuccess(`${successCount} shift(s) unassigned successfully.`);
          location.reload();
        }
        if (skipped > 0 && failureCount === 0) {
          notifySuccess(`${skipped} past shift(s) were skipped.`);
        }
        return;
      }

      notifyError('Unassign failed: unable to update selected shifts.');
    } catch (error) {
      console.error(error);
      notifyError('Error unassigning shift(s).');
    } finally {
      syncAssignedShiftsToolbar();
    }
  }

  function renderEmployeeWeekdays(rule) {
    if (!employeeModalWeekdays) return;
    const selected = new Set(Array.isArray(rule?.off_weekdays) ? rule.off_weekdays.map((x) => parseInt(x, 10)) : []);
    employeeModalWeekdays.innerHTML = WEEKDAY_OPTIONS.map((option) => {
      const checked = selected.has(option.value) ? 'checked' : '';
      return `<label><input type="checkbox" data-assignment-modal-weekday="${option.value}" ${checked}>${option.label}</label>`;
    }).join('');
  }

  function renderEmployeeSpecialDates(rule) {
    if (!employeeModalSpecialList) return;
    const list = Array.isArray(rule?.special_dates) ? rule.special_dates.slice().sort((a, b) => String(a.date).localeCompare(String(b.date))) : [];
    if (!list.length) {
      employeeModalSpecialList.innerHTML = '<span class="crud-modal-subtitle">' + tr('No unavailable dates defined.', 'Aucune date indisponible definie.', 'Nessuna data non disponibile definita.') + '</span>';
      return;
    }

    employeeModalSpecialList.innerHTML = list.map((item) => {
      const dateValue = String(item?.date || '');
      const reason = String(item?.reason || 'special');
      return `<span class="settings-auto-rule-chip" data-date="${dateValue}" data-reason="${reason}">${dateValue} • ${toRuleReasonLabel(reason)}<button type="button" data-assignment-modal-remove-special="${dateValue}" aria-label="${tr('Remove unavailable date', 'Supprimer la date indisponible', 'Rimuovi la data non disponibile')}">×</button></span>`;
    }).join('');
  }

  function renderEmployeeShiftList(rows, monthKey) {
    if (!employeeModalShifts) return;
    employeeModalShiftRows = Array.isArray(rows) ? rows.slice() : [];
    employeeModalSelectedShiftIds = new Set();
    const normalizedMonth = normalizeMonthKey(monthKey) || currentMonthKey();
    if (!rows.length) {
      employeeModalShifts.innerHTML = '<div class="crud-empty-state">' + tr('No shifts assigned for selected month.', 'Aucun poste assigne pour le mois selectionne.', 'Nessun turno assegnato per il mese selezionato.') + ` (${normalizedMonth})</div>`;
      syncAssignedShiftsToolbar();
      return;
    }

    employeeModalShifts.innerHTML = rows.map((row) => {
      const assignmentId = String(row.assignmentId || row.dataset?.assignmentId || '0');
      const workDate = String(row.workDate || row.dataset?.assignmentWorkDate || '--');
      const shiftName = String(row.shiftName || row.dataset?.assignmentShiftName || 'Shift');
      const shiftIcon = String(row.shiftIcon || row.dataset?.assignmentShiftIcon || '🕒');
      const shiftKind = String(row.shiftKind || row.dataset?.assignmentShiftKind || 'work');
      const shiftKindLabel = toShiftKindLabel(shiftKind);
      const departmentName = String(row.departmentName || row.dataset?.assignmentDepartmentName || '--');
      const status = String(row.status || row.dataset?.assignmentStatus || 'assigned');
      const start = String(row.startTime || row.dataset?.assignmentStartTime || '--:--');
      const end = String(row.endTime || row.dataset?.assignmentEndTime || '--:--');
      return `
        <article class="settings-assignment-modal-shift-item" data-assignment-modal-shift-row data-assignment-id="${assignmentId}" data-assignment-work-date="${escapeHtml(workDate)}">
          <div class="settings-assignment-modal-shift-item-head">
            <strong>${workDate}</strong>
            <span>${status}</span>
          </div>
          <div class="settings-assignment-modal-shift-item-meta"><label class="settings-assignment-shift-select-label"><input type="checkbox" data-assignment-modal-shift-select data-assignment-id="${assignmentId}"> <span>${renderShiftIcon(shiftIcon)} ${escapeHtml(shiftName)} • ${escapeHtml(start || '--:--')} - ${escapeHtml(end || '--:--')} • ${escapeHtml(shiftKindLabel)} • ${escapeHtml(departmentName)}</span></label></div>
          <div class="settings-assignment-modal-shift-item-actions">
            <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-edit="${assignmentId}">Modify</button>
            <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-unassign="${assignmentId}">Unassign</button>
          </div>
        </article>
      `;
    }).join('');
    syncAssignedShiftsToolbar();
  }

  async function refreshEmployeeAssignedShifts() {
    if (!activeEmployeeUserId || !employeeModalShifts) return;
    const monthKey = getActiveAssignmentsMonthKey();
    employeeModalShifts.innerHTML = '<div class="crud-empty-state">' + tr('Loading assigned shifts...', 'Chargement des postes assignes...', 'Caricamento dei turni assegnati...') + '</div>';
    try {
      const rows = await fetchEmployeeAssignmentsByMonth(activeEmployeeUserId, monthKey);
      renderEmployeeShiftList(rows, monthKey);
    } catch (error) {
      console.error(error);
      employeeModalShifts.innerHTML = '<div class="crud-empty-state">' + tr('Unable to load assigned shifts for selected month.', 'Impossible de charger les postes assignes pour le mois selectionne.', 'Impossibile caricare i turni assegnati per il mese selezionato.') + '</div>';
    }
  }

  async function openEmployeeModal(userId, userName, departmentId = 0, departmentName = '') {
    if (!employeeModal) return;
    const normalizedId = parseInt(String(userId || '0'), 10) || 0;
    if (!normalizedId) return;

    activeEmployeeUserId = normalizedId;
    activeEmployeeUserName = String(userName || '').trim() || `Employee #${normalizedId}`;
    activeEmployeeDepartmentId = parseInt(String(departmentId || '0'), 10) || 0;
    activeEmployeeDepartmentName = String(departmentName || '').trim();

    const rule = getRuleForUser(normalizedId);
    const rows = getAssignmentRowsForUser(normalizedId).filter((row) => String(row.dataset.assignmentStatus || '') !== 'cancelled');
    const counts = {
      assigned: rows.length,
      sick: rows.filter((row) => String(row.dataset.assignmentShiftKind || '').toLowerCase() === 'sick').length,
      vacation: rows.filter((row) => String(row.dataset.assignmentShiftKind || '').toLowerCase() === 'vacation').length,
      rest: rows.filter((row) => String(row.dataset.assignmentShiftKind || '').toLowerCase() === 'rest').length,
    };

    if (employeeModalTitle) {
      employeeModalTitle.textContent = activeEmployeeUserName;
    }
    if (employeeModalSubtitle) {
      employeeModalSubtitle.textContent = `${tr('Assigned', 'Assigne', 'Assegnato')}: ${counts.assigned} • ${tr('Sick', 'Maladie', 'Malattia')}: ${counts.sick} • ${tr('Vacation', 'Vacances', 'Vacanza')}: ${counts.vacation} • ${tr('Rest', 'Repos', 'Riposo')}: ${counts.rest}`;
    }

    renderEmployeeWeekdays(rule);
    renderMonthWeekdaysForSelectedMonth(rule);
    renderRotationPreview(rule);
    renderEmployeeSpecialDates(rule);
    renderEmployeeWeeklyAvailability(rule);
    renderSelectedMonthUnavailable(rule);
    closeAssignedShiftsModal();
    renderEmployeeShiftList(rows, getActiveAssignmentsMonthKey());
    if (employeeModalRulesMonth && !normalizeMonthKey(employeeModalRulesMonth.value)) {
      employeeModalRulesMonth.value = currentMonthKey();
    }
    if (employeeModalPeriodMode && !employeeModalPeriodMode.value) {
      employeeModalPeriodMode.value = 'current';
    }
    if (employeeModalPeriodMonth && !normalizeMonthKey(employeeModalPeriodMonth.value)) {
      employeeModalPeriodMonth.value = currentMonthKey();
    }
    if (employeeModalShiftMode && !employeeModalShiftMode.value) {
      employeeModalShiftMode.value = 'some';
    }
    selectedOpenShiftFilterIds = new Set();
    syncRuleRangeDefaults();
    syncAbsenceRangeDefaults();
    populateOpenShiftFilter();
    syncEmployeePeriodInputs();
    clearOpenSlotSelection();
    renderOpenSlotSelection();
    employeeModal.hidden = false;
    await refreshEmployeeAssignedShifts();
  }

  function persistRulesFromRows() {
    saveRules(collectRulesFromRows());
  }

  function addSpecialDateToRow(row) {
    if (!row) return;
    const dateInput = row.querySelector('[data-auto-rule-special-date]');
    const reasonInput = row.querySelector('[data-auto-rule-special-reason]');
    const dateValue = (dateInput?.value || '').trim();
    const reasonValue = (reasonInput?.value || 'special').trim() || 'special';

    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
      notifyError('Choose a valid unavailable date.');
      return;
    }

    const currentRule = readRuleFromRow(row)?.rule || { scope: 'all', off_weekdays: [], special_dates: [] };
    const hasDate = currentRule.special_dates.some((item) => item.date === dateValue);
    if (hasDate) {
      notifyError('This unavailable date already exists for the employee.');
      return;
    }
    currentRule.special_dates.push({ date: dateValue, reason: reasonValue });
    currentRule.special_dates.sort((a, b) => String(a.date).localeCompare(String(b.date)));
    renderSpecialDates(row, currentRule.special_dates);
    if (dateInput) {
      dateInput.value = '';
    }
    persistRulesFromRows();
  }

  function getRow(el) {
    return el.closest && el.closest('[data-assignment-id]');
  }

  function getDrawer(row) {
    return row ? row.querySelector('.settings-edit-drawer') : null;
  }

  function closeAllDrawers() {
    document.querySelectorAll('[data-assignment-id] .settings-edit-drawer').forEach((drawer) => {
      drawer.hidden = true;
    });
  }

  function openDrawer(row) {
    if (!row) return;
    closeAllDrawers();
    const drawer = getDrawer(row);
    if (drawer) {
      drawer.hidden = false;
      syncAssignmentShiftPreview(drawer);
    }
  }

  function syncAssignmentShiftPreview(scope) {
    if (!scope) return;
    const select = scope.querySelector('select[data-field="shift_id"]');
    const preview = scope.querySelector('[data-assignment-shift-preview]');
    if (!select || !preview) return;

    const selectedOption = select.selectedOptions && select.selectedOptions[0] ? select.selectedOptions[0] : null;
    const iconValue = String(selectedOption?.dataset?.shiftOptionIcon || '').trim();
    const displayName = String(selectedOption?.dataset?.shiftOptionName || selectedOption?.textContent || '').trim();
    const iconHtml = iconValue ? renderShiftIcon(iconValue) : '';
    const labelHtml = escapeHtml(displayName || 'Shift');

    preview.innerHTML = `${iconHtml || ''}<span class="settings-shift-picker-preview-label">${labelHtml}</span>`;
  }

  function closeDrawer(row) {
    const drawer = getDrawer(row);
    if (drawer) drawer.hidden = true;
  }

  async function saveAssignment(row) {
    if (!row || !apiUrl || !window.AppAPI) return;
    const assignmentId = parseInt(row.dataset.assignmentId || '0', 10) || 0;
    if (!assignmentId) return;

    const payload = {
      action: 'move_shift',
      assignment_id: assignmentId,
      shift_id: parseInt(row.querySelector('[data-field="shift_id"]')?.value || '0', 10) || 0,
      user_id: parseInt(row.querySelector('[data-field="user_id"]')?.value || '0', 10) || 0,
      work_date: row.querySelector('[data-field="work_date"]')?.value || '',
      status: row.querySelector('[data-field="status"]')?.value || 'assigned',
    };

    if (!payload.shift_id || !payload.work_date) {
      notifyError('Shift and work date are required.');
      return;
    }
    if (isPastDateKey(payload.work_date)) {
      notifyError('Past dates are read-only and cannot be edited.');
      return;
    }

    try {
      const res = await AppAPI.postJSON(apiUrl, payload);
      if (res?.ok || res?.success) {
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', 'Assignment updated successfully.');
        } else {
          notifySuccess('Assignment updated successfully.');
          location.reload();
        }
      } else {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error saving assignment.');
    }
  }

  async function unassignAssignmentById(assignmentId) {
    const normalizedId = parseInt(String(assignmentId || '0'), 10) || 0;
    if (!normalizedId || !apiUrl || !window.AppAPI) return false;

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'unassign_shift',
        assignment_id: normalizedId,
      });
      if (res?.ok || res?.success) {
        return true;
      }
      throw new Error('Unassign failed: ' + (res?.error || 'unknown'));
    } catch (e) {
      console.error(e);
      return false;
    }
  }

  async function unassignAssignment(row) {
    if (!row || !apiUrl || !window.AppAPI) return;
    const assignmentId = parseInt(row.dataset.assignmentId || '0', 10) || 0;
    if (!assignmentId) return;
    const rowDate = String(row.dataset.assignmentWorkDate || '').trim();
    if (isPastDateKey(rowDate)) {
      notifyError('Past dates are read-only and cannot be edited.');
      return;
    }

    const ok = await unassignAssignmentById(assignmentId);
    if (ok) {
      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('assignments', 'Done', 'Shift unassigned successfully.');
      } else {
        notifySuccess('Shift unassigned successfully.');
        location.reload();
      }
      return;
    }
    notifyError('Error unassigning shift.');
  }

  async function autoAssignOpen() {
    if (!apiUrl || !window.AppAPI) return;

    try {
      const range = normalizeCurrentMonthRange(
        document.querySelector('[data-auto-assign-range-start]')?.value || '',
        document.querySelector('[data-auto-assign-range-end]')?.value || ''
      );
      const rangeStartInput = document.querySelector('[data-auto-assign-range-start]');
      const rangeEndInput = document.querySelector('[data-auto-assign-range-end]');
      if (rangeStartInput) rangeStartInput.value = range.start;
      if (rangeEndInput) rangeEndInput.value = range.end;
      const params = getGlobalAutoAssignParams();
      if (!params.globalShiftSelection.allowedShiftIds.length) {
        notifyError('Select at least one shift for selected-shifts mode.');
        return;
      }

      if (!isRunningConfirmedAutoAssign) {
        const forecastSource = lastGlobalForecastResponse || await (async () => {
          try {
            const previewRes = await AppAPI.postJSON(apiUrl, {
              action: 'auto_assign_forecast',
              scope_shift_id: 0,
              allowed_shift_ids: params.globalShiftSelection.allowedShiftIds,
              range_start: params.range.start,
              range_end: params.range.end,
              min_employees_per_shift_day: params.normalizedMin,
              max_employees_per_shift_day: params.normalizedMax,
              min_rest_days_per_week: params.boundedMinRestDays,
              max_rest_days_per_week: params.boundedMaxRestDays,
              min_work_days_per_week: params.boundedMinWorkDays,
              max_work_days_per_week: params.boundedMaxWorkDays,
              rest_distribution_mode: params.restDistributionMode,
              allow_cross_department_fallback: true,
              priority_department_id: params.priorityDepartment.id,
              priority_department_strict_internal: params.priorityDepartmentStrictInternal,
            });
            if (previewRes?.ok || previewRes?.success) {
              lastGlobalForecastResponse = previewRes;
              return previewRes;
            }
          } catch (previewError) {
            console.error(previewError);
          }
          return null;
        })();

        if (!forecastSource || !renderAutoAssignPreviewModal(forecastSource)) {
          notifyError('Unable to open auto-assign simulation. Refresh forecast and retry.');
          return;
        }
        return;
      }

      isRunningConfirmedAutoAssign = false;
      closeAutoAssignPreviewModal();

      const employeeRules = {
        ...loadRules(),
        ...collectRulesFromRows(),
      };
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'auto_assign_open',
        scope_shift_id: 0,
        allowed_shift_ids: params.globalShiftSelection.allowedShiftIds,
        range_start: range.start,
        range_end: range.end,
        allow_reassign_conflicts: true,
        allow_cross_department_fallback: true,
        min_employees_per_shift_day: params.normalizedMin,
        max_employees_per_shift_day: params.normalizedMax,
        min_rest_days_per_week: params.boundedMinRestDays,
        max_rest_days_per_week: params.boundedMaxRestDays,
        min_work_days_per_week: params.boundedMinWorkDays,
        max_work_days_per_week: params.boundedMaxWorkDays,
        rest_distribution_mode: params.restDistributionMode,
        priority_department_id: params.priorityDepartment.id,
        priority_department_strict_internal: params.priorityDepartmentStrictInternal,
        employee_rules: employeeRules,
      });
      if (res?.ok || res?.success) {
        const skippedByRules = parseInt(res.skipped_by_rules || '0', 10) || 0;
        const groupsBelowMin = parseInt(res.groups_below_min || '0', 10) || 0;
        const reassignedCount = parseInt(res.reassigned_count || '0', 10) || 0;
        const overworkedUsers = Array.isArray(res?.overworked_users) ? res.overworked_users : [];
        const overloadSummary = overworkedUsers.slice(0, 2).map((item) => {
          const employeeName = String(item?.name || tr('Employee', 'Employe', 'Dipendente')).trim();
          const weekLabel = String(item?.week || '').trim();
          const workDays = Number(item?.work_days || 0);
          const restDays = Number(item?.rest_days || 0);
          const replacements = Array.isArray(item?.replacement_suggestions)
            ? item.replacement_suggestions.slice(0, 2).map((candidate) => String(candidate?.name || '').trim()).filter(Boolean)
            : [];
          return `${employeeName}${weekLabel ? ` (${weekLabel})` : ''} ${workDays}/${restDays}${replacements.length ? ` -> ${replacements.join(', ')}` : ''}`;
        }).filter(Boolean);
        const message = `Assigned ${res.assigned_count || 0} shifts. Open remaining: ${res.open_remaining || 0}.`
          + (reassignedCount > 0 ? ` Reassigned: ${reassignedCount}.` : '')
          + (skippedByRules > 0 ? ` Skipped by rules: ${skippedByRules}.` : '')
          + (groupsBelowMin > 0 ? ` Shift-days below minimum: ${groupsBelowMin}.` : '')
          + (overworkedUsers.length > 0 ? ` ${tr('Overloaded employees', 'Employes surcharges', 'Dipendenti sovraccarichi')}: ${overworkedUsers.length}.` : '')
          + (overloadSummary.length > 0 ? ` ${overloadSummary.join(' | ')}` : '');
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', message);
        } else {
          notifySuccess(message);
          location.reload();
        }
        refreshGlobalForecast();
      } else {
        notifyError('Auto assignment failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      isRunningConfirmedAutoAssign = false;
      console.error(e);
      notifyError('Error running auto assignment.');
    }
  }

  async function autoAssignForActiveEmployee() {
    if (!apiUrl || !window.AppAPI) return;
    if (!activeEmployeeUserId || activeEmployeeUserId <= 0) {
      notifyError('Select an employee first.');
      return;
    }

    try {
      syncEmployeePeriodInputs();
      const period = resolveEmployeePeriodRange();
      const shiftSelection = getOpenShiftSelection();
      const assignmentMode = document.querySelector('[data-assignment-modal-open-mode]')?.value || 'multiple';
      const minRestDaysRaw = parseInt(document.querySelector('[data-auto-assign-min-rest-days]')?.value || '1', 10);
      const maxRestDaysRaw = parseInt(document.querySelector('[data-auto-assign-max-rest-days]')?.value || '2', 10);
      const minWorkDaysRaw = parseInt(document.querySelector('[data-auto-assign-min-work-days]')?.value || '4', 10);
      const maxWorkDaysRaw = parseInt(document.querySelector('[data-auto-assign-max-work-days]')?.value || '6', 10);
      const boundedMinRestDays = Math.min(Math.min(6, Math.max(0, Number.isFinite(minRestDaysRaw) ? minRestDaysRaw : 1)), Math.min(6, Math.max(0, Number.isFinite(maxRestDaysRaw) ? maxRestDaysRaw : 2)));
      const boundedMaxRestDays = Math.max(Math.min(6, Math.max(0, Number.isFinite(minRestDaysRaw) ? minRestDaysRaw : 1)), Math.min(6, Math.max(0, Number.isFinite(maxRestDaysRaw) ? maxRestDaysRaw : 2)));
      const boundedMinWorkDays = Math.min(Math.min(7, Math.max(1, Number.isFinite(minWorkDaysRaw) ? minWorkDaysRaw : 4)), Math.min(7, Math.max(1, Number.isFinite(maxWorkDaysRaw) ? maxWorkDaysRaw : 6)));
      const boundedMaxWorkDays = Math.max(Math.min(7, Math.max(1, Number.isFinite(minWorkDaysRaw) ? minWorkDaysRaw : 4)), Math.min(7, Math.max(1, Number.isFinite(maxWorkDaysRaw) ? maxWorkDaysRaw : 6)));
      const restStrategyRaw = String(globalAutoAssignRestStrategy?.value || 'fixed').toLowerCase();
      const restDistributionMode = ['fixed', 'staggered', 'random'].includes(restStrategyRaw) ? restStrategyRaw : 'fixed';
      const employeeRules = {
        ...loadRules(),
        ...collectRulesFromRows(),
      };

      if (shiftSelection.mode === 'some' && !shiftSelection.allowedShiftIds.length) {
        notifyError('Select at least one shift in "Some shifts" mode.');
        return;
      }

      const res = await AppAPI.postJSON(apiUrl, {
        action: 'auto_assign_open',
        scope_shift_id: shiftSelection.mode === 'one' ? shiftSelection.scopeShiftId : 0,
        allowed_shift_ids: shiftSelection.mode === 'some' ? shiftSelection.allowedShiftIds : [],
        range_mode: period.mode,
        target_month: period.month,
        range_start: period.start,
        range_end: period.end,
        allow_reassign_conflicts: true,
        allow_cross_department_fallback: true,
        min_employees_per_shift_day: 0,
        max_employees_per_shift_day: 50,
        min_rest_days_per_week: boundedMinRestDays,
        max_rest_days_per_week: boundedMaxRestDays,
        min_work_days_per_week: boundedMinWorkDays,
        max_work_days_per_week: boundedMaxWorkDays,
        rest_distribution_mode: restDistributionMode,
        priority_department_id: Number(activeEmployeeDepartmentId || 0),
        target_user_id: activeEmployeeUserId,
        assignment_mode: assignmentMode,
        employee_rules: employeeRules,
      });

      if (res?.ok || res?.success) {
        const reassignedCount = parseInt(res.reassigned_count || '0', 10) || 0;
        const overloadedUser = Array.isArray(res?.overworked_users)
          ? res.overworked_users.find((item) => Number(item?.user_id || 0) === Number(activeEmployeeUserId || 0))
          : null;
        const replacementSummary = Array.isArray(overloadedUser?.replacement_suggestions)
          ? overloadedUser.replacement_suggestions.slice(0, 2).map((candidate) => String(candidate?.name || '').trim()).filter(Boolean)
          : [];
        const message = `Assigned ${res.assigned_count || 0} shifts to employee.`
          + (reassignedCount > 0 ? ` Reassigned: ${reassignedCount}.` : '')
          + (overloadedUser ? ` ${tr('Employee overload detected', 'Surcharge employee detectee', 'Sovraccarico dipendente rilevato')}: ${Number(overloadedUser?.work_days || 0)}/${Number(overloadedUser?.rest_days || 0)}.` : '')
          + (replacementSummary.length > 0 ? ` ${tr('Suggested replacements', 'Remplacements suggérés', 'Sostituzioni suggerite')}: ${replacementSummary.join(', ')}.` : '');
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', message);
        } else {
          notifySuccess(message);
          location.reload();
        }
      } else {
        notifyError('Employee auto assignment failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error running employee auto assignment.');
    }
  }

  async function clearAssignmentsForActiveEmployee(options = {}) {
    if (!apiUrl || !window.AppAPI || !activeEmployeeUserId) return;
    syncEmployeePeriodInputs();
    const period = resolveEmployeePeriodRange();
    const shiftSelection = getOpenShiftSelection();

    if (shiftSelection.mode === 'some' && !shiftSelection.allowedShiftIds.length) {
      notifyError('Select at least one shift in "Some shifts" mode.');
      return;
    }

    const canClear = feedback?.confirm
      ? await feedback.confirm('Clear existing assignments for this employee in the selected period?', 'Confirm action')
      : false;

    if (!canClear) {
      if (!feedback?.confirm) {
        notifyError('Confirmation dialog is not available.');
      }
      return;
    }

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'clear_assignments_scope',
        target_user_id: activeEmployeeUserId,
        scope_shift_id: shiftSelection.mode === 'one' ? shiftSelection.scopeShiftId : 0,
        allowed_shift_ids: shiftSelection.mode === 'some' ? shiftSelection.allowedShiftIds : [],
        range_mode: period.mode,
        target_month: period.month,
        range_start: period.start,
        range_end: period.end,
      });
      if (res?.ok || res?.success) {
        const message = `Cleared ${res.cleared_count || 0} assigned shift(s) for employee.`;
        if (options?.silent) {
          return true;
        }
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', message);
        } else {
          notifySuccess(message);
          location.reload();
        }
      } else {
        notifyError('Clear employee assignments failed: ' + (res?.error || 'unknown'));
        return false;
      }
    } catch (error) {
      console.error(error);
      notifyError('Error clearing employee assignments.');
      return false;
    }
    return true;
  }

  async function reassignForActiveEmployee() {
    if (!apiUrl || !window.AppAPI || !activeEmployeeUserId) return;
    const cleared = await clearAssignmentsForActiveEmployee({ silent: true });
    if (!cleared) return;
    await autoAssignForActiveEmployee();
  }

  async function clearAssignedInScope() {
    if (!apiUrl || !window.AppAPI) return;

    const globalShiftSelection = getGlobalShiftSelection();
    if (!globalShiftSelection.allowedShiftIds.length) {
      notifyError('Select at least one shift for selected-shifts mode.');
      return;
    }
    const normalizedRange = normalizeCurrentMonthRange(
      document.querySelector('[data-auto-assign-range-start]')?.value || '',
      document.querySelector('[data-auto-assign-range-end]')?.value || ''
    );
    const rangeStartInput = document.querySelector('[data-auto-assign-range-start]');
    const rangeEndInput = document.querySelector('[data-auto-assign-range-end]');
    if (rangeStartInput) rangeStartInput.value = normalizedRange.start;
    if (rangeEndInput) rangeEndInput.value = normalizedRange.end;
    const rangeStart = normalizedRange.start;
    const rangeEnd = normalizedRange.end;
    const shiftLabel = `${globalShiftSelection.allowedShiftIds.length} selected shifts`;
    const rangeLabel = rangeStart && rangeEnd ? `${rangeStart} to ${rangeEnd}` : 'the selected range';

    const canClear = feedback?.confirm
      ? await feedback.confirm(`Unassign all employees for ${shiftLabel} in ${rangeLabel}?`, 'Confirm action')
      : false;

    if (!canClear) {
      if (!feedback?.confirm) {
        notifyError('Confirmation dialog is not available.');
      }
      return;
    }

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'clear_assignments_scope',
        scope_shift_id: 0,
        allowed_shift_ids: globalShiftSelection.allowedShiftIds,
        include_rest_assignments: true,
        range_start: rangeStart,
        range_end: rangeEnd,
      });
      if (res?.ok || res?.success) {
        const message = `Cleared ${res.cleared_count || 0} assigned shift(s).`;
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', 'Done', message);
        } else {
          notifySuccess(message);
          location.reload();
        }
      } else {
        notifyError('Clear assignments failed: ' + (res?.error || 'unknown'));
      }
    } catch (e) {
      console.error(e);
      notifyError('Error clearing assignments.');
    }
  }

  // ── Department-level quick-assign / clear ─────────────────────────────────

  function getDeptShiftIds(btn) {
    const raw = String(btn.getAttribute('data-dept-shift-ids') || '').trim();
    if (!raw) return [];
    return raw.split(',').map((v) => parseInt(v.trim(), 10) || 0).filter((v) => v > 0);
  }

  async function autoAssignForDepartment(departmentId, departmentName, shiftIds) {
    if (!apiUrl || !window.AppAPI) return;
    const deptId = parseInt(String(departmentId || '0'), 10) || 0;
    if (!deptId) {
      notifyError(tr('Invalid department.', 'Departement invalide.', 'Dipartimento non valido.'));
      return;
    }
    const allowedShiftIds = Array.isArray(shiftIds) && shiftIds.length
      ? shiftIds
      : shiftCatalog.filter((s) => Number(s?.department_id || 0) === deptId && String(s?.kind || '').toLowerCase() === 'work').map((s) => parseInt(String(s?.id || '0'), 10) || 0).filter(Boolean);

    if (!allowedShiftIds.length) {
      notifyError(tr('No work shifts found for this department.', 'Aucun poste de travail trouve pour ce departement.', 'Nessun turno di lavoro trovato per questo dipartimento.'));
      return;
    }

    const range = normalizeCurrentMonthRange(
      document.querySelector('[data-auto-assign-range-start]')?.value || '',
      document.querySelector('[data-auto-assign-range-end]')?.value || ''
    );
    const params = getGlobalAutoAssignParams ? getGlobalAutoAssignParams() : null;
    const minRest = params?.boundedMinRestDays ?? 1;
    const maxRest = params?.boundedMaxRestDays ?? 2;
    const minWork = params?.boundedMinWorkDays ?? 4;
    const maxWork = params?.boundedMaxWorkDays ?? 6;
    const restMode = params?.restDistributionMode ?? 'staggered';
    const employeeRules = { ...loadRules(), ...collectRulesFromRows() };

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'auto_assign_open',
        scope_shift_id: 0,
        allowed_shift_ids: allowedShiftIds,
        range_start: range.start,
        range_end: range.end,
        allow_reassign_conflicts: true,
        allow_cross_department_fallback: false,
        min_employees_per_shift_day: params?.normalizedMin ?? 1,
        max_employees_per_shift_day: params?.normalizedMax ?? 3,
        min_rest_days_per_week: minRest,
        max_rest_days_per_week: maxRest,
        min_work_days_per_week: minWork,
        max_work_days_per_week: maxWork,
        rest_distribution_mode: restMode,
        priority_department_id: deptId,
        priority_department_strict_internal: true,
        employee_rules: employeeRules,
      });
      if (res?.ok || res?.success) {
        const msg = `${departmentName || tr('Department', 'Departement', 'Dipartimento')}: ${res.assigned_count || 0} ${tr('shifts assigned', 'postes assignes', 'turni assegnati')}. ${tr('Open remaining', 'Postes ouverts restants', 'Turni aperti restanti')}: ${res.open_remaining || 0}.`;
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', tr('Done', 'Termine', 'Fatto'), msg);
        } else {
          notifySuccess(msg);
          location.reload();
        }
      } else {
        notifyError(tr('Department auto-assign failed: ', 'Echec affectation departement : ', 'Assegnazione automatica del dipartimento non riuscita: ') + (res?.error || tr('unknown', 'inconnue', 'sconosciuto')));
      }
    } catch (e) {
      console.error(e);
      notifyError(tr('Error during department auto-assign.', 'Erreur lors de l\'affectation automatique du departement.', 'Errore durante l\'assegnazione automatica del dipartimento.'));
    }
  }

  async function clearAssignmentsForDepartment(departmentId, departmentName, shiftIds) {
    if (!apiUrl || !window.AppAPI) return;
    const deptId = parseInt(String(departmentId || '0'), 10) || 0;
    if (!deptId) {
      notifyError(tr('Invalid department.', 'Departement invalide.', 'Dipartimento non valido.'));
      return;
    }
    const allowedShiftIds = Array.isArray(shiftIds) && shiftIds.length
      ? shiftIds
      : shiftCatalog.filter((s) => Number(s?.department_id || 0) === deptId && String(s?.kind || '').toLowerCase() === 'work').map((s) => parseInt(String(s?.id || '0'), 10) || 0).filter(Boolean);

    const canClear = feedback?.confirm
      ? await feedback.confirm(tr(`Clear all assignments for department "${departmentName}"?`, `Effacer les affectations du departement "${departmentName}" ?`, `Cancellare tutte le assegnazioni del dipartimento "${departmentName}"?`), tr('Confirm', 'Confirmer', 'Conferma'))
      : false;

    if (!canClear) {
      if (!feedback?.confirm) {
        notifyError(tr('Confirmation dialog not available.', 'Fenetre de confirmation non disponible.', 'Finestra di conferma non disponibile.'));
      }
      return;
    }

    const range = normalizeCurrentMonthRange(
      document.querySelector('[data-auto-assign-range-start]')?.value || '',
      document.querySelector('[data-auto-assign-range-end]')?.value || ''
    );

    try {
      const res = await AppAPI.postJSON(apiUrl, {
        action: 'clear_assignments_scope',
        scope_shift_id: 0,
        allowed_shift_ids: allowedShiftIds,
        include_rest_assignments: false,
        range_start: range.start,
        range_end: range.end,
      });
      if (res?.ok || res?.success) {
        const msg = `${departmentName || tr('Department', 'Departement', 'Dipartimento')}: ${res.cleared_count || 0} ${tr('assignment(s) cleared.', 'affectation(s) effacee(s).', 'assegnazione(i) rimossa(e).')}`;
        if (feedback?.reloadSettingsTabWithSuccess) {
          feedback.reloadSettingsTabWithSuccess('assignments', tr('Done', 'Termine', 'Fatto'), msg);
        } else {
          notifySuccess(msg);
          location.reload();
        }
      } else {
        notifyError(tr('Clear department failed: ', 'Echec effacement departement : ', 'Cancellazione assegnazioni dipartimento non riuscita: ') + (res?.error || tr('unknown', 'inconnue', 'sconosciuto')));
      }
    } catch (e) {
      console.error(e);
      notifyError(tr('Error clearing department assignments.', 'Erreur lors de l\'effacement des affectations du departement.', 'Errore durante la cancellazione delle assegnazioni del dipartimento.'));
    }
  }

  // ─────────────────────────────────────────────────────────────────────────

  document.addEventListener('click', (ev) => {
    const plannerForecastBtn = ev.target.closest && ev.target.closest('[data-assignment-planner-forecast]');
    if (plannerForecastBtn) {
      ev.preventDefault();
      if (!isAssignmentPlannerPanelActive()) return;
      runAssignmentPlannerForecast().catch((error) => {
        notifyError((error && error.message) || tr('Forecast failed.', 'La prevision a echoue.', 'Previsione non riuscita.'));
      });
      return;
    }

    const plannerRunBtn = ev.target.closest && ev.target.closest('[data-assignment-planner-run]');
    if (plannerRunBtn) {
      ev.preventDefault();
      if (!isAssignmentPlannerPanelActive()) return;
      const confirmed = window.confirm(tr('Run automatic assignment with current planner parameters?', 'Lancer l\'affectation automatique avec les parametres actuels ?', 'Avviare l\'assegnazione automatica con i parametri attuali del pianificatore?'));
      if (!confirmed) return;
      runAssignmentPlannerAutoAssign().catch((error) => {
        notifyError((error && error.message) || tr('Auto-assignment failed.', 'L\'affectation automatique a echoue.', 'Assegnazione automatica non riuscita.'));
      });
      return;
    }

    if (!isAssignmentsPanelActive()) return;

    const addRuleDateBtn = ev.target.closest && ev.target.closest('[data-auto-rule-add-special]');
    if (addRuleDateBtn) {
      ev.preventDefault();
      addSpecialDateToRow(addRuleDateBtn.closest('[data-auto-rule-user-id]'));
      return;
    }

    const removeRuleDateBtn = ev.target.closest && ev.target.closest('[data-auto-rule-remove-special]');
    if (removeRuleDateBtn) {
      ev.preventDefault();
      const row = removeRuleDateBtn.closest('[data-auto-rule-user-id]');
      if (!row) return;
      const dateToRemove = removeRuleDateBtn.getAttribute('data-auto-rule-remove-special') || '';
      const currentRule = readRuleFromRow(row)?.rule || { scope: 'all', off_weekdays: [], special_dates: [] };
      currentRule.special_dates = currentRule.special_dates.filter((item) => item.date !== dateToRemove);
      renderSpecialDates(row, currentRule.special_dates);
      persistRulesFromRows();
      return;
    }

    const editBtn = ev.target.closest && ev.target.closest('.settings-assignment-edit');
    if (editBtn) {
      ev.preventDefault();
      openDrawer(getRow(editBtn));
      return;
    }

    const cancelBtn = ev.target.closest && ev.target.closest('.settings-assignment-cancel');
    if (cancelBtn) {
      ev.preventDefault();
      closeDrawer(getRow(cancelBtn));
      return;
    }

    const saveBtn = ev.target.closest && ev.target.closest('.settings-assignment-save');
    if (saveBtn) {
      ev.preventDefault();
      saveAssignment(getRow(saveBtn));
      return;
    }

    const unassignBtn = ev.target.closest && ev.target.closest('.settings-assignment-unassign');
    if (unassignBtn) {
      ev.preventDefault();
      unassignAssignment(getRow(unassignBtn));
      return;
    }

    const autoAssignBtn = ev.target.closest && ev.target.closest('[data-auto-assign-open]');
    if (autoAssignBtn) {
      ev.preventDefault();
      autoAssignOpen();
      return;
    }

    const clearAssignmentsBtn = ev.target.closest && ev.target.closest('[data-auto-assign-clear]');
    if (clearAssignmentsBtn) {
      ev.preventDefault();
      clearAssignedInScope();
      return;
    }

    const policyPresetBtn = ev.target.closest && ev.target.closest('[data-auto-assign-preset]');
    if (policyPresetBtn) {
      ev.preventDefault();
      const presetKey = String(policyPresetBtn.getAttribute('data-auto-assign-preset') || '');
      if (presetKey) {
        applyPolicyPreset(presetKey);
      }
      return;
    }

    const deptAssignBtn = ev.target.closest && ev.target.closest('[data-dept-assign-open]');
    if (deptAssignBtn) {
      ev.preventDefault();
      const deptId = parseInt(String(deptAssignBtn.getAttribute('data-dept-id') || '0'), 10) || 0;
      const deptName = String(deptAssignBtn.getAttribute('data-dept-name') || tr('Department', 'Departement', 'Dipartimento'));
      const deptShiftIds = getDeptShiftIds(deptAssignBtn);
      autoAssignForDepartment(deptId, deptName, deptShiftIds);
      return;
    }

    const deptClearBtn = ev.target.closest && ev.target.closest('[data-dept-assign-clear]');
    if (deptClearBtn) {
      ev.preventDefault();
      const deptId = parseInt(String(deptClearBtn.getAttribute('data-dept-id') || '0'), 10) || 0;
      const deptName = String(deptClearBtn.getAttribute('data-dept-name') || tr('Department', 'Departement', 'Dipartimento'));
      const deptShiftIds = getDeptShiftIds(deptClearBtn);
      clearAssignmentsForDepartment(deptId, deptName, deptShiftIds);
      return;
    }

    const priorityDeptBtn = ev.target.closest && ev.target.closest('[data-auto-assign-priority-department-id]');
    if (priorityDeptBtn && globalAutoAssignPriorityList) {
      ev.preventDefault();
      Array.from(globalAutoAssignPriorityList.querySelectorAll('[data-auto-assign-priority-department-id]')).forEach((button) => {
        const isActive = button === priorityDeptBtn;
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      refreshGlobalForecast();
      return;
    }

    const autoAssignPreviewCancelBtn = ev.target.closest && ev.target.closest('[data-auto-assign-preview-cancel]');
    if (autoAssignPreviewCancelBtn) {
      ev.preventDefault();
      isRunningConfirmedAutoAssign = false;
      closeAutoAssignPreviewModal();
      return;
    }

    const autoAssignPreviewConfirmBtn = ev.target.closest && ev.target.closest('[data-auto-assign-preview-confirm]');
    if (autoAssignPreviewConfirmBtn) {
      ev.preventDefault();
      isRunningConfirmedAutoAssign = true;
      autoAssignOpen();
      return;
    }

    const autoAssignEmployeeBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-auto-assign]');
    if (autoAssignEmployeeBtn) {
      ev.preventDefault();
      autoAssignForActiveEmployee();
      return;
    }

    const clearEmployeeAssignmentsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-clear-assigned]');
    if (clearEmployeeAssignmentsBtn) {
      ev.preventDefault();
      clearAssignmentsForActiveEmployee();
      return;
    }

    const rotateToggleBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-rotate-toggle]');
    if (rotateToggleBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      toggleRotation();
      return;
    }

    const saveMonthOverrideBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-save-month-override]');
    if (saveMonthOverrideBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      saveMonthWeekdayOverride();
      return;
    }

    const clearMonthOverrideBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-clear-month-override]');
    if (clearMonthOverrideBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      clearMonthWeekdayOverride();
      return;
    }

    const openAssignedShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-shifts]');
    if (openAssignedShiftsBtn) {
      ev.preventDefault();
      openAssignedShiftsModal();
      return;
    }

    const closeAssignedShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-shifts-close]');
    if (closeAssignedShiftsBtn) {
      ev.preventDefault();
      closeAssignedShiftsModal();
      return;
    }

    const selectAllAssignedShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-shifts-select-all]');
    if (selectAllAssignedShiftsBtn) {
      ev.preventDefault();
      setAllEmployeeShiftSelection(true);
      return;
    }

    const clearAssignedShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-shifts-clear-selection]');
    if (clearAssignedShiftsBtn) {
      ev.preventDefault();
      setAllEmployeeShiftSelection(false);
      return;
    }

    const unassignSelectedShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-shifts-unassign-selected]');
    if (unassignSelectedShiftsBtn) {
      ev.preventDefault();
      unassignEmployeeShiftsBulk(false);
      return;
    }

    const unassignAllShiftsBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-shifts-unassign-all]');
    if (unassignAllShiftsBtn) {
      ev.preventDefault();
      setAllEmployeeShiftSelection(true);
      unassignEmployeeShiftsBulk(true);
      return;
    }

    const reassignEmployeeBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-reassign]');
    if (reassignEmployeeBtn) {
      ev.preventDefault();
      reassignForActiveEmployee();
      return;
    }

    const assignmentListToggleBtn = ev.target.closest && ev.target.closest('[data-assignment-list-toggle]');
    if (assignmentListToggleBtn) {
      ev.preventDefault();
      const wrap = getAssignmentsListWrap();
      setAssignmentsListVisible(!!wrap?.hidden);
      return;
    }

    const openEmployeeBtn = ev.target.closest && ev.target.closest('[data-assignment-employee-open]');
    if (openEmployeeBtn) {
      ev.preventDefault();
      openEmployeeModal(
        openEmployeeBtn.getAttribute('data-user-id'),
        openEmployeeBtn.getAttribute('data-user-name'),
        openEmployeeBtn.getAttribute('data-user-department-id'),
        openEmployeeBtn.getAttribute('data-user-department-name'),
      );
      return;
    }

    const closeEmployeeBtn = ev.target.closest && ev.target.closest('[data-assignment-employee-close]');
    if (closeEmployeeBtn) {
      ev.preventDefault();
      closeEmployeeModal();
      return;
    }

    if (employeeModal && ev.target === employeeModal) {
      closeEmployeeModal();
      return;
    }

    if (employeeModalShiftsModal && ev.target === employeeModalShiftsModal) {
      closeAssignedShiftsModal();
      return;
    }

    const modalRemoveSpecialBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-remove-special]');
    if (modalRemoveSpecialBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      const dateToRemove = modalRemoveSpecialBtn.getAttribute('data-assignment-modal-remove-special') || '';
      const currentRule = getRuleForUser(activeEmployeeUserId);
      currentRule.special_dates = (currentRule.special_dates || []).filter((item) => String(item?.date || '') !== dateToRemove);
      setRuleForUser(activeEmployeeUserId, currentRule);
      openEmployeeModal(activeEmployeeUserId, activeEmployeeUserName, activeEmployeeDepartmentId, activeEmployeeDepartmentName);
      return;
    }

    const modalAddSpecialBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-add-special]');
    if (modalAddSpecialBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      const dateValue = (employeeModalSpecialDate?.value || '').trim();
      const reasonValue = (employeeModalSpecialReason?.value || 'special').trim() || 'special';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
        notifyError('Choose a valid unavailable date.');
        return;
      }
      const currentRule = getRuleForUser(activeEmployeeUserId);
      if ((currentRule.special_dates || []).some((item) => String(item?.date || '') === dateValue)) {
        notifyError('This unavailable date already exists for the employee.');
        return;
      }
      currentRule.special_dates = (currentRule.special_dates || []).concat([{ date: dateValue, reason: reasonValue }]);
      setRuleForUser(activeEmployeeUserId, currentRule);
      if (employeeModalSpecialDate) {
        employeeModalSpecialDate.value = '';
      }
      openEmployeeModal(activeEmployeeUserId, activeEmployeeUserName, activeEmployeeDepartmentId, activeEmployeeDepartmentName);
      return;
    }

    const modalAddSpecialRangeBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-add-special-range]');
    if (modalAddSpecialRangeBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      addUnavailableRangeForActiveEmployee();
      return;
    }

    const modalRulesResetBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-rules-reset]');
    if (modalRulesResetBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      resetRulesForActiveEmployee();
      return;
    }

    const weekDayToggleBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-week-day]');
    if (weekDayToggleBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      const dateValue = normalizeDateKey(weekDayToggleBtn.getAttribute('data-assignment-modal-week-day') || '');
      if (!dateValue) return;

      const selectedReason = String(employeeModalSpecialReason?.value || 'rest').trim() || 'rest';
      const currentRule = getRuleForUser(activeEmployeeUserId);
      const specialDates = Array.isArray(currentRule.special_dates) ? currentRule.special_dates.slice() : [];
      const existingIndex = specialDates.findIndex((item) => String(item?.date || '') === dateValue);

      if (existingIndex >= 0) {
        specialDates.splice(existingIndex, 1);
      } else {
        specialDates.push({ date: dateValue, reason: selectedReason });
      }

      currentRule.special_dates = specialDates.sort((a, b) => String(a?.date || '').localeCompare(String(b?.date || '')));
      setRuleForUser(activeEmployeeUserId, currentRule);
      openEmployeeModal(activeEmployeeUserId, activeEmployeeUserName, activeEmployeeDepartmentId, activeEmployeeDepartmentName);
      return;
    }

    const openSlotToggle = ev.target.closest && ev.target.closest('[data-assignment-modal-open-toggle]');
    if (openSlotToggle && activeEmployeeUserId > 0) {
      ev.preventDefault();
      const key = openSlotToggle.getAttribute('data-assignment-modal-open-toggle') || '';
      if (!key) return;
      if (selectedOpenSlotKeys.has(key)) {
        selectedOpenSlotKeys.delete(key);
      } else {
        selectedOpenSlotKeys.add(key);
      }
      renderOpenSlotSelection();
      return;
    }

    const openSlotClearBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-clear]');
    if (openSlotClearBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      clearOpenSlotSelection();
      return;
    }

    const openSlotReselectBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-reselect]');
    if (openSlotReselectBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      reselectionOpenSlots();
      return;
    }

    const openSlotAssignBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-assign]');
    if (openSlotAssignBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      assignSelectedOpenSlots();
      return;
    }

    const openSlotCoverAllBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-open-cover-all]');
    if (openSlotCoverAllBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      reselectionOpenSlots();
      assignSelectedOpenSlots();
      return;
    }

    const absenceAssignBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-absence-assign]');
    if (absenceAssignBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      assignAbsenceRange();
      return;
    }

    const absenceResetBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-absence-reset]');
    if (absenceResetBtn && activeEmployeeUserId > 0) {
      ev.preventDefault();
      syncAbsenceRangeDefaults();
      return;
    }

    const modalEditShiftBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-edit]');
    if (modalEditShiftBtn) {
      ev.preventDefault();
      const assignmentId = modalEditShiftBtn.getAttribute('data-assignment-modal-edit') || '';
      const row = document.querySelector(`[data-assignment-id="${assignmentId}"]`);
      if (row) {
        setAssignmentsListVisible(true);
        openDrawer(row);
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      closeEmployeeModal();
      return;
    }

    const modalUnassignShiftBtn = ev.target.closest && ev.target.closest('[data-assignment-modal-unassign]');
    if (modalUnassignShiftBtn) {
      ev.preventDefault();
      const assignmentId = modalUnassignShiftBtn.getAttribute('data-assignment-modal-unassign') || '';
      const row = document.querySelector(`[data-assignment-id="${assignmentId}"]`);
      if (row) {
        unassignAssignment(row);
      }
    }
  });

  document.addEventListener('change', (ev) => {
    const plannerTarget = ev.target;
    if (plannerTarget.matches('[data-assignment-planner-scope]')) {
      syncAssignmentPlannerScopeUi();
      return;
    }

    if (plannerTarget.matches('[data-assignment-planner-range-start], [data-assignment-planner-range-end]')) {
      const start = normalizeDateKey(assignmentPlannerRangeStart?.value || '');
      const end = normalizeDateKey(assignmentPlannerRangeEnd?.value || '');
      if (start && end && start > end) {
        if (assignmentPlannerRangeEnd) {
          assignmentPlannerRangeEnd.value = start;
        }
      }
      return;
    }

    if (!isAssignmentsPanelActive()) return;
    const target = ev.target;
    if (target.matches('[data-assignment-modal-shift-select]')) {
      syncEmployeeShiftSelectionFromDom();
      return;
    }
    if (target.matches('select[data-field="shift_id"]')) {
      syncAssignmentShiftPreview(target.closest('.settings-edit-drawer'));
      return;
    }
    if (
      target.matches('[data-auto-assign-shift-id]')
      || target.matches('[data-auto-assign-range-start]')
      || target.matches('[data-auto-assign-range-end]')
      || target.matches('[data-auto-assign-min-employees]')
      || target.matches('[data-auto-assign-max-employees]')
      || target.matches('[data-auto-assign-min-rest-days]')
      || target.matches('[data-auto-assign-max-rest-days]')
      || target.matches('[data-auto-assign-min-work-days]')
      || target.matches('[data-auto-assign-max-work-days]')
      || target.matches('[data-auto-assign-rest-strategy]')
      || target.matches('[data-auto-assign-priority-strict]')
    ) {
      updatePolicyPresetUi(resolveMatchingPolicyPreset());
      refreshGlobalForecast();
      return;
    }
    if (
      target.matches('[data-auto-rule-scope]')
      || target.matches('[data-auto-rule-weekday]')
      || target.matches('[data-auto-rule-special-reason]')
    ) {
      persistRulesFromRows();
      return;
    }

    if (target.matches('[data-assignment-modal-weekday]') && activeEmployeeUserId > 0) {
      const checked = Array.from(employeeModalWeekdays?.querySelectorAll('[data-assignment-modal-weekday]:checked') || [])
        .map((checkbox) => parseInt(checkbox.getAttribute('data-assignment-modal-weekday') || '-1', 10))
        .filter((value) => value >= 0 && value <= 6);
      const currentRule = getRuleForUser(activeEmployeeUserId);
      currentRule.off_weekdays = Array.from(new Set(checked)).sort();
      setRuleForUser(activeEmployeeUserId, currentRule);
      renderEmployeeWeeklyAvailability(currentRule);
      renderSelectedMonthUnavailable(currentRule);
      renderOpenSlotSelection();
      return;
    }

    if (target.matches('[data-assignment-modal-rules-month]') && activeEmployeeUserId > 0) {
      syncRuleRangeDefaults();
      const currentRule = getRuleForUser(activeEmployeeUserId);
      renderMonthWeekdaysForSelectedMonth(currentRule);
      renderEmployeeWeeklyAvailability(currentRule);
      renderSelectedMonthUnavailable(currentRule);
      return;
    }

    if (target.matches('[data-assignment-modal-period-mode]') || target.matches('[data-assignment-modal-period-month]')) {
      if (activeEmployeeUserId > 0) {
        syncEmployeePeriodInputs();
        selectedOpenSlotKeys = new Set();
        renderOpenSlotSelection();
        refreshEmployeeAssignedShifts();
      }
      return;
    }

    if (target.matches('[data-assignment-modal-shift-mode]') && activeEmployeeUserId > 0) {
      populateOpenShiftFilter();
      selectedOpenSlotKeys = new Set();
      renderOpenSlotSelection();
      return;
    }

    if (target.matches('[data-assignment-modal-open-shift-id]') && activeEmployeeUserId > 0) {
      const shiftId = parseInt(String(target.getAttribute('data-assignment-modal-open-shift-id') || '0'), 10) || 0;
      if (shiftId > 0) {
        if (target.checked) {
          selectedOpenShiftFilterIds.add(shiftId);
        } else {
          selectedOpenShiftFilterIds.delete(shiftId);
        }
      }
      selectedOpenSlotKeys = new Set();
      renderOpenSlotSelection();
      return;
    }

    if (target.matches('[data-assignment-modal-open-shift]') && activeEmployeeUserId > 0) {
      selectedOpenSlotKeys = new Set();
      renderOpenSlotSelection();
      return;
    }

    if ((target.matches('[data-assignment-modal-open-from]') || target.matches('[data-assignment-modal-open-to]')) && activeEmployeeUserId > 0) {
      const openFrom = getEmployeeModalField('[data-assignment-modal-open-from]');
      const openTo = getEmployeeModalField('[data-assignment-modal-open-to]');
      if (normalizeDateKey(openFrom?.value || '') && normalizeDateKey(openTo?.value || '') && openFrom.value > openTo.value) {
        const tempValue = openFrom.value;
        openFrom.value = openTo.value;
        openTo.value = tempValue;
      }
      selectedOpenSlotKeys = new Set();
      renderOpenSlotSelection();
    }

    if ((target.matches('[data-assignment-modal-special-from]') || target.matches('[data-assignment-modal-special-to]') || target.matches('[data-assignment-modal-absence-from]') || target.matches('[data-assignment-modal-absence-to]')) && activeEmployeeUserId > 0) {
      const specialRange = normalizeDateRange(employeeModalSpecialFrom?.value || '', employeeModalSpecialTo?.value || '');
      if (specialRange) {
        if (employeeModalSpecialFrom) employeeModalSpecialFrom.value = specialRange.start;
        if (employeeModalSpecialTo) employeeModalSpecialTo.value = specialRange.end;
      }
      const absenceRange = normalizeDateRange(employeeModalAbsenceFrom?.value || '', employeeModalAbsenceTo?.value || '');
      if (absenceRange) {
        if (employeeModalAbsenceFrom) employeeModalAbsenceFrom.value = absenceRange.start;
        if (employeeModalAbsenceTo) employeeModalAbsenceTo.value = absenceRange.end;
      }
    }
  });

  document.addEventListener('dashboard:planner-updated', () => {
    refreshAssignmentEmployeeIndexStats();
    updatePolicyPresetUi(resolveMatchingPolicyPreset());
    refreshGlobalForecast();
  });

  updatePolicyPresetUi(resolveMatchingPolicyPreset());
  refreshAssignmentEmployeeIndexStats();
  refreshGlobalForecast();
  syncAssignmentPlannerScopeUi();
  applyRulesToRows(loadRules());
})();
