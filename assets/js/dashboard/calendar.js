/**
 * Dashboard calendar interactions module.
 *
 * Handles user interactions with the calendar, including clicking on date cards
 * to open the date view and clicking on assignment cards to open the associated
 * date. Relies on custom data attributes for identifying interactive elements and
 * communicates with the main dashboard logic via provided callback functions.
 */

(function(){
  var feedback = window.DashboardFeedback;
  var locale = String(document.documentElement.getAttribute('lang') || 'en').toLowerCase();
  var isFr = locale.indexOf('fr') === 0;
  var isIt = locale.indexOf('it') === 0;
  var iconsBase = String((window.DashboardConfig && window.DashboardConfig.iconsBase) || '/assets/icons/');
  function tr(enText, frText, itText) {
    return isFr ? frText : (isIt && itText ? itText : enText);
  }

  function notifyError(message) {
    if (feedback && typeof feedback.error === 'function') {
      feedback.error(tr('Oops!', 'Erreur', 'Errore'), message);
      return;
    }
    console.error(message);
  }

  function notifySuccess(message) {
    if (feedback && typeof feedback.success === 'function') {
      feedback.success(tr('Done', 'Termine', 'Fatto'), message);
      return;
    }
  }

  function createBulkAssignModal(assignShift, events, isUserAvailableForDate) {
    var state = {
      assignment: null,
      dates: [],
    };

    var host = document.createElement('div');
    host.className = 'calendar-bulk-assign-overlay';
    host.hidden = true;
    host.innerHTML = '\n      <section class="calendar-bulk-assign-modal" role="dialog" aria-modal="true" aria-label="Assign employee to other dates">\n        <header class="calendar-bulk-assign-head">\n          <h3>Assign Employee to Other Dates</h3>\n          <button type="button" class="calendar-bulk-assign-close" data-calendar-bulk-close aria-label="Close">×</button>\n        </header>\n        <div class="calendar-bulk-assign-employee">\n          <span class="calendar-bulk-assign-label">Employee</span>\n          <strong data-calendar-bulk-employee-name></strong>\n        </div>\n        <p class="calendar-bulk-assign-meta" data-calendar-bulk-meta></p>\n        <div class="calendar-bulk-assign-picker">\n          <input type="date" data-calendar-bulk-date-input>\n          <button type="button" class="calendar-bulk-assign-btn" data-calendar-bulk-add-date>Add date</button>\n        </div>\n        <div class="calendar-bulk-assign-range">\n          <input type="date" data-calendar-bulk-range-start>\n          <input type="date" data-calendar-bulk-range-end>\n          <button type="button" class="calendar-bulk-assign-btn is-secondary" data-calendar-bulk-add-range>Add range</button>\n        </div>\n        <div class="calendar-bulk-assign-available">\n          <div class="calendar-bulk-assign-available-head">\n            <span data-calendar-bulk-available-count>Available dates this month: 0</span>\n            <button type="button" class="calendar-bulk-assign-btn is-secondary" data-calendar-bulk-add-available>Add all available</button>\n          </div>\n          <div class="calendar-bulk-assign-available-list" data-calendar-bulk-available-list></div>\n        </div>\n        <div class="calendar-bulk-assign-dates" data-calendar-bulk-dates></div>\n        <footer class="calendar-bulk-assign-actions">\n          <button type="button" class="calendar-bulk-assign-btn" data-calendar-bulk-auto-assign-available>Auto assign available dates</button>\n          <button type="button" class="calendar-bulk-assign-btn" data-calendar-bulk-apply>Assign selected dates</button>\n        </footer>\n      </section>\n    ';

    document.body.appendChild(host);

    var meta = host.querySelector('[data-calendar-bulk-meta]');
    var employeeName = host.querySelector('[data-calendar-bulk-employee-name]');
    var dateInput = host.querySelector('[data-calendar-bulk-date-input]');
    var rangeStartInput = host.querySelector('[data-calendar-bulk-range-start]');
    var rangeEndInput = host.querySelector('[data-calendar-bulk-range-end]');
    var datesWrap = host.querySelector('[data-calendar-bulk-dates]');
    var availableCount = host.querySelector('[data-calendar-bulk-available-count]');
    var availableList = host.querySelector('[data-calendar-bulk-available-list]');
    var addDateButton = host.querySelector('[data-calendar-bulk-add-date]');
    var addRangeButton = host.querySelector('[data-calendar-bulk-add-range]');
    var addAvailableButton = host.querySelector('[data-calendar-bulk-add-available]');
    var autoAssignAvailableButton = host.querySelector('[data-calendar-bulk-auto-assign-available]');
    var applyButton = host.querySelector('[data-calendar-bulk-apply]');
    var closeButtons = host.querySelectorAll('[data-calendar-bulk-close]');
    var modalPanel = host.querySelector('.calendar-bulk-assign-modal');

    function toLocalDate(value) {
      if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
      var parsed = new Date(value + 'T12:00:00');
      return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function toDateKey(date) {
      var year = String(date.getFullYear());
      var month = String(date.getMonth() + 1).padStart(2, '0');
      var day = String(date.getDate()).padStart(2, '0');
      return year + '-' + month + '-' + day;
    }

    function isDateAlreadyAssigned(dateValue) {
      if (!state.assignment) return false;
      return (events || []).some(function (item) {
        return Number(item.user_id || 0) === Number(state.assignment.user_id || 0)
          && Number(item.shift_id || 0) === Number(state.assignment.shift_id || 0)
          && String(item.work_date || '') === String(dateValue || '')
          && Number(item.assignment_id || 0) !== Number(state.assignment.assignment_id || 0)
          && Number(item.user_id || 0) > 0;
      });
    }

    function getAvailableDatesInMonth() {
      if (!state.assignment) return [];
      var currentWorkDate = String(state.assignment.work_date || '');
      if (!/^\d{4}-\d{2}-\d{2}$/.test(currentWorkDate)) return [];
      var monthPrefix = currentWorkDate.slice(0, 7);
      var shiftId = Number(state.assignment.shift_id || 0);
      var unique = new Set();

      (events || []).forEach(function (item) {
        var dateValue = String(item.work_date || '');
        if (!dateValue.startsWith(monthPrefix)) return;
        if (Number(item.shift_id || 0) !== shiftId) return;
        if (Number(item.user_id || 0) > 0) return;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) return;
        if (typeof isUserAvailableForDate === 'function' && !isUserAvailableForDate(Number(state.assignment.user_id || 0), dateValue)) return;
        if (isDateAlreadyAssigned(dateValue)) return;
        unique.add(dateValue);
      });

      return Array.from(unique).sort();
    }

    function renderAvailableDates() {
      if (!availableCount || !availableList) return;
      var dates = getAvailableDatesInMonth();
      availableCount.textContent = 'Available dates this month: ' + dates.length;
      if (!dates.length) {
        availableList.innerHTML = '<p class="calendar-bulk-assign-empty">No open dates available for this shift in the same month.</p>';
        return;
      }
      availableList.innerHTML = dates.map(function (dateValue) {
        return '<button type="button" class="calendar-bulk-assign-chip is-clickable" data-calendar-bulk-pick-available="' + dateValue + '">' + dateValue + '</button>';
      }).join('');
    }

    function renderDates() {
      if (!datesWrap) return;
      if (!state.dates.length) {
        datesWrap.innerHTML = '<p class="calendar-bulk-assign-empty">No dates selected yet.</p>';
        return;
      }

      datesWrap.innerHTML = state.dates.map(function (dateValue) {
        return '<span class="calendar-bulk-assign-chip">' + dateValue + '<button type="button" data-calendar-bulk-remove-date="' + dateValue + '" aria-label="Remove date">×</button></span>';
      }).join('');
    }

    function closeModal() {
      host.hidden = true;
      state.assignment = null;
      state.dates = [];
      if (dateInput) {
        dateInput.value = '';
      }
      if (rangeStartInput) {
        rangeStartInput.value = '';
      }
      if (rangeEndInput) {
        rangeEndInput.value = '';
      }
      renderDates();
      renderAvailableDates();
    }

    function openModal(assignment) {
      state.assignment = assignment;
      state.dates = [];
      if (meta) {
        var userName = (assignment.user_name || 'Employee').trim();
        if (!userName) {
          userName = Number(assignment.user_id || 0) > 0 ? ('Employee #' + Number(assignment.user_id || 0)) : 'Employee';
        }
        var shiftName = assignment.shift_name || 'Shift';
        meta.textContent = 'Shift: ' + shiftName;
        if (employeeName) {
          employeeName.textContent = userName || 'Employee';
        }
      }
      if (dateInput) {
        dateInput.value = '';
      }
      renderDates();
      renderAvailableDates();
      host.hidden = false;
    }

    function pushDate(value) {
      if (!value) return;
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        notifyError('Use date format YYYY-MM-DD.');
        return;
      }
      if (typeof isUserAvailableForDate === 'function' && !isUserAvailableForDate(Number(state.assignment?.user_id || 0), value)) {
        notifyError('This employee is unavailable on ' + value + '.');
        return;
      }
      if (isDateAlreadyAssigned(value)) {
        notifyError('This employee is already assigned to the same shift on ' + value + '.');
        return;
      }
      if (!state.dates.includes(value)) {
        state.dates.push(value);
        state.dates.sort();
        renderDates();
      }
    }

    function pushRange(startValue, endValue) {
      var startDate = toLocalDate(startValue);
      var endDate = toLocalDate(endValue);
      if (!startDate || !endDate) {
        notifyError('Set a valid start and end date.');
        return;
      }
      if (endDate < startDate) {
        notifyError('Range end date must be after start date.');
        return;
      }

      var duplicateCount = 0;
      for (var cursor = new Date(startDate); cursor <= endDate; cursor.setDate(cursor.getDate() + 1)) {
        var key = toDateKey(cursor);
        if (isDateAlreadyAssigned(key)) {
          duplicateCount += 1;
          continue;
        }
        if (!state.dates.includes(key)) {
          state.dates.push(key);
        }
      }

      state.dates.sort();
      renderDates();
      if (duplicateCount > 0) {
        notifyError('Skipped ' + duplicateCount + ' already assigned date(s).');
      }
    }

    if (addDateButton) {
      addDateButton.addEventListener('click', function () {
        pushDate(dateInput ? dateInput.value : '');
        if (dateInput) {
          dateInput.value = '';
        }
      });
    }

    if (addRangeButton) {
      addRangeButton.addEventListener('click', function () {
        pushRange(rangeStartInput ? rangeStartInput.value : '', rangeEndInput ? rangeEndInput.value : '');
      });
    }

    if (addAvailableButton) {
      addAvailableButton.addEventListener('click', function () {
        getAvailableDatesInMonth().forEach(function (dateValue) {
          pushDate(dateValue);
        });
      });
    }

    if (dateInput) {
      dateInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          pushDate(dateInput.value);
          dateInput.value = '';
        }
      });
    }

    if (modalPanel) {
      modalPanel.addEventListener('click', function (event) {
        event.stopPropagation();
      });
    }

    closeButtons.forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        closeModal();
      });
    });

    host.addEventListener('click', function (event) {
      if (event.target === host) {
        closeModal();
        return;
      }

      var removeButton = event.target.closest('[data-calendar-bulk-remove-date]');
      if (removeButton) {
        var dateValue = removeButton.getAttribute('data-calendar-bulk-remove-date') || '';
        state.dates = state.dates.filter(function (item) { return item !== dateValue; });
        renderDates();
        return;
      }

      var availablePick = event.target.closest('[data-calendar-bulk-pick-available]');
      if (availablePick) {
        var availableDate = availablePick.getAttribute('data-calendar-bulk-pick-available') || '';
        pushDate(availableDate);
      }
    });

    if (autoAssignAvailableButton) {
      autoAssignAvailableButton.addEventListener('click', function () {
        if (!state.assignment || typeof assignShift !== 'function') {
          notifyError('Missing assignment context.');
          return;
        }

        var availableDates = getAvailableDatesInMonth();
        if (!availableDates.length) {
          notifyError('No available dates in this month for auto assignment.');
          return;
        }

        (async function () {
          var successCount = 0;
          for (var index = 0; index < availableDates.length; index += 1) {
            try {
              await assignShift({
                user_id: Number(state.assignment.user_id || 0),
                shift_id: Number(state.assignment.shift_id || 0),
                work_date: availableDates[index],
                status: 'assigned',
              });
              successCount += 1;
            } catch (error) {
              // Continue with other dates even if one assignment fails.
            }
          }

          if (successCount > 0) {
            notifySuccess('Assigned ' + successCount + ' available date(s).');
            closeModal();
            return;
          }
          notifyError('Unable to auto assign available dates.');
        })();
      });
    }

    if (applyButton) {
      applyButton.addEventListener('click', function () {
      if (!state.assignment || !state.dates.length || typeof assignShift !== 'function') {
        notifyError('Select at least one date.');
        return;
      }

      (async function () {
        try {
          for (var index = 0; index < state.dates.length; index += 1) {
            await assignShift({
              user_id: Number(state.assignment.user_id || 0),
              shift_id: Number(state.assignment.shift_id || 0),
              work_date: state.dates[index],
              status: 'assigned',
            });
          }
          notifySuccess('Employee assigned to selected dates.');
          closeModal();
        } catch (error) {
          notifyError((error && error.message) || 'Unable to assign employee to selected dates.');
        }
      })();
      });
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !host.hidden) {
        closeModal();
      }
    });

    return {
      open: openModal,
    };
  }

  function createDayFullscreenModal() {
    return {
      open: function () {},
      close: function () {},
    };
  }

  function initCalendarInteractions(options) {
    var calendarShell = options.calendarShell;
    var events = options.events || [];
    var state = options.state || {};
    var toLocalDate = options.toLocalDate;
    var openDate = options.openDate;
    var unassignAssignment = options.unassignAssignment;
    var assignShift = options.assignShift;
    var isUserAvailableForDate = options.isUserAvailableForDate;
    var getUserAvailabilityStatus = options.getUserAvailabilityStatus;
    var getActiveShift = options.getActiveShift;
    var getAbsenceTemplateShiftId = options.getAbsenceTemplateShiftId;
    var getAbsenceTemplateShift = options.getAbsenceTemplateShift;
    var bulkAssignModal = createBulkAssignModal(assignShift, events, isUserAvailableForDate);
    var badgeActionState = {
      assignmentId: 0,
      userId: 0,
      workDate: '',
      anchorKey: '',
      actionHandled: false,
    };

    function resolveTemplateIconPath(kind, fallbackIconFile) {
      var templateShift = typeof getAbsenceTemplateShift === 'function' ? (getAbsenceTemplateShift(kind) || null) : null;
      var rawIcon = String((templateShift && (templateShift.icon || templateShift.shift_icon)) || '').trim();
      if (!rawIcon) return iconsBase + fallbackIconFile;
      if (rawIcon.indexOf('data:') === 0 || rawIcon.indexOf('http://') === 0 || rawIcon.indexOf('https://') === 0 || rawIcon.indexOf('/') === 0) {
        return rawIcon;
      }
      return iconsBase + rawIcon.replace(/^\/+/, '');
    }

    function renderBadgeActionsMenu() {
      var restIconPath = resolveTemplateIconPath('rest', 'moon.svg');
      var vacationIconPath = resolveTemplateIconPath('vacation', 'parasol.svg');
      var sickIconPath = resolveTemplateIconPath('sick', 'stethoscope.svg');
      badgeActionMenu.innerHTML = [
        '<button type="button" class="calendar-badge-actions-btn" data-calendar-badge-action="rest"><img class="calendar-badge-actions-icon" src="' + restIconPath + '" alt="" aria-hidden="true"> ' + tr('Rest', 'Repos', 'Riposo') + '</button>',
        '<button type="button" class="calendar-badge-actions-btn" data-calendar-badge-action="vacation"><img class="calendar-badge-actions-icon" src="' + vacationIconPath + '" alt="" aria-hidden="true"> ' + tr('Vacation', 'Vacances', 'Vacanze') + '</button>',
        '<button type="button" class="calendar-badge-actions-btn" data-calendar-badge-action="sick"><img class="calendar-badge-actions-icon" src="' + sickIconPath + '" alt="" aria-hidden="true"> ' + tr('Sick', 'Maladie', 'Malattia') + '</button>',
        '<button type="button" class="calendar-badge-actions-btn is-danger" data-calendar-badge-action="unassign"><img class="calendar-badge-actions-icon" src="' + iconsBase + 'x.svg" alt="" aria-hidden="true"> ' + tr('Unassign', 'Desassigner', 'Disassegna') + '</button>',
        '<button type="button" class="calendar-badge-actions-btn" data-calendar-badge-action="cancel">' + tr('Cancel', 'Annuler', 'Annulla') + '</button>'
      ].join('');
    }

    var badgeActionMenu = document.createElement('div');
    badgeActionMenu.className = 'calendar-badge-actions-menu';
    badgeActionMenu.hidden = true;
    renderBadgeActionsMenu();
    document.body.appendChild(badgeActionMenu);

    function closeBadgeActionMenu() {
      badgeActionMenu.hidden = true;
      badgeActionState.assignmentId = 0;
      badgeActionState.userId = 0;
      badgeActionState.workDate = '';
      badgeActionState.anchorKey = '';
    }

    function openBadgeActionMenu(anchorNode, context, triggerEvent) {
      var targetAnchorKey = String(context.assignmentId || 0) + '|' + String(context.userId || 0) + '|' + String(context.workDate || '');
      if (!badgeActionMenu.hidden && badgeActionState.anchorKey === targetAnchorKey) {
        closeBadgeActionMenu();
        return;
      }

      renderBadgeActionsMenu();

      var rect = anchorNode && typeof anchorNode.getBoundingClientRect === 'function'
        ? anchorNode.getBoundingClientRect()
        : null;
      if (rect && rect.left === 0 && rect.top === 0 && rect.width === 0 && rect.height === 0) {
        rect = null;
      }

      if (!rect && triggerEvent && triggerEvent.target && typeof triggerEvent.target.closest === 'function') {
        var fallbackAnchor = triggerEvent.target.closest('.calendar-event-slot-card[data-calendar-slot-toggle], .calendar-event-user-badge, [data-calendar-slot-toggle]');
        if (fallbackAnchor && typeof fallbackAnchor.getBoundingClientRect === 'function') {
          rect = fallbackAnchor.getBoundingClientRect();
        }
      }

      var eventClientX = Number(triggerEvent && triggerEvent.clientX || 0);
      var eventClientY = Number(triggerEvent && triggerEvent.clientY || 0);
      if (rect && eventClientX > 20 && eventClientY > 20 && rect.left <= 2 && rect.top <= 2) {
        rect = null;
      }

      var top = rect ? (rect.bottom + 6) : (eventClientY + 10);
      var left = rect ? rect.left : (eventClientX + 10);

      badgeActionMenu.hidden = false;

      var viewportWidth = Math.max(
        Number(window.innerWidth || 0),
        Number((window.visualViewport && window.visualViewport.width) || 0),
        Number(document.documentElement && document.documentElement.clientWidth || 0),
        Number(document.body && document.body.clientWidth || 0)
      );
      var viewportHeight = Math.max(
        Number(window.innerHeight || 0),
        Number((window.visualViewport && window.visualViewport.height) || 0),
        Number(document.documentElement && document.documentElement.clientHeight || 0),
        Number(document.body && document.body.clientHeight || 0)
      );
      var menuWidth = Number(badgeActionMenu.offsetWidth || 170);
      var menuHeight = Number(badgeActionMenu.offsetHeight || 130);
      var boundary = 8;

      if (viewportWidth > 0 && left + menuWidth > viewportWidth - boundary) {
        left = Math.max(boundary, viewportWidth - menuWidth - boundary);
      }
      if (viewportHeight > 0 && top + menuHeight > viewportHeight - boundary) {
        var aboveTop = rect ? (rect.top - menuHeight - 6) : (viewportHeight - menuHeight - boundary);
        top = Math.max(boundary, aboveTop);
      }

      top = Math.max(boundary, top);
      left = Math.max(boundary, left);
      badgeActionMenu.style.top = top + 'px';
      badgeActionMenu.style.left = left + 'px';

      badgeActionState.assignmentId = Number(context.assignmentId || 0);
      badgeActionState.userId = Number(context.userId || 0);
      badgeActionState.workDate = String(context.workDate || '');
      badgeActionState.anchorKey = targetAnchorKey;
      badgeActionState.actionHandled = false;
    }

    function handleBadgeAction(event) {
      var actionButton = event.target.closest('[data-calendar-badge-action]');
      if (!actionButton) return;
      event.preventDefault();
      event.stopPropagation();

      if (badgeActionState.actionHandled) return;
      badgeActionState.actionHandled = true;

      var action = String(actionButton.getAttribute('data-calendar-badge-action') || '').toLowerCase();
      var assignmentId = Number(badgeActionState.assignmentId || 0);
      var userId = Number(badgeActionState.userId || 0);
      var workDate = String(badgeActionState.workDate || '');

      if (action === 'cancel') {
        closeBadgeActionMenu();
        return;
      }

      // Close immediately so the popup never stays stuck on screen.
      closeBadgeActionMenu();

      if (!workDate || isPastDateKey(workDate)) {
        notifyError('Past days are locked and cannot be edited.');
        return;
      }

      if (action === 'unassign') {
        if (!assignmentId || typeof unassignAssignment !== 'function') {
          notifyError('Missing unassign context.');
          return;
        }
        (async function () {
          try {
            await unassignAssignment(assignmentId);
            notifySuccess(tr('Shift unassigned successfully.', 'Poste desassigne avec succes.', 'Turno disassegnato con successo.'));
          } catch (error) {
            notifyError((error && error.message) || tr('Unable to unassign shift.', 'Impossible de desassigner le poste.', 'Impossibile disassegnare il turno.'));
          }
        })();
        return;
      }

      if (!assignShift || !userId) {
        notifyError('Missing assignment context.');
        return;
      }

      var absenceShiftId = getAbsenceTemplateShiftId ? Number(getAbsenceTemplateShiftId(action) || 0) : 0;
      if (!absenceShiftId) {
        notifyError(tr('Absence template is not available.', 'Le modele d absence n est pas disponible.', 'Il modello di assenza non è disponibile.'));
        return;
      }

      (async function () {
        try {
          await assignShift({
            user_id: userId,
            shift_id: absenceShiftId,
            work_date: workDate,
            status: 'assigned',
            force_override: true,
          });
          notifySuccess(tr('Absence assigned successfully.', 'Absence assignee avec succes.', 'Assenza assegnata con successo.'));
        } catch (error) {
          notifyError((error && error.message) || tr('Unable to assign absence.', 'Impossible d assigner l absence.', 'Impossibile assegnare l\'assenza.'));
        }
      })();
    }

    badgeActionMenu.addEventListener('click', handleBadgeAction);
    badgeActionMenu.addEventListener('pointerdown', function (event) {
      var actionButton = event.target.closest('[data-calendar-badge-action]');
      if (!actionButton) return;
      event.preventDefault();
      handleBadgeAction(event);
    });

    document.addEventListener('click', function (event) {
      if (badgeActionMenu.hidden) return;
      if (event.target.closest('.calendar-badge-actions-menu')) return;
      closeBadgeActionMenu();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !badgeActionMenu.hidden) {
        closeBadgeActionMenu();
      }
    });

    var toDateKey = function (dateObj) {
      var y = String(dateObj.getFullYear());
      var m = String(dateObj.getMonth() + 1).padStart(2, '0');
      var d = String(dateObj.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + d;
    };

    var isPastDateKey = function (dateValue) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(String(dateValue || ''))) return false;
      var now = new Date();
      now.setHours(12, 0, 0, 0);
      return String(dateValue) < toDateKey(now);
    };

    if (!calendarShell) return;

    calendarShell.addEventListener('click', function (event) {
      var userBadge = event.target.closest('.calendar-event-user-badge');
      if (userBadge) {
        var slotCardButton = userBadge.closest('.calendar-event-slot-card[data-calendar-slot-toggle]');
        if (slotCardButton) {
          event.preventDefault();
          event.stopPropagation();
          openBadgeActionMenu(userBadge, {
            assignmentId: Number(slotCardButton.getAttribute('data-assignment-id') || 0),
            userId: Number(slotCardButton.getAttribute('data-user-id') || 0),
            workDate: String(slotCardButton.getAttribute('data-work-date') || ''),
          }, event);
          return;
        }
      }

      var slotCardQuickActions = event.target.closest('.calendar-event-slot-card[data-calendar-slot-toggle]');
      if (slotCardQuickActions && !event.target.closest('.calendar-event-slot-expanded')) {
        event.preventDefault();
        event.stopPropagation();
        openBadgeActionMenu(slotCardQuickActions, {
          assignmentId: Number(slotCardQuickActions.getAttribute('data-assignment-id') || 0),
          userId: Number(slotCardQuickActions.getAttribute('data-user-id') || 0),
          workDate: String(slotCardQuickActions.getAttribute('data-work-date') || ''),
        }, event);
        return;
      }

      var sidebarAssignButton = event.target.closest('[data-calendar-sidebar-assign]');
      if (sidebarAssignButton) {
        event.preventDefault();
        event.stopPropagation();
        var selectedUserId = Number(sidebarAssignButton.getAttribute('data-user-id'));
        var selectedShiftId = Number(sidebarAssignButton.getAttribute('data-shift-id'));
        var selectedWorkDate = String(sidebarAssignButton.getAttribute('data-work-date') || '');
        var selectedUserName = String(sidebarAssignButton.getAttribute('data-user-name') || state.activeUserName || 'Employee');
        if (!selectedUserId || !selectedShiftId || !selectedWorkDate || typeof assignShift !== 'function') {
          notifyError('Missing assignment context.');
          return;
        }
        if (isPastDateKey(selectedWorkDate)) {
          notifyError('Past days are locked and cannot be edited.');
          return;
        }
        if (typeof isUserAvailableForDate === 'function' && !isUserAvailableForDate(selectedUserId, selectedWorkDate)) {
          var unavailableReason = (typeof getUserAvailabilityStatus === 'function')
            ? String((getUserAvailabilityStatus(selectedUserId, selectedWorkDate) || {}).reason || '')
            : '';
          notifyError(unavailableReason || (selectedUserName + ' is unavailable on ' + selectedWorkDate + '.'));
          return;
        }

        (async function () {
          try {
            await assignShift({
              user_id: selectedUserId,
              shift_id: selectedShiftId,
              work_date: selectedWorkDate,
              status: 'assigned',
            });
            notifySuccess(selectedUserName + ' assigned successfully.');
          } catch (error) {
            notifyError((error && error.message) || 'Unable to assign employee to this open shift.');
          }
        })();
        return;
      }

      var unassignButton = event.target.closest('[data-calendar-unassign]');
      if (unassignButton) {
        event.preventDefault();
        event.stopPropagation();
        var assignmentToUnassign = Number(unassignButton.getAttribute('data-calendar-unassign'));
        var assignmentForUnassign = events.find(function (item) { return Number(item.assignment_id) === assignmentToUnassign; });
        if (assignmentForUnassign && isPastDateKey(String(assignmentForUnassign.work_date || ''))) {
          notifyError('Past days are locked and cannot be edited.');
          return;
        }
        if (assignmentToUnassign && typeof unassignAssignment === 'function') {
          (async function () {
            try {
              await unassignAssignment(assignmentToUnassign);
              notifySuccess('Shift unassigned successfully.');
            } catch (error) {
              notifyError((error && error.message) || 'Unable to unassign shift.');
            }
          })();
        }
        return;
      }

      var slotToggle = event.target.closest('[data-calendar-slot-toggle]');
      if (slotToggle) {
        event.preventDefault();
        var assignmentCardForSlot = slotToggle.closest('.calendar-event');
        if (!assignmentCardForSlot) return;
        if (assignmentCardForSlot.getAttribute('data-is-past-day') === '1') {
          return;
        }
        var slotUserId = slotToggle.getAttribute('data-user-id') || '';

        calendarShell.querySelectorAll('.calendar-event-slot-expanded').forEach(function (panel) {
          var parentCard = panel.closest('.calendar-event');
          var panelUserId = panel.getAttribute('data-user-id') || '';
          var isSameCard = parentCard === assignmentCardForSlot && panelUserId === slotUserId;
          panel.hidden = isSameCard ? !panel.hidden : true;
          if (!isSameCard && parentCard) {
            parentCard.classList.remove('is-slot-expanded');
            var button = parentCard.querySelector('[data-calendar-slot-toggle]');
            if (button) button.setAttribute('aria-expanded', 'false');
          }
        });

        var ownPanel = slotUserId
          ? assignmentCardForSlot.querySelector('.calendar-event-slot-expanded[data-user-id="' + slotUserId + '"]')
          : assignmentCardForSlot.querySelector('.calendar-event-slot-expanded');
        var isExpanded = ownPanel ? !ownPanel.hidden : false;
        assignmentCardForSlot.classList.toggle('is-slot-expanded', isExpanded);
        slotToggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        return;
      }

        var slotSetAbsenceButton = event.target.closest('[data-calendar-slot-set-absence]');
        if (slotSetAbsenceButton) {
          event.preventDefault();
          var absenceKind = String(slotSetAbsenceButton.getAttribute('data-calendar-slot-set-absence') || '').toLowerCase();
          var absenceUserId = Number(slotSetAbsenceButton.getAttribute('data-user-id') || 0);
          var absenceDate = String(slotSetAbsenceButton.getAttribute('data-work-date') || '');
          var absenceShiftId = getAbsenceTemplateShiftId ? Number(getAbsenceTemplateShiftId(absenceKind) || 0) : 0;
          if (!absenceShiftId || !absenceUserId || !absenceDate || typeof assignShift !== 'function') {
            notifyError('Missing absence assignment context.');
            return;
          }
          if (isPastDateKey(absenceDate)) {
            notifyError('Past days are locked and cannot be edited.');
            return;
          }

          (async function () {
            try {
              await assignShift({
                user_id: absenceUserId,
                shift_id: absenceShiftId,
                work_date: absenceDate,
                status: 'assigned',
                force_override: true,
              });
              notifySuccess('Absence forced successfully.');
            } catch (error) {
              notifyError((error && error.message) || 'Unable to force absence assignment.');
            }
          })();
          return;
        }

        var slotForceWorkButton = event.target.closest('[data-calendar-slot-force-active-shift]');
        if (slotForceWorkButton) {
          event.preventDefault();
          var activeShift = getActiveShift ? (getActiveShift() || null) : null;
          var forceUserId = Number(slotForceWorkButton.getAttribute('data-user-id') || 0);
          var forceDate = String(slotForceWorkButton.getAttribute('data-work-date') || '');
          var currentShiftId = Number(slotForceWorkButton.getAttribute('data-shift-id') || 0);
          var currentDepartmentId = Number(slotForceWorkButton.getAttribute('data-department-id') || 0);
          var activeWorkShiftId = Number(activeShift && String(activeShift.kind || '').toLowerCase() === 'work' ? (activeShift.id || 0) : 0);

          if (!activeWorkShiftId && forceDate) {
            var fallbackShift = events.find(function (item) {
              if (String(item.work_date || '') !== forceDate) return false;
              if (String(item.shift_kind || '').toLowerCase() !== 'work') return false;
              if (currentDepartmentId > 0 && Number(item.department_id || 0) !== currentDepartmentId) return false;
              return Number(item.shift_id || 0) > 0 && Number(item.shift_id || 0) !== currentShiftId;
            });
            activeWorkShiftId = Number(fallbackShift && fallbackShift.shift_id ? fallbackShift.shift_id : 0);
          }

          if (!activeWorkShiftId || !forceUserId || !forceDate || typeof assignShift !== 'function') {
            notifyError('Select a work shift in sidebar, or open another work slot in this day/department first.');
            return;
          }
          if (isPastDateKey(forceDate)) {
            notifyError('Past days are locked and cannot be edited.');
            return;
          }

          (async function () {
            try {
              await assignShift({
                user_id: forceUserId,
                shift_id: activeWorkShiftId,
                work_date: forceDate,
                status: 'assigned',
                force_override: true,
              });
              notifySuccess('Work shift forced successfully.');
            } catch (error) {
              notifyError((error && error.message) || 'Unable to force work shift assignment.');
            }
          })();
          return;
        }

      var assignOtherDatesButton = event.target.closest('[data-calendar-assign-other-dates]');
      if (assignOtherDatesButton) {
        event.preventDefault();
        var assignmentIdForDuplicate = Number(assignOtherDatesButton.getAttribute('data-calendar-assign-other-dates'));
        var sourceAssignment = events.find(function (item) { return Number(item.assignment_id) === assignmentIdForDuplicate; });
        if (!sourceAssignment || !Number(sourceAssignment.user_id || 0) || !Number(sourceAssignment.shift_id || 0) || typeof assignShift !== 'function') {
          return;
        }
        if (isPastDateKey(String(sourceAssignment.work_date || ''))) {
          notifyError('Past days are locked and cannot be edited.');
          return;
        }

        bulkAssignModal.open(sourceAssignment);
        return;
      }

      if (event.target.closest('[data-calendar-slot-panel]')) {
        return;
      }

      var dateCard = event.target.closest('[data-calendar-date]');
      if (dateCard) {
        var dateValue = dateCard.getAttribute('data-calendar-date');
        if (dateValue) {
          openDate(toLocalDate(dateValue));
        }
        return;
      }

      var assignmentCard = event.target.closest('[data-assignment-id]');
      if (assignmentCard) {
        var assignmentId = Number(assignmentCard.getAttribute('data-assignment-id'));
        var assignment = events.find(function (item) { return Number(item.assignment_id) === assignmentId; });
        if (assignment && assignment.work_date) {
          openDate(toLocalDate(assignment.work_date));
        }
      }
    });
  }

  window.DashboardCalendar = {
    init: initCalendarInteractions,
  };
})();
