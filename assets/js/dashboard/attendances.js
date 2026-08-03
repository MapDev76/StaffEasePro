(() => {
    const apiUrl = window.DashboardConfig?.apiDashboard;
    const plannerData = window.DashboardPlannerData || {};
    const feedback = window.DashboardFeedback;
    const employeeModal = document.querySelector('[data-attendance-employee-modal]');
    const recordModal = document.querySelector('[data-attendance-record-modal]');

    if (!apiUrl || !window.AppAPI || !employeeModal) return;

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

    const _atLocale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
    const _atIsFr = _atLocale.startsWith('fr');
    const _atIsIt = _atLocale.startsWith('it');
    const _atTr = (en, fr, it) => (_atIsFr ? fr : (_atIsIt && it ? it : en));

    async function confirmAction(message, title) {
        if (!title) title = _atTr('Confirm action', "Confirmer l'action", "Conferma l'azione");
        if (feedback?.confirm) {
            return feedback.confirm(message, title);
        }
        notifyError(_atTr('Confirmation dialog is not available.', "La boîte de confirmation n'est pas disponible.", 'La finestra di conferma non è disponibile.'));
        return false;
    }

    function parseEmbeddedJson(selector, fallback) {
        const node = document.querySelector(selector);
        if (!node) return fallback;
        try {
            const parsed = JSON.parse(node.textContent || 'null');
            return Array.isArray(fallback) ? (Array.isArray(parsed) ? parsed : fallback) : (parsed || fallback);
        } catch (_error) {
            return fallback;
        }
    }

    function toTimeInputValue(value) {
        const raw = String(value || '').trim();
        return raw ? raw.slice(0, 5) : '';
    }

    function normalizeTimeInputValue(value) {
        const raw = String(value || '').trim();
        return /^\d{2}:\d{2}$/.test(raw) ? raw : '';
    }

    const assignmentCatalog = parseEmbeddedJson('[data-attendance-assignment-catalog]', []);
    const attendanceRecordCatalog = parseEmbeddedJson('[data-attendance-record-catalog]', []);
    const openButtons = Array.from(document.querySelectorAll('[data-attendance-employee-open]'));
    const editButtons = Array.from(document.querySelectorAll('[data-attendance-record-edit]'));
    const cancelButtons = Array.from(document.querySelectorAll('[data-attendance-record-delete]'));

    const employeeCloseButton = employeeModal.querySelector('[data-attendance-employee-close]');
    const employeeTitleNode = employeeModal.querySelector('[data-attendance-modal-title]');
    const employeeSubtitleNode = employeeModal.querySelector('[data-attendance-modal-subtitle]');
    const assignmentSelect = employeeModal.querySelector('[data-attendance-modal-user-shift]');
    const existingAttendanceNote = employeeModal.querySelector('[data-attendance-existing-note]');
    const canEditExistingAttendance = employeeModal.getAttribute('data-attendance-can-edit') === '1';
    const statusSelect = employeeModal.querySelector('[data-attendance-modal-status]');
    const employeeCheckInField = employeeModal.querySelector('[data-attendance-modal-checkin]');
    const employeeCheckOutField = employeeModal.querySelector('[data-attendance-modal-checkout]');
    const employeeHoursMonthSelect = employeeModal.querySelector('[data-attendance-hours-month-select]');
    const employeeHoursMonthNode = employeeModal.querySelector('[data-attendance-hours-month]');
    const employeeHoursAssignedNode = employeeModal.querySelector('[data-attendance-hours-assigned]');
    const employeeHoursWorkedNode = employeeModal.querySelector('[data-attendance-hours-worked]');
    const employeeHoursAssignmentCountNode = employeeModal.querySelector('[data-attendance-hours-assignment-count]');
    const employeeHoursSignedCountNode = employeeModal.querySelector('[data-attendance-hours-signed-count]');
    const employeeHoursLateCountNode = employeeModal.querySelector('[data-attendance-hours-late-count]');
    const saveSignatureButton = employeeModal.querySelector('[data-attendance-signature-save]');
    const clearSignatureButton = employeeModal.querySelector('[data-attendance-signature-clear]');
    const signatureCanvas = employeeModal.querySelector('[data-attendance-signature-canvas]');
    const signatureErrorNode = employeeModal.querySelector('[data-attendance-signature-error]');
    const context = signatureCanvas?.getContext('2d');
    const recordSignatureCanvas = recordModal?.querySelector('[data-attendance-record-signature-canvas]') || null;
    const recordSignatureErrorNode = recordModal?.querySelector('[data-attendance-record-signature-error]') || null;
    const recordSignatureStateNode = recordModal?.querySelector('[data-attendance-record-signature-state]') || null;
    const recordSignatureClearButton = recordModal?.querySelector('[data-attendance-record-signature-clear]') || null;
    const recordSignatureContext = recordSignatureCanvas?.getContext('2d') || null;

    if (!assignmentSelect || !statusSelect || !saveSignatureButton || !signatureCanvas || !context) return;

    context.lineWidth = 2;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.strokeStyle = '#111827';

    if (recordSignatureContext) {
        recordSignatureContext.lineWidth = 2;
        recordSignatureContext.lineJoin = 'round';
        recordSignatureContext.lineCap = 'round';
        recordSignatureContext.strokeStyle = '#111827';
    }

    let currentUserId = 0;
    let currentUserName = '';
    let currentDepartmentName = '';
    let editingAttendanceId = 0;
    let activeAttendanceId = 0;
    let activeAttendanceUserId = 0;
    let isDrawing = false;
    let hasSignature = false;
    let isRecordDrawing = false;
    let hasRecordSignature = false;

    function normalizeMonthKey(value) {
        const raw = String(value || '').trim();
        if (/^\d{4}-\d{2}$/.test(raw)) return raw;
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw.slice(0, 7);
        return '';
    }

    function hoursBetweenTimes(startRaw, endRaw) {
        const start = String(startRaw || '').slice(0, 5);
        const end = String(endRaw || '').slice(0, 5);
        if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end)) return 0;
        const [sh, sm] = start.split(':').map((v) => parseInt(v, 10));
        const [eh, em] = end.split(':').map((v) => parseInt(v, 10));
        let delta = ((eh * 60) + em) - ((sh * 60) + sm);
        if (delta <= 0) delta += 24 * 60;
        return Math.round((delta / 60) * 100) / 100;
    }

    function getMonthLabel(monthKey) {
        const normalized = normalizeMonthKey(monthKey);
        if (!normalized) return '--';
        const date = new Date(`${normalized}-01T12:00:00`);
        const formatter = new Intl.DateTimeFormat(_atIsFr ? 'fr-FR' : (_atIsIt ? 'it-IT' : 'en-US'), { month: 'long', year: 'numeric' });
        const label = formatter.format(date);
        return label.charAt(0).toUpperCase() + label.slice(1);
    }

    function computeMonthlyAssignedHours(userId, monthKey) {
        const assignments = Array.isArray(plannerData.assignments) ? plannerData.assignments : [];
        let total = 0;
        assignments.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            if (normalizeMonthKey(String(row?.work_date || '')) !== monthKey) return;
            if (String(row?.status || '').toLowerCase() === 'cancelled') return;
            if (String(row?.shift_kind || 'work').toLowerCase() !== 'work') return;
            total += hoursBetweenTimes(row?.start_time, row?.end_time);
        });
        return Math.round(total * 100) / 100;
    }

    function computeMonthlyAssignedShiftCount(userId, monthKey) {
        const assignments = Array.isArray(plannerData.assignments) ? plannerData.assignments : [];
        let total = 0;
        assignments.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            if (normalizeMonthKey(String(row?.work_date || '')) !== monthKey) return;
            if (String(row?.status || '').toLowerCase() === 'cancelled') return;
            if (String(row?.shift_kind || 'work').toLowerCase() !== 'work') return;
            total += 1;
        });
        return total;
    }

    function computeMonthlyWorkedHours(userId, monthKey) {
        const attendances = Array.isArray(plannerData.attendances) ? plannerData.attendances : [];
        let total = 0;
        attendances.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            if (normalizeMonthKey(String(row?.work_date || '')) !== monthKey) return;
            const checkIn = String(row?.check_in_time || '').trim();
            const checkOut = String(row?.check_out_time || '').trim();
            if (checkIn && checkOut) {
                total += hoursBetweenTimes(checkIn, checkOut);
            } else {
                total += hoursBetweenTimes(row?.shift_start_time, row?.shift_end_time);
            }
        });
        return Math.round(total * 100) / 100;
    }

    function isLateAttendance(row) {
        const status = String(row?.status || '').trim().toLowerCase();
        if (status === 'late') return true;
        const checkIn = String(row?.check_in_time || '').slice(0, 5);
        const shiftStart = String(row?.shift_start_time || '').slice(0, 5);
        if (!/^\d{2}:\d{2}$/.test(checkIn) || !/^\d{2}:\d{2}$/.test(shiftStart)) return false;
        return checkIn > shiftStart;
    }

    function computeMonthlySignedAttendanceCount(userId, monthKey) {
        const attendances = Array.isArray(plannerData.attendances) ? plannerData.attendances : [];
        let total = 0;
        attendances.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            if (normalizeMonthKey(String(row?.work_date || '')) !== monthKey) return;
            if (Number(row?.digital_signature_id || 0) <= 0) return;
            total += 1;
        });
        return total;
    }

    function computeMonthlyLateAttendanceCount(userId, monthKey) {
        const attendances = Array.isArray(plannerData.attendances) ? plannerData.attendances : [];
        let total = 0;
        attendances.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            if (normalizeMonthKey(String(row?.work_date || '')) !== monthKey) return;
            if (!isLateAttendance(row)) return;
            total += 1;
        });
        return total;
    }

    function getUserMonthOptions(userId, preferredMonthKey) {
        const monthMap = new Map();
        const fallbackMonth = normalizeMonthKey(new Date().toISOString().slice(0, 7));
        const seedMonth = normalizeMonthKey(preferredMonthKey) || fallbackMonth;
        if (seedMonth) monthMap.set(seedMonth, true);

        const assignments = Array.isArray(plannerData.assignments) ? plannerData.assignments : [];
        assignments.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            const monthKey = normalizeMonthKey(String(row?.work_date || ''));
            if (monthKey) monthMap.set(monthKey, true);
        });

        const attendances = Array.isArray(plannerData.attendances) ? plannerData.attendances : [];
        attendances.forEach((row) => {
            if (Number(row?.user_id || 0) !== Number(userId || 0)) return;
            const monthKey = normalizeMonthKey(String(row?.work_date || ''));
            if (monthKey) monthMap.set(monthKey, true);
        });

        const months = Array.from(monthMap.keys()).sort((a, b) => b.localeCompare(a));
        if (months.length === 0 && fallbackMonth) {
            months.push(fallbackMonth);
        }
        return months;
    }

    function fillMonthSelect(selectNode, userId, preferredMonthKey) {
        const fallbackMonth = normalizeMonthKey(new Date().toISOString().slice(0, 7));
        const normalizedPreferred = normalizeMonthKey(preferredMonthKey) || fallbackMonth;
        if (!selectNode) return normalizedPreferred;

        const months = getUserMonthOptions(userId, normalizedPreferred);
        selectNode.innerHTML = '';
        months.forEach((monthKey) => {
            const option = document.createElement('option');
            option.value = monthKey;
            option.textContent = getMonthLabel(monthKey);
            selectNode.appendChild(option);
        });

        const selectedMonth = months.includes(normalizedPreferred || '')
            ? normalizedPreferred
            : (months[0] || fallbackMonth);
        selectNode.value = selectedMonth || '';
        return selectedMonth;
    }

    function renderEmployeeHoursSummary(userId, monthKey) {
        const normalizedMonth = normalizeMonthKey(monthKey);
        if (!employeeHoursMonthNode || !employeeHoursAssignedNode || !employeeHoursWorkedNode) return;
        if (employeeHoursMonthSelect && employeeHoursMonthSelect.value !== normalizedMonth) {
            employeeHoursMonthSelect.value = normalizedMonth;
        }
        employeeHoursMonthNode.textContent = `${_atTr('Target month', 'Mois cible', 'Mese target')}: ${getMonthLabel(normalizedMonth)}`;
        employeeHoursAssignedNode.textContent = `${_atTr('Planned (forecast)', 'Planifiees (previsionnel)', 'Pianificate (previsionale)')}: ${computeMonthlyAssignedHours(userId, normalizedMonth).toFixed(2)} h`;
        employeeHoursWorkedNode.textContent = `${_atTr('Worked (historical)', 'Travaillees (historique)', 'Lavorate (storico)')}: ${computeMonthlyWorkedHours(userId, normalizedMonth).toFixed(2)} h`;
        if (employeeHoursAssignmentCountNode) {
            employeeHoursAssignmentCountNode.textContent = `${computeMonthlyAssignedShiftCount(userId, normalizedMonth)}`;
        }
        if (employeeHoursSignedCountNode) {
            employeeHoursSignedCountNode.textContent = `${computeMonthlySignedAttendanceCount(userId, normalizedMonth)}`;
        }
        if (employeeHoursLateCountNode) {
            employeeHoursLateCountNode.textContent = `${computeMonthlyLateAttendanceCount(userId, normalizedMonth)}`;
        }
    }

    function renderRecordHoursSummary(userId, monthKey) {
        const monthNode = recordModal?.querySelector('[data-attendance-record-hours-month]');
        const monthSelect = recordModal?.querySelector('[data-attendance-record-hours-month-select]');
        const assignedNode = recordModal?.querySelector('[data-attendance-record-hours-assigned]');
        const workedNode = recordModal?.querySelector('[data-attendance-record-hours-worked]');
        const assignmentCountNode = recordModal?.querySelector('[data-attendance-record-hours-assignment-count]');
        const signedCountNode = recordModal?.querySelector('[data-attendance-record-hours-signed-count]');
        const lateCountNode = recordModal?.querySelector('[data-attendance-record-hours-late-count]');
        const normalizedMonth = normalizeMonthKey(monthKey);
        if (!monthNode || !assignedNode || !workedNode) return;
        if (monthSelect && monthSelect.value !== normalizedMonth) {
            monthSelect.value = normalizedMonth;
        }
        monthNode.textContent = `${_atTr('Target month', 'Mois cible', 'Mese target')}: ${getMonthLabel(normalizedMonth)}`;
        assignedNode.textContent = `${_atTr('Planned (forecast)', 'Planifiees (previsionnel)', 'Pianificate (previsionale)')}: ${computeMonthlyAssignedHours(userId, normalizedMonth).toFixed(2)} h`;
        workedNode.textContent = `${_atTr('Worked (historical)', 'Travaillees (historique)', 'Lavorate (storico)')}: ${computeMonthlyWorkedHours(userId, normalizedMonth).toFixed(2)} h`;
        if (assignmentCountNode) {
            assignmentCountNode.textContent = `${computeMonthlyAssignedShiftCount(userId, normalizedMonth)}`;
        }
        if (signedCountNode) {
            signedCountNode.textContent = `${computeMonthlySignedAttendanceCount(userId, normalizedMonth)}`;
        }
        if (lateCountNode) {
            lateCountNode.textContent = `${computeMonthlyLateAttendanceCount(userId, normalizedMonth)}`;
        }
    }

    function resetSignatureCanvas() {
        context.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
        hasSignature = false;
        if (signatureErrorNode) signatureErrorNode.textContent = '';
    }

    function resetRecordSignatureCanvas() {
        if (!recordSignatureCanvas || !recordSignatureContext) return;
        recordSignatureContext.clearRect(0, 0, recordSignatureCanvas.width, recordSignatureCanvas.height);
        hasRecordSignature = false;
        if (recordSignatureErrorNode) recordSignatureErrorNode.textContent = '';
    }

    function closeEmployeeModal() {
        employeeModal.hidden = true;
        currentUserId = 0;
        currentUserName = '';
        currentDepartmentName = '';
        editingAttendanceId = 0;
        assignmentSelect.value = '';
        statusSelect.value = 'present';
        if (employeeCheckInField) employeeCheckInField.value = '';
        if (employeeCheckOutField) employeeCheckOutField.value = '';
        setEmployeeFormReadOnly(false);
        saveSignatureButton.textContent = defaultSaveLabel;
        if (existingAttendanceNote) {
            existingAttendanceNote.hidden = true;
            existingAttendanceNote.textContent = '';
        }
        resetSignatureCanvas();
    }

    const defaultSaveLabel = saveSignatureButton.textContent;

    function findAttendanceByUserShift(userShiftId) {
        const id = Number(userShiftId || 0);
        if (id <= 0) return null;
        return attendanceRecordCatalog.find((record) => Number(record.user_shift_id || 0) === id) || null;
    }

    function setEmployeeFormReadOnly(readOnly) {
        statusSelect.disabled = readOnly;
        if (employeeCheckInField) employeeCheckInField.disabled = readOnly;
        if (employeeCheckOutField) employeeCheckOutField.disabled = readOnly;
        saveSignatureButton.disabled = readOnly;
        saveSignatureButton.hidden = readOnly;
        signatureCanvas.style.pointerEvents = readOnly ? 'none' : '';
        if (clearSignatureButton) {
            clearSignatureButton.disabled = readOnly;
            clearSignatureButton.hidden = readOnly;
        }
    }

    function applyAssignmentSelection(assignment) {
        const record = assignment ? findAttendanceByUserShift(assignment.assignment_id) : null;
        editingAttendanceId = record ? Number(record.id || 0) : 0;
        resetSignatureCanvas();

        if (record) {
            statusSelect.value = record.status || 'present';
            if (employeeCheckInField) employeeCheckInField.value = toTimeInputValue(record.check_in_time);
            if (employeeCheckOutField) employeeCheckOutField.value = toTimeInputValue(record.check_out_time);
            const signedLabel = Number(record.digital_signature_id || 0) > 0
                ? _atTr('signed', 'signée', 'firmata')
                : _atTr('unsigned', 'non signée', 'non firmata');
            if (existingAttendanceNote) {
                existingAttendanceNote.hidden = false;
                existingAttendanceNote.textContent = canEditExistingAttendance
                    ? `${_atTr('Existing attendance', 'Présence existante', 'Presenza esistente')} (${signedLabel}). ${_atTr('You can edit it and save the changes. Drawing a new signature replaces the current one.', 'Vous pouvez la modifier et enregistrer les changements. Une nouvelle signature remplace la signature actuelle.', 'Puoi modificarla e salvare le modifiche. Una nuova firma sostituisce quella attuale.')}`
                    : `${_atTr('Existing attendance', 'Présence existante', 'Presenza esistente')} (${signedLabel}). ${_atTr('Read only: only an administrator can edit it.', 'Lecture seule : seul un administrateur peut la modifier.', 'Sola lettura: solo un amministratore può modificarla.')}`;
            }
            setEmployeeFormReadOnly(!canEditExistingAttendance);
            saveSignatureButton.textContent = _atTr('Update attendance', 'Mettre à jour la présence', 'Aggiorna presenza');
        } else {
            statusSelect.value = 'present';
            if (employeeCheckInField) employeeCheckInField.value = normalizeTimeInputValue(assignment?.start_time || '');
            if (employeeCheckOutField) employeeCheckOutField.value = normalizeTimeInputValue(assignment?.end_time || '');
            if (existingAttendanceNote) {
                existingAttendanceNote.hidden = true;
                existingAttendanceNote.textContent = '';
            }
            setEmployeeFormReadOnly(false);
            saveSignatureButton.textContent = defaultSaveLabel;
        }
    }

    function openEmployeeModal(button) {
        currentUserId = Number(button.getAttribute('data-user-id') || 0);
        currentUserName = (button.getAttribute('data-user-name') || '').trim();
        currentDepartmentName = (button.getAttribute('data-user-department-name') || '').trim();

        const userAssignments = assignmentCatalog.filter((assignment) => Number(assignment.user_id || 0) === currentUserId);
        assignmentSelect.innerHTML = '<option value="">Select assigned shift</option>';
        userAssignments.forEach((assignment) => {
            const option = document.createElement('option');
            option.value = String(assignment.assignment_id || '');
            const record = findAttendanceByUserShift(assignment.assignment_id);
            let marker = '';
            if (record) {
                marker = Number(record.digital_signature_id || 0) > 0
                    ? ` • ${_atTr('signed', 'signée', 'firmata')}`
                    : ` • ${_atTr('recorded, unsigned', 'enregistrée, non signée', 'registrata, non firmata')}`;
            }
            option.textContent = `${assignment.work_date || ''} - ${assignment.shift_name || 'Shift'} (${assignment.status || 'assigned'})${marker}`;
            assignmentSelect.appendChild(option);
        });
        let targetMonth = normalizeMonthKey(new Date().toISOString().slice(0, 7));
        if (userAssignments.length > 0) {
            assignmentSelect.value = String(userAssignments[0].assignment_id || '');
            targetMonth = normalizeMonthKey(userAssignments[0].work_date || '') || targetMonth;
            applyAssignmentSelection(userAssignments[0]);
        } else {
            applyAssignmentSelection(null);
            if (employeeCheckInField) employeeCheckInField.value = '';
            if (employeeCheckOutField) employeeCheckOutField.value = '';
        }
        targetMonth = fillMonthSelect(employeeHoursMonthSelect, currentUserId, targetMonth);
        renderEmployeeHoursSummary(currentUserId, targetMonth);

        if (employeeTitleNode) {
            employeeTitleNode.textContent = `Attendance signature: ${currentUserName || 'Employee'}`;
        }
        if (employeeSubtitleNode) {
            employeeSubtitleNode.textContent = currentDepartmentName
                ? `${currentDepartmentName} - Select shift and sign to record attendance.`
                : 'Select shift and sign to record attendance.';
        }

        employeeModal.hidden = false;
    }    function closeRecordModal() {
        if (!recordModal) return;
        recordModal.hidden = true;
        activeAttendanceId = 0;
        activeAttendanceUserId = 0;
        resetRecordSignatureCanvas();
    }

    function openRecordModal(attendanceId) {
        if (!recordModal) return;
        const record = attendanceRecordCatalog.find((item) => Number(item.id || 0) === Number(attendanceId));
        if (!record) {
            notifyError('Attendance record not found.');
            return;
        }

        activeAttendanceId = Number(record.id || 0);
        activeAttendanceUserId = Number(record.user_id || 0);
        const titleNode = recordModal.querySelector('[data-attendance-record-title]');
        const subtitleNode = recordModal.querySelector('[data-attendance-record-subtitle]');
        const statusField = recordModal.querySelector('[data-attendance-record-status]');
        const checkInField = recordModal.querySelector('[data-attendance-record-checkin]');
        const checkOutField = recordModal.querySelector('[data-attendance-record-checkout]');

        if (titleNode) titleNode.textContent = `Edit attendance: ${record.user_name || 'Employee'}`;
        if (subtitleNode) subtitleNode.textContent = `${record.work_date || ''} - ${record.shift_name || 'Shift'} - ${record.department_name || 'Department'}`;
        if (statusField) statusField.value = record.status || 'present';
        if (checkInField) checkInField.value = toTimeInputValue(record.check_in_time);
        if (checkOutField) checkOutField.value = toTimeInputValue(record.check_out_time);
        resetRecordSignatureCanvas();
        if (recordSignatureStateNode) {
            const hasExistingSignature = Number(record.digital_signature_id || 0) > 0;
            recordSignatureStateNode.textContent = hasExistingSignature
                ? `${_atTr('Signature status', 'Etat signature', 'Stato firma')}: ${_atTr('present', 'presente', 'presente')}`
                : `${_atTr('Signature status', 'Etat signature', 'Stato firma')}: ${_atTr('missing', 'manquante', 'mancante')}`;
        }
        const recordMonthSelect = recordModal.querySelector('[data-attendance-record-hours-month-select]');
        const recordUserId = Number(record.user_id || 0);
        const selectedMonth = fillMonthSelect(recordMonthSelect, recordUserId, record.work_date || '');
        if (recordMonthSelect) {
            recordMonthSelect.setAttribute('data-user-id', String(recordUserId));
        }
        renderRecordHoursSummary(recordUserId, selectedMonth || record.work_date || '');

        recordModal.hidden = false;
    }

    function pointFromEvent(event) {
        const rect = signatureCanvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
    }

    function pointFromRecordEvent(event) {
        if (!recordSignatureCanvas) {
            return { x: 0, y: 0 };
        }
        const rect = recordSignatureCanvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
    }

    signatureCanvas.addEventListener('pointerdown', (event) => {
        isDrawing = true;
        signatureCanvas.setPointerCapture(event.pointerId);
        const point = pointFromEvent(event);
        context.beginPath();
        context.moveTo(point.x, point.y);
    });

    signatureCanvas.addEventListener('pointermove', (event) => {
        if (!isDrawing) return;
        const point = pointFromEvent(event);
        context.lineTo(point.x, point.y);
        context.stroke();
        hasSignature = true;
        if (signatureErrorNode) signatureErrorNode.textContent = '';
    });

    function stopDrawing(event) {
        if (!isDrawing) return;
        isDrawing = false;
        try {
            signatureCanvas.releasePointerCapture(event.pointerId);
        } catch (_error) {
            // noop
        }
    }

    signatureCanvas.addEventListener('pointerup', stopDrawing);
    signatureCanvas.addEventListener('pointercancel', stopDrawing);
    signatureCanvas.addEventListener('pointerleave', stopDrawing);

    if (recordSignatureCanvas && recordSignatureContext) {
        recordSignatureCanvas.addEventListener('pointerdown', (event) => {
            isRecordDrawing = true;
            recordSignatureCanvas.setPointerCapture(event.pointerId);
            const point = pointFromRecordEvent(event);
            recordSignatureContext.beginPath();
            recordSignatureContext.moveTo(point.x, point.y);
        });

        recordSignatureCanvas.addEventListener('pointermove', (event) => {
            if (!isRecordDrawing) return;
            const point = pointFromRecordEvent(event);
            recordSignatureContext.lineTo(point.x, point.y);
            recordSignatureContext.stroke();
            hasRecordSignature = true;
            if (recordSignatureErrorNode) recordSignatureErrorNode.textContent = '';
        });

        const stopRecordDrawing = (event) => {
            if (!isRecordDrawing) return;
            isRecordDrawing = false;
            try {
                recordSignatureCanvas.releasePointerCapture(event.pointerId);
            } catch (_error) {
                // noop
            }
        };

        recordSignatureCanvas.addEventListener('pointerup', stopRecordDrawing);
        recordSignatureCanvas.addEventListener('pointercancel', stopRecordDrawing);
        recordSignatureCanvas.addEventListener('pointerleave', stopRecordDrawing);
    }

    openButtons.forEach((button) => {
        button.addEventListener('click', () => openEmployeeModal(button));
    });

    if (employeeCloseButton) {
        employeeCloseButton.addEventListener('click', closeEmployeeModal);
    }

    employeeModal.addEventListener('click', (event) => {
        if (event.target === employeeModal) closeEmployeeModal();
    });

    if (clearSignatureButton) {
        clearSignatureButton.addEventListener('click', resetSignatureCanvas);
    }

    if (recordSignatureClearButton) {
        recordSignatureClearButton.addEventListener('click', resetRecordSignatureCanvas);
    }

    assignmentSelect.addEventListener('change', () => {
        const selectedAssignmentId = Number(assignmentSelect.value || 0);
        const selectedAssignment = assignmentCatalog.find((assignment) => Number(assignment.assignment_id || 0) === selectedAssignmentId) || null;
        applyAssignmentSelection(selectedAssignment);
        const selectedMonth = normalizeMonthKey(selectedAssignment?.work_date || '') || normalizeMonthKey(new Date().toISOString().slice(0, 7));
        const targetMonth = fillMonthSelect(employeeHoursMonthSelect, currentUserId, selectedMonth);
        renderEmployeeHoursSummary(currentUserId, targetMonth);
    });

    if (employeeHoursMonthSelect) {
        employeeHoursMonthSelect.addEventListener('change', () => {
            const selectedMonth = normalizeMonthKey(employeeHoursMonthSelect.value) || normalizeMonthKey(new Date().toISOString().slice(0, 7));
            renderEmployeeHoursSummary(currentUserId, selectedMonth);
        });
    }

    saveSignatureButton.addEventListener('click', async () => {
        if (currentUserId <= 0) {
            notifyError('Unable to determine user for attendance record.');
            return;
        }

        const selectedShiftId = Number(assignmentSelect.value || 0);
        if (selectedShiftId <= 0) {
            notifyError('Select an assigned shift before recording attendance.');
            return;
        }

        const isEditingExisting = editingAttendanceId > 0;
        if (isEditingExisting && !canEditExistingAttendance) {
            notifyError(_atTr('Only an administrator can edit an existing attendance.', 'Seul un administrateur peut modifier une présence existante.', 'Solo un amministratore può modificare una presenza esistente.'));
            return;
        }

        if (!isEditingExisting && !hasSignature) {
            if (signatureErrorNode) signatureErrorNode.textContent = 'Digital signature is required.';
            notifyError('Digital signature is required.');
            return;
        }

        const checkInValue = normalizeTimeInputValue(employeeCheckInField?.value || '');
        const checkOutValue = normalizeTimeInputValue(employeeCheckOutField?.value || '');
        if (!checkInValue && checkOutValue) {
            notifyError('Set check-in time before check-out time.');
            return;
        }

        saveSignatureButton.disabled = true;
        try {
            const payload = isEditingExisting
                ? {
                    action: 'update_attendance',
                    attendance_id: editingAttendanceId,
                    attendance_status: statusSelect.value || 'present',
                    check_in_time: checkInValue,
                    check_out_time: checkOutValue,
                    signature_data: hasSignature ? signatureCanvas.toDataURL('image/png') : '',
                }
                : {
                    action: 'record_attendance_signature',
                    user_id: currentUserId,
                    user_shift_id: selectedShiftId,
                    attendance_status: statusSelect.value || 'present',
                    signature_data: signatureCanvas.toDataURL('image/png'),
                    check_in_time: checkInValue,
                    check_out_time: checkOutValue,
                };
            const response = await AppAPI.postJSON(apiUrl, payload);

            if (!response?.ok) {
                notifyError(response?.error || 'Unable to record attendance.');
                return;
            }

            notifySuccess(isEditingExisting
                ? _atTr('Attendance updated successfully.', 'Présence mise à jour avec succès.', 'Presenza aggiornata con successo.')
                : 'Attendance recorded with digital signature.');
            globalThis.setTimeout(() => globalThis.location.reload(), 450);
        } catch (error) {
            notifyError(error?.message || 'Unable to record attendance.');
        } finally {
            saveSignatureButton.disabled = false;
        }
    });

    if (!recordModal) return;

    const recordCloseButtons = Array.from(recordModal.querySelectorAll('[data-attendance-record-close]'));
    const recordSaveButton = recordModal.querySelector('[data-attendance-record-save]');
    const recordCancelButton = recordModal.querySelector('[data-attendance-record-cancel-registration]');
    const recordStatusField = recordModal.querySelector('[data-attendance-record-status]');
    const recordCheckInField = recordModal.querySelector('[data-attendance-record-checkin]');
    const recordCheckOutField = recordModal.querySelector('[data-attendance-record-checkout]');

    editButtons.forEach((button) => {
        button.addEventListener('click', () => {
            openRecordModal(Number(button.getAttribute('data-attendance-id') || 0));
        });
    });

    async function cancelAttendance(attendanceId) {
        if (!attendanceId) return;
        const canProceed = await confirmAction(_atTr('Cancel this attendance registration?', 'Annuler cet enregistrement de présence ?', 'Annullare questa registrazione di presenza?'), _atTr('Confirm cancellation', "Confirmer l'annulation", "Conferma l'annullamento"));
        if (!canProceed) return;
        const response = await AppAPI.postJSON(apiUrl, {
            action: 'cancel_attendance',
            attendance_id: attendanceId,
        });
        if (!response?.ok) {
            notifyError(response?.error || 'Unable to cancel attendance.');
            return;
        }
        notifySuccess('Attendance registration cancelled.');
        globalThis.setTimeout(() => globalThis.location.reload(), 450);
    }

    cancelButtons.forEach((button) => {
        button.addEventListener('click', () => {
            cancelAttendance(Number(button.getAttribute('data-attendance-id') || 0));
        });
    });

    recordCloseButtons.forEach((button) => {
        button.addEventListener('click', closeRecordModal);
    });

    recordModal.addEventListener('click', (event) => {
        if (event.target === recordModal) closeRecordModal();
    });

    const recordMonthSelect = recordModal.querySelector('[data-attendance-record-hours-month-select]');
    if (recordMonthSelect) {
        recordMonthSelect.addEventListener('change', () => {
            const targetUserId = Number(recordMonthSelect.getAttribute('data-user-id') || 0);
            const selectedMonth = normalizeMonthKey(recordMonthSelect.value) || normalizeMonthKey(new Date().toISOString().slice(0, 7));
            if (targetUserId > 0) {
                renderRecordHoursSummary(targetUserId, selectedMonth);
            }
        });
    }

    if (recordSaveButton && recordStatusField && recordCheckInField && recordCheckOutField) {
        recordSaveButton.addEventListener('click', async () => {
            if (!activeAttendanceId) {
                notifyError('Attendance record not selected.');
                return;
            }
            const checkInValue = normalizeTimeInputValue(recordCheckInField.value || '');
            const checkOutValue = normalizeTimeInputValue(recordCheckOutField.value || '');
            if (!checkInValue && checkOutValue) {
                notifyError('Set check-in time before check-out time.');
                return;
            }
            recordSaveButton.disabled = true;
            try {
                const response = await AppAPI.postJSON(apiUrl, {
                    action: 'update_attendance',
                    attendance_id: activeAttendanceId,
                    attendance_status: recordStatusField.value || 'present',
                    check_in_time: checkInValue,
                    check_out_time: checkOutValue,
                    signature_data: hasRecordSignature && recordSignatureCanvas
                        ? recordSignatureCanvas.toDataURL('image/png')
                        : '',
                });
                if (!response?.ok) {
                    notifyError(response?.error || 'Unable to update attendance.');
                    return;
                }
                notifySuccess('Attendance updated successfully.');
                globalThis.setTimeout(() => globalThis.location.reload(), 450);
            } catch (error) {
                notifyError(error?.message || 'Unable to update attendance.');
            } finally {
                recordSaveButton.disabled = false;
            }
        });
    }

    if (recordCancelButton) {
        recordCancelButton.addEventListener('click', () => {
            cancelAttendance(activeAttendanceId);
        });
    }
})();
