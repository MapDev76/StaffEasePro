/*
 * Dashboard UI behaviors: modal shell, form wiring and company/user/department actions.
 * This module expects the following globals to be present before execution:
 * - `window.DashboardConfig` with `{ apiCompanies, apiDepartments, apiUsers }`
 * - `window.AppAPI` that implements the AJAX helpers used by buttons.
 *
 * The file contains two main self-invoking sections:
 * 1) Modal lifecycle and template wiring (`setupModals`) — mounts CRUD templates
 *    into the shared modal shell and manages focus/keyboard accessibility.
 * 2) Company actions (`setupCompanyActions`) — lightweight event handlers that
 *    call `AppAPI` to perform create/delete/manage operations on companies,
 *    departments and users.
 */
(function(){
  const config = window.DashboardConfig || {};
  const locale = (document.documentElement.getAttribute('lang') || 'en').toLowerCase();
  const isFr = locale.startsWith('fr');
  const isIt = locale.startsWith('it');
  const tr = (enText, frText, itText) => (isFr ? frText : (isIt && itText ? itText : enText));
  const apiCompanies = config.apiCompanies;
  const apiDepartments = config.apiDepartments;
  const apiUsers = config.apiUsers;
  const apiDashboard = config.apiDashboard;
  const iconsBase = String(config.iconsBase || '/assets/icons/');
  const pdfjsLibSrc = String(config.pdfjsLibSrc || '/assets/js/vendor/pdfjs/pdf.min.js');
  const pdfjsWorkerSrc = String(config.pdfjsWorkerSrc || '/assets/js/vendor/pdfjs/pdf.worker.min.js');
  const plannerData = window.DashboardPlannerData || {};
  const currentUser = window.DashboardCurrentUser || {};
  let pdfjsLibGlobal = window.pdfjsLib || null;
  let pdfjsLoadPromise = null;

  const ensureSuperAdminCompanyScope = () => {
    const role = String(currentUser.role || '').toLowerCase();
    if (role !== 'super_admin') return;

    const url = new URL(window.location.href);
    const routeParam = String(url.searchParams.get('route') || '').toLowerCase();
    const viewParam = String(url.searchParams.get('view') || '').toLowerCase();
    const isDashboardRoute = routeParam === 'dashboard' || /\/dashboard\/?$/i.test(url.pathname);
    if (!isDashboardRoute) return;
    if (viewParam === 'directory') return;

    const currentCompanyId = Number(url.searchParams.get('settings_company_id') || 0);
    if (currentCompanyId > 0) return;

    const plannerCompanyId = Number(plannerData?.company?.id || 0);
    const firstCompanyId = Number((Array.isArray(plannerData?.companies) ? plannerData.companies[0]?.id : 0) || 0);
    const fallbackCompanyId = plannerCompanyId > 0 ? plannerCompanyId : firstCompanyId;
    if (fallbackCompanyId <= 0) return;

    url.searchParams.set('settings_company_id', String(fallbackCompanyId));
    window.location.replace(url.toString());
  };

  ensureSuperAdminCompanyScope();

  const configurePdfWorker = () => {
    if (pdfjsLibGlobal && pdfjsLibGlobal.GlobalWorkerOptions) {
      pdfjsLibGlobal.GlobalWorkerOptions.workerSrc = pdfjsWorkerSrc;
    }
  };

  const ensurePdfJsLoaded = async () => {
    if (pdfjsLibGlobal && typeof pdfjsLibGlobal.getDocument === 'function') {
      configurePdfWorker();
      return pdfjsLibGlobal;
    }

    if (window.pdfjsLib && typeof window.pdfjsLib.getDocument === 'function') {
      pdfjsLibGlobal = window.pdfjsLib;
      configurePdfWorker();
      return pdfjsLibGlobal;
    }

    if (pdfjsLoadPromise) {
      return pdfjsLoadPromise;
    }

    pdfjsLoadPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-pdfjs-runtime="1"]');
      if (existing) {
        existing.addEventListener('load', () => {
          pdfjsLibGlobal = window.pdfjsLib || null;
          configurePdfWorker();
          resolve(pdfjsLibGlobal);
        }, { once: true });
        existing.addEventListener('error', () => reject(new Error(tr('Unable to load PDF engine.', 'Impossible de charger le moteur PDF.', 'Impossibile caricare il motore PDF.'))), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = pdfjsLibSrc;
      script.defer = true;
      script.setAttribute('data-pdfjs-runtime', '1');
      script.onload = () => {
        pdfjsLibGlobal = window.pdfjsLib || null;
        configurePdfWorker();
        resolve(pdfjsLibGlobal);
      };
      script.onerror = () => reject(new Error(tr('Unable to load PDF engine.', 'Impossible de charger le moteur PDF.', 'Impossibile caricare il motore PDF.')));
      document.head.appendChild(script);
    });

    return pdfjsLoadPromise;
  };

  configurePdfWorker();

  const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const isIconAsset = (icon) => /\.(svg|png|jpe?g|gif|webp|ico)$/i.test(String(icon || ''));
  const renderIconHtml = (icon, color) => {
    if (!icon) return '';
    if (isIconAsset(icon)) {
      return `<img src="${iconsBase}${encodeURIComponent(icon)}" aria-hidden="true" class="calendar-icon-img">`;
    }
    return `<span style="color:${escapeHtml(color || '')}">${escapeHtml(icon)}</span>`;
  };

  const renderDepartmentTitleHtml = (icon, name, color) => {
    const safeName = escapeHtml(name || 'Department');
    const iconHtml = icon ? `${renderIconHtml(icon, color)} ` : '';
    return `${iconHtml}${safeName}`;
  };

  /**
   * setupModals
   * Initialize modal behavior for the dashboard: open/close logic, overlay,
   * focus trapping and template-based content injection for the shared CRUD
   * modal. Templates are defined in `app/layout/crud-modal.php` and are
   * injected into the element with id `crud-modal-body`.
   */
  (function setupModals(){
    const feedback = window.DashboardFeedback;
    const notifyError = (message) => {
      if (feedback?.error) {
        feedback.error('Oops!', message);
        return;
      }
      console.error(message);
    };
    const notifySuccess = (message) => {
      if (feedback?.success) {
        feedback.success('Done', message);
      }
    };

    const overlay = document.getElementById('dashboard-overlay');
    const modals = document.querySelectorAll('.dashboard-modal, .crud-modal');
    const openButtons = document.querySelectorAll('[data-modal-target]');
    const closeButtons = document.querySelectorAll('[data-modal-close]');
    const crudModal = document.getElementById('crud-modal');
    const crudTitle = document.getElementById('crud-modal-title');
    const crudSubtitle = document.getElementById('crud-modal-subtitle');
    const crudBody = document.getElementById('crud-modal-body');
    let lastFocusedElement = null;
    let activeModal = null;
    const focusableSelector = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled]):not([type="hidden"])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    /**
     * focusFirst(container)
     * Move keyboard focus to the first tabbable element inside `container`.
     */
    const focusFirst = (container) => {
      const focusables = container ? container.querySelectorAll(focusableSelector) : [];
      const first = focusables[0];
      if (first && typeof first.focus === 'function') {
        first.focus();
      }
    };

    /**
     * syncDepartmentFilter(select, companyId)
     * Hide department options that don't belong to the provided companyId.
     * Used when the UI exposes both a company select and a department select
     * so the department list is contextually filtered.
     */
    const syncDepartmentFilter = (select, companyId) => {
      if (!select) return;
      const normalized = String(companyId || '');
      Array.from(select.options).forEach((option) => {
        if (option.value === '') return;
        const optionCompanyId = option.getAttribute('data-company-id') || '';
        option.hidden = normalized !== '' && optionCompanyId !== normalized;
      });
      if (select.value && select.selectedOptions[0] && select.selectedOptions[0].hidden) {
        select.value = '';
      }
    };

    /**
     * setModalContent(entity)
    * Given an entity name (companies|users|departments|documents),
     * initialize the form fields and wire local UI handlers for that template's
     * controls. Templates are copied from the `<template>` nodes included in
     * `app/layout/crud-modal.php`.
     */
    const setModalContent = (entity) => {
      if (!crudBody) return;
      if (entity === 'documents') {
        const removeDocumentCard = (card) => {
          if (!card || !card.parentElement) return;
          card.remove();
          const remaining = crudBody.querySelectorAll('.company-card').length;
          if (remaining === 0) {
            const grid = crudBody.querySelector('.company-grid');
            if (grid) {
              grid.innerHTML = '<div class="crud-empty-state">No documents available.</div>';
            }
          }
        };

        const uploadForm = crudBody.querySelector('#crud-document-share-form');
        const uploadFileInput = crudBody.querySelector('#crud-document-file');
        const uploadScopeInput = crudBody.querySelector('#crud-document-recipient-scope');
        const uploadRecipientsLabel = crudBody.querySelector('#crud-document-recipient-label');
        const uploadRecipientsInput = crudBody.querySelector('#crud-document-recipient-ids');
        const uploadRequireSignatureInput = crudBody.querySelector('#crud-document-require-signature');
        const uploadShareNowInput = crudBody.querySelector('#crud-document-share-now');
        const uploadSubmitButton = crudBody.querySelector('#crud-document-share-submit');
        const uploadShareExistingButton = crudBody.querySelector('#crud-document-share-existing-submit');
        const uploadRequestTypeInput = crudBody.querySelector('#crud-document-request-type');
        const uploadShiftRow = crudBody.querySelector('[data-document-shift-row]');
        const uploadShiftInput = crudBody.querySelector('#crud-document-shift-id');
        const uploadTitleInput = crudBody.querySelector('#crud-document-title');
        const uploadMessageInput = crudBody.querySelector('#crud-document-message');

        const closeDashboardSignModal = () => {
          const signModal = document.querySelector('[data-dashboard-document-sign-modal]');
          if (signModal) {
            signModal.remove();
          }
        };

        const openDashboardSignModal = (button) => {
          const documentId = Number(button.getAttribute('data-dashboard-document-sign-id') || 0);
          if (!documentId) {
            notifyError(tr('Invalid document id.', 'ID document invalide.', 'ID documento non valido.'));
            return;
          }

          closeDashboardSignModal();

          const documentName = String(button.getAttribute('data-dashboard-document-sign-name') || 'Document').trim();
          const documentMimeType = String(button.getAttribute('data-dashboard-document-sign-mime-type') || '').trim().toLowerCase();
          const previewUrl = String(button.getAttribute('data-dashboard-document-sign-preview-url') || '').trim();
          const isPdfDocument = /pdf/.test(documentMimeType) || /\.pdf$/i.test(documentName);
          const fallbackPreviewUrl = previewUrl ? previewUrl.split('#')[0] : '';
          const pdfStreamUrl = previewUrl
            ? `${previewUrl}${previewUrl.includes('?') ? '&' : '?'}pdf_stream=1`
            : '';

          const signModal = document.createElement('div');
          signModal.className = 'employee-attendance-modal';
          signModal.setAttribute('data-dashboard-document-sign-modal', '1');

          signModal.innerHTML = `
            <div class="employee-attendance-dialog" role="dialog" aria-modal="true" aria-labelledby="dashboard-document-sign-title">
              <div class="employee-attendance-dialog-head">
                <div>
                  <span class="employee-stage-eyebrow">${tr('Documents', 'Documents', 'Documenti')}</span>
                  <h3 id="dashboard-document-sign-title">${tr('Sign document', 'Signer le document', 'Firma documento')}</h3>
                  <p class="crud-modal-subtitle">${documentName.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                </div>
                <button type="button" class="dashboard-modal-close" data-dashboard-document-sign-close aria-label="${tr('Close', 'Fermer', 'Chiudi')}">×</button>
              </div>

              <div class="admin-form employee-sign-form" data-dashboard-document-sign-form>
                <div class="employee-document-sign-stage">
                  <p class="crud-modal-subtitle employee-document-sign-stage-hint">${tr('Choose where to sign: click or drag the marker inside the document preview.', 'Choisissez où signer : cliquez ou faites glisser le marqueur dans l apercu du document.', 'Scegli dove firmare: clicca o trascina il marcatore nell\'anteprima del documento.')}</p>
                  <div class="employee-signature-pad-actions" data-dashboard-document-sign-page-controls>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-dashboard-document-sign-page-prev>${tr('Previous page', 'Page precedente', 'Pagina precedente')}</button>
                    <label style="display:flex;align-items:center;gap:.35rem;">
                      <span>${tr('Page', 'Page', 'Pagina')}</span>
                      <input type="number" min="1" step="1" value="1" data-dashboard-document-sign-page-input style="max-width:88px;">
                    </label>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-dashboard-document-sign-page-next>${tr('Next page', 'Page suivante', 'Pagina successiva')}</button>
                  </div>
                  <div class="employee-document-sign-preview-wrap">
                    <iframe src="${previewUrl}" class="employee-document-sign-preview" data-dashboard-document-sign-preview-frame title="Preview"></iframe>
                    <canvas class="employee-document-sign-pdf-canvas" data-dashboard-document-sign-pdf-canvas hidden aria-label="${tr('PDF page preview', 'Apercu de la page PDF', 'Anteprima della pagina PDF')}"></canvas>
                    <button type="button" class="employee-document-sign-position-layer" data-dashboard-document-sign-position-layer aria-label="${tr('Choose signature position', 'Choisissez la position de la signature', 'Scegli la posizione della firma')}">
                      <span class="employee-document-sign-position-dot" data-dashboard-document-sign-position-dot aria-hidden="true"></span>
                    </button>
                  </div>
                </div>

                <div class="employee-signature-pad-shell">
                  <canvas width="520" height="180" data-dashboard-document-sign-canvas aria-label="${tr('Digital signature', 'Signature numerique', 'Firma digitale')}"></canvas>
                  <small class="employee-signature-error" data-dashboard-document-sign-error></small>
                  <div class="employee-signature-pad-actions">
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-dashboard-document-sign-clear>${tr('Clear signature', 'Effacer la signature', 'Cancella firma')}</button>
                    <small>${tr('Sign with your finger on mobile or with mouse/stylus on desktop.', 'Signez avec le doigt sur mobile ou avec la souris/le stylet sur desktop.', 'Firma con il dito su mobile o con il mouse/pennino su desktop.')}</small>
                  </div>
                </div>

                <div class="employee-attendance-dialog-actions">
                  <button type="button" class="admin-action-link admin-action-link-secondary" data-dashboard-document-sign-close>${tr('Cancel', 'Annuler', 'Annulla')}</button>
                  <button type="button" class="admin-action-link" data-dashboard-document-sign-submit>${tr('Sign document', 'Signer le document', 'Firma documento')}</button>
                </div>
              </div>
            </div>
          `;

          document.body.appendChild(signModal);
          document.documentElement.classList.add('modal-open');
          document.body.classList.add('modal-open');

          const closeModal = () => {
            closeDashboardSignModal();
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
          };

          signModal.querySelectorAll('[data-dashboard-document-sign-close]').forEach((closeBtn) => {
            closeBtn.addEventListener('click', (event) => {
              event.preventDefault();
              closeModal();
            });
          });

          signModal.addEventListener('click', (event) => {
            if (event.target === signModal) {
              closeModal();
            }
          });

          const canvas = signModal.querySelector('[data-dashboard-document-sign-canvas]');
          const errorNode = signModal.querySelector('[data-dashboard-document-sign-error]');
          const clearButton = signModal.querySelector('[data-dashboard-document-sign-clear]');
          const submitButton = signModal.querySelector('[data-dashboard-document-sign-submit]');
          const positionLayer = signModal.querySelector('[data-dashboard-document-sign-position-layer]');
          const positionDot = signModal.querySelector('[data-dashboard-document-sign-position-dot]');
          const previewWrap = signModal.querySelector('.employee-document-sign-preview-wrap');
          const previewFrame = signModal.querySelector('[data-dashboard-document-sign-preview-frame]');
          const pdfCanvas = signModal.querySelector('[data-dashboard-document-sign-pdf-canvas]');
          const pageControls = signModal.querySelector('[data-dashboard-document-sign-page-controls]');
          const pagePrevButton = signModal.querySelector('[data-dashboard-document-sign-page-prev]');
          const pageNextButton = signModal.querySelector('[data-dashboard-document-sign-page-next]');
          const pageInput = signModal.querySelector('[data-dashboard-document-sign-page-input]');

          if (!canvas || !positionLayer || !positionDot || !submitButton || !previewFrame) {
            notifyError(tr('Unable to open signature editor.', 'Impossible d\'ouvrir l\'editeur de signature.', 'Impossibile aprire l\'editor di firma.'));
            closeModal();
            return;
          }

          const pad = window.AppSignaturePad && window.AppSignaturePad.init(canvas, {
            errorNode,
            clearBtn: clearButton,
            positionLayer,
            positionDot,
          });

          if (!pad) {
            notifyError(tr('Unable to open signature editor.', 'Impossible d\'ouvrir l\'editeur de signature.', 'Impossibile aprire l\'editor di firma.'));
            closeModal();
            return;
          }

          let markerX = 86;
          let markerY = 84;
          let selectedPage = 1;
          let pdfDocument = null;
          let pdfRenderToken = 0;

          const normalizePage = (rawValue) => {
            const parsed = Number(rawValue || 1);
            if (!Number.isFinite(parsed)) return 1;
            return Math.max(1, Math.floor(parsed));
          };

          const previewBaseUrl = (isPdfDocument && pdfStreamUrl ? pdfStreamUrl : previewUrl).split('#')[0];

          const renderPdfPage = async (targetPage) => {
            if (!pdfDocument || !pdfCanvas || !previewWrap) return false;
            const pageNumber = Math.max(1, Math.min(pdfDocument.numPages || 1, normalizePage(targetPage)));
            selectedPage = pageNumber;
            if (pageInput) {
              pageInput.value = String(selectedPage);
            }

            const token = ++pdfRenderToken;
            const page = await pdfDocument.getPage(pageNumber);
            const fitRect = previewWrap.getBoundingClientRect();
            const viewportRaw = page.getViewport({ scale: 1 });
            const safeWidth = Math.max(320, Math.floor((fitRect.width || 920) - 12));
            const scale = Math.max(0.2, safeWidth / viewportRaw.width);
            const viewport = page.getViewport({ scale });
            const ratio = Math.max(window.devicePixelRatio || 1, 1);

            if (token !== pdfRenderToken) return false;

            const ctxPdf = pdfCanvas.getContext('2d');
            if (!ctxPdf) return false;
            pdfCanvas.width = Math.floor(viewport.width * ratio);
            pdfCanvas.height = Math.floor(viewport.height * ratio);
            pdfCanvas.style.width = `${Math.floor(viewport.width)}px`;
            pdfCanvas.style.height = `${Math.floor(viewport.height)}px`;
            ctxPdf.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctxPdf.fillStyle = '#ffffff';
            ctxPdf.fillRect(0, 0, viewport.width, viewport.height);

            await page.render({ canvasContext: ctxPdf, viewport }).promise;

            if (token !== pdfRenderToken) return false;

            previewFrame.hidden = true;
            pdfCanvas.hidden = false;
            previewWrap.classList.add('is-pdf-rendered');
            return true;
          };

          const initPdfRenderer = async () => {
            if (!isPdfDocument || !pdfStreamUrl) {
              return;
            }

            try {
              const pdfLib = await ensurePdfJsLoaded();
              if (!pdfLib || typeof pdfLib.getDocument !== 'function') {
                return;
              }
              const loadingTask = pdfLib.getDocument({ url: pdfStreamUrl, withCredentials: true });
              pdfDocument = await loadingTask.promise;
              if (pageInput && pdfDocument && Number.isFinite(pdfDocument.numPages)) {
                pageInput.max = String(Math.max(1, Math.floor(pdfDocument.numPages)));
              }
              await renderPdfPage(selectedPage);
            } catch (error) {
              pdfDocument = null;
              if (errorNode) {
                errorNode.textContent = tr('PDF advanced preview unavailable, using browser preview fallback.', 'Apercu PDF avance indisponible, utilisation du mode de secours navigateur.', 'Anteprima PDF avanzata non disponibile, utilizzo dell\'anteprima del browser.');
              }
              previewFrame.hidden = false;
              previewFrame.src = fallbackPreviewUrl;
              if (pdfCanvas) pdfCanvas.hidden = true;
              if (previewWrap) previewWrap.classList.remove('is-pdf-rendered');
            }
          };

          const applyPreviewPage = (targetPage) => {
            selectedPage = normalizePage(targetPage);
            if (pdfDocument) {
              renderPdfPage(selectedPage).catch(() => {
                previewFrame.src = fallbackPreviewUrl;
              });
              return;
            }
            if (pageInput) {
              pageInput.value = String(selectedPage);
            }
            if (isPdfDocument) {
              previewFrame.src = fallbackPreviewUrl;
              return;
            }
            previewFrame.src = previewBaseUrl;
          };

          if (!isPdfDocument && pageControls) {
            pageControls.style.display = 'none';
          }

          if (pagePrevButton) {
            pagePrevButton.addEventListener('click', (event) => {
              event.preventDefault();
              applyPreviewPage(selectedPage - 1);
            });
          }
          if (pageNextButton) {
            pageNextButton.addEventListener('click', (event) => {
              event.preventDefault();
              applyPreviewPage(selectedPage + 1);
            });
          }
          if (pageInput) {
            pageInput.addEventListener('change', (event) => {
              event.preventDefault();
              applyPreviewPage(pageInput.value);
            });
          }

          // Keep markerX / markerY in sync when the position layer updates via AppSignaturePad.
          const origUpdatePos = pad.updatePosition;
          pad.updatePosition = (xPercent, yPercent) => {
            const result = origUpdatePos(xPercent, yPercent);
            markerX = result ? result.x : Math.min(96, Math.max(4, Number(xPercent) || 86));
            markerY = result ? result.y : Math.min(96, Math.max(4, Number(yPercent) || 84));
          };

          submitButton.addEventListener('click', async (event) => {
            event.preventDefault();
            if (!pad.hasStroke()) {
              if (errorNode) {
                errorNode.textContent = tr('Please draw your signature before signing this document.', 'Veuillez dessiner votre signature avant de signer ce document.', 'Disegna la tua firma prima di firmare questo documento.');
              }
              return;
            }

            if (!apiDashboard || !window.AppAPI || typeof window.AppAPI.postJSON !== 'function') {
              notifyError(tr('Dashboard API is not available.', 'API dashboard indisponible.', 'API dashboard non disponibile.'));
              return;
            }

            submitButton.disabled = true;
            if (errorNode) errorNode.textContent = '';
            try {
              const response = await window.AppAPI.postJSON(apiDashboard, {
                action: 'sign_dashboard_document',
                document_id: documentId,
                signature_data: pad.getDataUrl(),
                signature_pos_x: markerX,
                signature_pos_y: markerY,
                signature_page: selectedPage,
              });

              if (!response || response.ok === false || response.success === false) {
                throw new Error((response && (response.error || response.message)) || tr('Unable to sign document.', 'Impossible de signer le document.', 'Impossibile firmare il documento.'));
              }

              notifySuccess(tr('Document signed successfully.', 'Document signe avec succes.', 'Documento firmato con successo.'));
              closeModal();
              window.location.reload();
            } catch (err) {
              if (errorNode) {
                errorNode.textContent = (err && err.message) || tr('Unable to sign document.', 'Impossible de signer le document.', 'Impossibile firmare il documento.');
              }
            } finally {
              submitButton.disabled = false;
            }
          });

          pad.updatePosition(markerX, markerY);
          applyPreviewPage(1);
          initPdfRenderer();
          pad.resize();
          window.addEventListener('resize', () => pad.resize(), { once: true });
        };

        const syncDocumentRequestMode = () => {
          const requestType = String(uploadRequestTypeInput ? uploadRequestTypeInput.value || 'notification' : 'notification');
          const shareNow = !uploadShareNowInput || !!uploadShareNowInput.checked;
          const showShiftPicker = requestType === 'shift_coverage' && shareNow;

          if (uploadShiftRow) {
            uploadShiftRow.classList.toggle('is-hidden', !showShiftPicker);
          }
          if (uploadShiftInput) {
            uploadShiftInput.disabled = !showShiftPicker;
            if (!showShiftPicker) {
              uploadShiftInput.value = '';
            }
          }

          if (uploadRequireSignatureInput) {
            const canAskSignature = requestType === 'notification' && shareNow;
            uploadRequireSignatureInput.disabled = !canAskSignature;
            if (!canAskSignature) {
              uploadRequireSignatureInput.checked = false;
            }
          }
        };

        const syncUploadRecipientMode = () => {
          if (!uploadScopeInput || !uploadRecipientsInput || !uploadRecipientsLabel) return;
          const shareNow = !uploadShareNowInput || !!uploadShareNowInput.checked;
          const isAll = uploadScopeInput.value === 'all';
          const disableRecipients = !shareNow || isAll;
          uploadScopeInput.disabled = !shareNow;
          uploadRecipientsInput.disabled = disableRecipients;
          uploadRecipientsLabel.classList.toggle('is-hidden', disableRecipients);
          if (disableRecipients) {
            Array.from(uploadRecipientsInput.options || []).forEach((option) => {
              option.selected = false;
            });
          }
          if (uploadSubmitButton) {
            uploadSubmitButton.textContent = shareNow
              ? tr('Upload and share document', 'Televerser et partager le document', 'Carica e condividi documento')
              : tr('Save document draft', 'Enregistrer le brouillon du document', 'Salva bozza documento');
          }
          syncDocumentRequestMode();
        };

        const getRecipientsPayload = () => {
          const recipientScope = uploadScopeInput ? String(uploadScopeInput.value || 'selected') : 'selected';
          const recipientIds = uploadRecipientsInput
            ? Array.from(uploadRecipientsInput.selectedOptions || []).map((option) => Number(option.value || 0)).filter((id) => Number.isInteger(id) && id > 0)
            : [];
          return { recipientScope, recipientIds };
        };

        const toBase64 = (file) => new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => {
            const result = String(reader.result || '');
            const commaIdx = result.indexOf(',');
            resolve(commaIdx >= 0 ? result.slice(commaIdx + 1) : result);
          };
          reader.onerror = () => reject(new Error(tr('Unable to read file.', 'Impossible de lire le fichier.', 'Impossibile leggere il file.')));
          reader.readAsDataURL(file);
        });

        if (uploadScopeInput) {
          uploadScopeInput.addEventListener('change', syncUploadRecipientMode);
          syncUploadRecipientMode();
        }
        if (uploadShareNowInput) {
          uploadShareNowInput.addEventListener('change', syncUploadRecipientMode);
          syncUploadRecipientMode();
        }
        if (uploadRequestTypeInput) {
          uploadRequestTypeInput.addEventListener('change', syncDocumentRequestMode);
          syncDocumentRequestMode();
        }

        if (uploadForm && uploadFileInput) {
          uploadForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!apiDashboard || !window.AppAPI || typeof window.AppAPI.postJSON !== 'function') {
              notifyError(tr('Dashboard API is not available.', 'API dashboard indisponible.', 'API dashboard non disponibile.'));
              return;
            }

            const file = uploadFileInput.files && uploadFileInput.files[0] ? uploadFileInput.files[0] : null;
            if (!file) {
              notifyError(tr('Please choose a document to upload.', 'Veuillez choisir un document a televerser.', 'Scegli un documento da caricare.'));
              return;
            }

            const shareNow = !uploadShareNowInput || !!uploadShareNowInput.checked;
            const { recipientScope, recipientIds } = getRecipientsPayload();
            const requestType = String(uploadRequestTypeInput ? uploadRequestTypeInput.value || 'notification' : 'notification');
            const shiftId = Number(uploadShiftInput ? uploadShiftInput.value || 0 : 0);
            const requestTitle = String(uploadTitleInput ? uploadTitleInput.value || '' : '').trim();
            const requestMessage = String(uploadMessageInput ? uploadMessageInput.value || '' : '').trim();

            if (shareNow && recipientScope !== 'all' && recipientIds.length === 0) {
              notifyError(tr('Select at least one recipient.', 'Selectionnez au moins un destinataire.', 'Seleziona almeno un destinatario.'));
              return;
            }
            if (shareNow && requestType === 'shift_coverage' && shiftId <= 0) {
              notifyError(tr('Select a shift for coverage request.', 'Selectionnez un quart pour la demande de remplacement.', 'Seleziona un turno per la richiesta di copertura.'));
              return;
            }

            uploadSubmitButton && (uploadSubmitButton.disabled = true);
            try {
              const fileContentB64 = await toBase64(file);
              const response = await window.AppAPI.postJSON(apiDashboard, {
                action: 'upload_and_share_document',
                file_name: file.name,
                file_content_b64: fileContentB64,
                file_mime_type: file.type || 'application/octet-stream',
                document_type: 'other',
                recipient_scope: recipientScope,
                recipient_ids: recipientIds,
                request_type: requestType,
                shift_id: shiftId,
                title: requestTitle,
                message: requestMessage,
                share_now: shareNow,
                require_signature: !!(uploadRequireSignatureInput && uploadRequireSignatureInput.checked),
              });

              if (!response || response.ok === false || response.success === false) {
                throw new Error((response && (response.error || response.message)) || tr('Unable to share document.', 'Impossible de partager le document.', 'Impossibile condividere il documento.'));
              }

              notifySuccess(shareNow
                ? tr('Document uploaded and shared successfully.', 'Document televerse et partage avec succes.', 'Documento caricato e condiviso con successo.')
                : tr('Document draft uploaded successfully. Sign or archive it before sharing.', 'Brouillon televerse avec succes. Signez-le ou archivez-le avant partage.', 'Bozza del documento caricata con successo. Firmala o archiviala prima della condivisione.'));
              window.location.reload();
            } catch (error) {
              notifyError((error && error.message) || tr('Unable to upload document.', 'Impossible de televerser le document.', 'Impossibile caricare il documento.'));
            } finally {
              uploadSubmitButton && (uploadSubmitButton.disabled = false);
            }
          });
        }

        if (uploadShareExistingButton) {
          uploadShareExistingButton.addEventListener('click', async (event) => {
            event.preventDefault();
            if (!apiDashboard || !window.AppAPI || typeof window.AppAPI.postJSON !== 'function') {
              notifyError(tr('Dashboard API is not available.', 'API dashboard indisponible.', 'API dashboard non disponibile.'));
              return;
            }

            const selectedButton = crudBody.querySelector('[data-document-share-existing-id].is-active');
            const documentId = Number(selectedButton ? (selectedButton.getAttribute('data-document-share-existing-id') || 0) : 0);
            if (!documentId) {
              notifyError(tr('Select a document card to share first.', 'Selectionnez d abord une carte document a partager.', 'Seleziona prima una scheda documento da condividere.'));
              return;
            }

            const { recipientScope, recipientIds } = getRecipientsPayload();
            const requestType = String(uploadRequestTypeInput ? uploadRequestTypeInput.value || 'notification' : 'notification');
            const shiftId = Number(uploadShiftInput ? uploadShiftInput.value || 0 : 0);
            const requestTitle = String(uploadTitleInput ? uploadTitleInput.value || '' : '').trim();
            const requestMessage = String(uploadMessageInput ? uploadMessageInput.value || '' : '').trim();
            if (recipientScope !== 'all' && recipientIds.length === 0) {
              notifyError(tr('Select at least one recipient.', 'Selectionnez au moins un destinataire.', 'Seleziona almeno un destinatario.'));
              return;
            }
            if (requestType === 'shift_coverage' && shiftId <= 0) {
              notifyError(tr('Select a shift for coverage request.', 'Selectionnez un quart pour la demande de remplacement.', 'Seleziona un turno per la richiesta di copertura.'));
              return;
            }

            uploadShareExistingButton.disabled = true;
            try {
              const response = await window.AppAPI.postJSON(apiDashboard, {
                action: 'share_existing_document',
                document_id: documentId,
                recipient_scope: recipientScope,
                recipient_ids: recipientIds,
                request_type: requestType,
                shift_id: shiftId,
                title: requestTitle,
                message: requestMessage,
                require_signature: !!(uploadRequireSignatureInput && uploadRequireSignatureInput.checked),
              });

              if (!response || response.ok === false || response.success === false) {
                throw new Error((response && (response.error || response.message)) || tr('Unable to share selected document.', 'Impossible de partager le document selectionne.', 'Impossibile condividere il documento selezionato.'));
              }

              notifySuccess(tr('Selected document shared successfully.', 'Document selectionne partage avec succes.', 'Documento selezionato condiviso con successo.'));
              window.location.reload();
            } catch (error) {
              notifyError((error && error.message) || tr('Unable to share selected document.', 'Impossible de partager le document selectionne.', 'Impossibile condividere il documento selezionato.'));
            } finally {
              uploadShareExistingButton.disabled = false;
            }
          });
        }

        crudBody.querySelectorAll('[data-document-share-existing-id]').forEach((button) => {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            const isAlreadyActive = button.classList.contains('is-active');
            crudBody.querySelectorAll('[data-document-share-existing-id]').forEach((item) => {
              item.classList.remove('is-active');
              const defaultTitle = String(item.getAttribute('data-document-share-select-title') || tr('Add to send selection', 'Ajouter a la selection d envoi', 'Aggiungi alla selezione di invio'));
              item.title = defaultTitle;
              const iconNode = item.querySelector('[data-document-share-select-icon]');
              if (iconNode) {
                iconNode.textContent = '+';
              }
            });
            if (!isAlreadyActive) {
              button.classList.add('is-active');
              const documentName = String(button.getAttribute('data-document-share-existing-name') || 'document');
              button.title = String(button.getAttribute('data-document-share-unselect-title') || tr('Remove from send selection', 'Retirer de la selection d envoi', 'Rimuovi dalla selezione di invio'));
              const iconNode = button.querySelector('[data-document-share-select-icon]');
              if (iconNode) {
                iconNode.textContent = '✓';
              }
              notifySuccess(tr(`Added to send selection: ${documentName}`, `Ajoute a la selection d envoi : ${documentName}`, `Aggiunto alla selezione di invio: ${documentName}`));
            } else {
              button.title = String(button.getAttribute('data-document-share-select-title') || tr('Add to send selection', 'Ajouter a la selection d envoi', 'Aggiungi alla selezione di invio'));
              notifySuccess(tr('Removed from send selection.', 'Retire de la selection d envoi.', 'Rimosso dalla selezione di invio.'));
            }
          });
        });

        crudBody.querySelectorAll('[data-document-delete-id]').forEach((button) => {
          button.addEventListener('click', async () => {
            if (!apiDashboard || !window.AppAPI || typeof window.AppAPI.postJSON !== 'function') {
              notifyError(tr('Dashboard API is not available.', 'API dashboard indisponible.', 'API dashboard non disponibile.'));
              return;
            }

            const documentId = Number(button.getAttribute('data-document-delete-id') || 0);
            const documentName = String(button.getAttribute('data-document-delete-name') || 'document');
            if (!documentId) {
              notifyError(tr('Invalid document id.', 'ID document invalide.', 'ID documento non valido.'));
              return;
            }

            if (!window.confirm(tr('Delete document "' + documentName + '"? This action cannot be undone.', 'Supprimer le document "' + documentName + '" ? Cette action est irreversible.', 'Eliminare il documento "' + documentName + '"? Questa azione non può essere annullata.'))) {
              return;
            }

            button.disabled = true;
            try {
              const response = await window.AppAPI.postJSON(apiDashboard, {
                action: 'delete_document',
                document_id: documentId,
              });
              if (!response || response.ok === false || response.success === false) {
                throw new Error((response && (response.error || response.message)) || tr('Unable to delete document.', 'Impossible de supprimer le document.', 'Impossibile eliminare il documento.'));
              }
              removeDocumentCard(button.closest('.company-card'));
              notifySuccess(tr('Document deleted successfully.', 'Document supprime avec succes.', 'Documento eliminato con successo.'));
            } catch (error) {
              notifyError((error && error.message) || tr('Unable to delete document.', 'Impossible de supprimer le document.', 'Impossibile eliminare il documento.'));
            } finally {
              button.disabled = false;
            }
          });
        });

        crudBody.querySelectorAll('[data-dashboard-document-sign-open]').forEach((button) => {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            openDashboardSignModal(button);
          });
        });
        return;
      }

      if (entity === 'companies') {
        const companyHeading = crudBody.querySelector('#crud-company-form-heading');
        const companyAction = crudBody.querySelector('#crud-company-action');
        const companyId = crudBody.querySelector('#crud-company-id');
        const companySubmit = crudBody.querySelector('#crud-company-submit');
        const resetCompany = crudBody.querySelector('[data-crud-reset-company]');
        const companyForm = crudBody.querySelector('#crud-company-form');

        const resetCompanyForm = () => {
          if (companyHeading) companyHeading.textContent = tr('Create company', 'Creer une entreprise', 'Crea azienda');
          if (companyAction) companyAction.value = 'create';
          if (companyId) companyId.value = '';
          if (companySubmit) companySubmit.textContent = tr('Create company', 'Creer une entreprise', 'Crea azienda');
          if (companyForm) companyForm.reset();
        };

        const fillCompanyForm = (card) => {
          if (!card) return;
          const data = card.dataset || {};
          if (companyHeading) companyHeading.textContent = tr('Edit company', 'Modifier l entreprise', 'Modifica azienda');
          if (companyAction) companyAction.value = 'update';
          if (companyId) companyId.value = data.companyId || '';
          if (companySubmit) companySubmit.textContent = tr('Update company', 'Mettre a jour l entreprise', 'Aggiorna azienda');
          const fields = {
            'crud-company-name': data.companyName || '',
            'crud-company-type': data.companyType || 'other',
            'crud-company-city': data.companyCity || '',
            'crud-company-address': data.companyAddress || '',
            'crud-company-zip-code': data.companyZipCode || '',
            'crud-company-phone': data.companyPhone || '',
            'crud-company-email': data.companyEmail || '',
            'crud-company-logo-path': data.companyLogoPath || '',
            'crud-company-signature-ip': data.companySignatureIp || '',
          };
          Object.entries(fields).forEach(([id, value]) => {
            const input = crudBody.querySelector(`#${id}`);
            if (input) input.value = value;
          });
        };

        crudBody.querySelectorAll('[data-company-action="edit"]').forEach((button) => {
          button.addEventListener('click', () => fillCompanyForm(button.closest('.company-card')));
        });

        if (resetCompany) resetCompany.addEventListener('click', resetCompanyForm);
        resetCompanyForm();
      }

      if (entity === 'users') {
        const userHeading = crudBody.querySelector('#crud-user-form-heading');
        const userAction = crudBody.querySelector('#crud-user-action');
        const userId = crudBody.querySelector('#crud-user-id');
        const userSubmit = crudBody.querySelector('#crud-user-submit');
        const userForm = crudBody.querySelector('#crud-user-form');
        const userCompanyFilter = crudBody.querySelector('#crud-user-company-filter');
        const userDepartment = crudBody.querySelector('#crud-user-department-id');
        const resetUser = crudBody.querySelector('[data-crud-reset-user]');

        const resetUserForm = () => {
          if (userHeading) userHeading.textContent = tr('Create user', 'Creer un utilisateur', 'Crea utente');
          if (userAction) userAction.value = 'create';
          if (userId) userId.value = '';
          if (userSubmit) userSubmit.textContent = tr('Create user', 'Creer un utilisateur', 'Crea utente');
          if (userForm) userForm.reset();
          syncDepartmentFilter(userDepartment, '');
        };

        const setCompanyFilterFromDepartment = (departmentId) => {
          if (!userCompanyFilter || !userDepartment) return;
          const selectedOption = userDepartment.querySelector(`option[value="${departmentId}"]`);
          const companyId = selectedOption ? (selectedOption.getAttribute('data-company-id') || '') : '';
          userCompanyFilter.value = companyId;
          syncDepartmentFilter(userDepartment, companyId);
          if (departmentId) userDepartment.value = String(departmentId);
        };

        const fillUserForm = (card) => {
          if (!card) return;
          const data = card.dataset || [];
          if (userHeading) userHeading.textContent = tr('Edit user', 'Modifier l utilisateur', 'Modifica utente');
          if (userAction) userAction.value = 'update';
          if (userId) userId.value = data.userId || '';
          if (userSubmit) userSubmit.textContent = tr('Update user', 'Mettre a jour l utilisateur', 'Aggiorna utente');
          const fields = {
            'crud-user-first-name': data.userFirstName || '',
            'crud-user-last-name': data.userLastName || '',
            'crud-user-email': data.userEmail || '',
            'crud-user-phone': data.userPhone || '',
            'crud-user-role': data.userRole || 'employee',
            'crud-user-status': data.userStatus || 'active',
            'crud-user-password': '',
          };
          Object.entries(fields).forEach(([id, value]) => {
            const input = crudBody.querySelector(`#${id}`);
            if (input) input.value = value;
          });
          const departmentId = data.userDepartmentId || '';
          setCompanyFilterFromDepartment(departmentId);
          if (userDepartment && departmentId) userDepartment.value = String(departmentId);
        };

        if (userCompanyFilter && userDepartment) {
          userCompanyFilter.addEventListener('change', () => {
            syncDepartmentFilter(userDepartment, userCompanyFilter.value);
          });
        }

        crudBody.querySelectorAll('[data-user-action="edit"]').forEach((button) => {
          button.addEventListener('click', () => fillUserForm(button.closest('.company-card')));
        });

        if (resetUser) resetUser.addEventListener('click', resetUserForm);
        resetUserForm();
      }

      if (entity === 'departments') {
        const departmentHeading = crudBody.querySelector('#crud-department-form-heading');
        const departmentAction = crudBody.querySelector('#crud-department-action');
        const departmentId = crudBody.querySelector('#crud-department-id');
        const departmentSubmit = crudBody.querySelector('#crud-department-submit');
        const departmentForm = crudBody.querySelector('#crud-department-edit-form');
        const departmentCompany = crudBody.querySelector('#crud-department-company-select');
        const departmentHead = crudBody.querySelector('#crud-department-head-user-select');
        const resetDepartment = crudBody.querySelector('[data-crud-reset-department]');

        const filterDepartmentHeads = (companyId) => {
          if (!departmentHead) return;
          const normalized = String(companyId || '');
          Array.from(departmentHead.options).forEach((option) => {
            if (option.value === '') return;
            const optionCompanyId = option.getAttribute('data-company-id') || '';
            option.hidden = normalized !== '' && optionCompanyId !== normalized;
          });
          if (departmentHead.value && departmentHead.selectedOptions[0] && departmentHead.selectedOptions[0].hidden) {
            departmentHead.value = '';
          }
        };

        const resetDepartmentForm = () => {
          if (departmentHeading) departmentHeading.textContent = tr('Create department', 'Creer un departement', 'Crea dipartimento');
          if (departmentAction) departmentAction.value = 'create';
          if (departmentId) departmentId.value = '';
          if (departmentSubmit) departmentSubmit.textContent = tr('Create department', 'Creer un departement', 'Crea dipartimento');
          if (departmentForm) departmentForm.reset();
          filterDepartmentHeads('');
        };

        const fillDepartmentForm = (card) => {
          if (!card) return;
          const data = card.dataset || {};
          if (departmentHeading) departmentHeading.textContent = tr('Edit department', 'Modifier le departement', 'Modifica dipartimento');
          if (departmentAction) departmentAction.value = 'update';
          if (departmentId) departmentId.value = data.departmentId || '';
          if (departmentSubmit) departmentSubmit.textContent = tr('Update department', 'Mettre a jour le departement', 'Aggiorna dipartimento');
          if (departmentCompany) departmentCompany.value = data.departmentCompanyId || '';
          filterDepartmentHeads(data.departmentCompanyId || '');
          const fields = {
            'crud-department-name-input': data.departmentName || '',
            'crud-department-description-input': data.departmentDescription || '',
            'crud-department-head-user-select': data.departmentHeadUserId || '',
          };
          Object.entries(fields).forEach(([id, value]) => {
            const input = crudBody.querySelector(`#${id}`);
            if (input) input.value = value;
          });
        };

        if (departmentCompany) {
          departmentCompany.addEventListener('change', () => {
            filterDepartmentHeads(departmentCompany.value);
          });
        }

        crudBody.querySelectorAll('[data-department-action="edit"]').forEach((button) => {
          button.addEventListener('click', () => fillDepartmentForm(button.closest('.company-card')));
        });

        if (resetDepartment) resetDepartment.addEventListener('click', resetDepartmentForm);
        resetDepartmentForm();
      }
    };

    /**
     * closeAll()
     * Close any open dashboard modal and restore page state and focus.
     */
    const closeAll = () => {
      modals.forEach((modal) => {
        modal.hidden = true;
        modal.classList.remove('is-open');
      });
      openButtons.forEach((item) => item.classList.remove('is-active'));
      if (overlay) {
        overlay.hidden = true;
        overlay.classList.remove('is-open');
      }
      document.body.classList.remove('modal-open');
      activeModal = null;
      if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
        lastFocusedElement.focus();
      }
    };

    openButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-modal-target');
        const entity = button.getAttribute('data-modal-entity') || '';
        const title = button.getAttribute('data-modal-title') || button.textContent.trim();
        const targetModal = document.getElementById(targetId);
        if (!targetModal) return;
        lastFocusedElement = document.activeElement;
        closeAll();
        button.classList.add('is-active');

        if (targetId === 'crud-modal' && crudModal) {
          const templateId = entity ? `crud-template-${entity}` : 'crud-template-placeholder';
          const template = document.getElementById(templateId) || document.getElementById('crud-template-placeholder');
          if (crudTitle) crudTitle.textContent = title;
          if (crudSubtitle) {
            crudSubtitle.textContent = entity === 'companies'
              ? tr('Create, edit and manage companies and departments.', 'Creez, modifiez et gerez entreprises et departements.', 'Crea, modifica e gestisci aziende e dipartimenti.')
              : entity === 'users'
                ? tr('Create, edit and assign users by role and department.', 'Creez, modifiez et assignez les utilisateurs par role et departement.', 'Crea, modifica e assegna gli utenti per ruolo e dipartimento.')
                : entity === 'departments'
                  ? tr('Create, edit and assign departments by company and head.', 'Creez, modifiez et assignez les departements par entreprise et responsable.', 'Crea, modifica e assegna i dipartimenti per azienda e responsabile.')
                    : entity === 'documents'
                        ? tr('Upload, share and manage documents from this panel.', 'Televersez, partagez et gerez les documents depuis ce panneau.', 'Carica, condividi e gestisci i documenti da questo pannello.')
                : tr('Common CRUD shell', 'Conteneur CRUD commun', 'Shell CRUD comune');
          }
          if (crudBody && template) crudBody.innerHTML = template.innerHTML;
          setModalContent(entity);
        }

        targetModal.hidden = false;
        targetModal.classList.add('is-open');
        targetModal.dispatchEvent(new CustomEvent('modal:open'));
        if (overlay) { overlay.hidden = false; overlay.classList.add('is-open'); }
        document.body.classList.add('modal-open');
        activeModal = targetModal;
        window.setTimeout(() => focusFirst(targetModal), 0);
      });
    });

    closeButtons.forEach((button) => button.addEventListener('click', closeAll));
    if (overlay) overlay.addEventListener('click', closeAll);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeAll();
        return;
      }

      if (event.key !== 'Tab' || !activeModal) {
        return;
      }

      const focusables = activeModal.querySelectorAll(focusableSelector);
      if (focusables.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    /**
     * openModalFromQuery()
     * If the URL contains `?modal=<entity>`, attempt to open the corresponding
     * modal by triggering the matching sidebar/button element. This supports
     * deep linking to modal content for demos or bookmarking.
     */
    const openModalFromQuery = () => {
      const params = new URLSearchParams(window.location.search);
      const modalEntity = params.get('modal');
      if (!modalEntity) return;

      const trigger = document.querySelector(`[data-modal-entity="${modalEntity}"]`) ||
        (modalEntity === 'settings' ? document.querySelector('[data-modal-target="modal-settings"]') : null);
      if (trigger && typeof trigger.click === 'function') {
        window.__dashboardRequestedSettingsTab = params.get('settings_tab') || '';
        window.history.replaceState({}, '', `${window.location.pathname}${window.location.hash}`);
        trigger.click();
      }
    };

    const setupSettingsTabs = () => {
      const settingsModal = document.getElementById('modal-settings');
      if (!settingsModal) return;

      const tabButtons = Array.from(settingsModal.querySelectorAll('[data-settings-tab]'));
      const panels = Array.from(settingsModal.querySelectorAll('[data-settings-panel]'));
      const tabInput = settingsModal.querySelector('[data-settings-tab-input]');
      const companySelect = settingsModal.querySelector('[data-settings-company-select]');
      const collapsiblePanelNames = new Set(['users', 'departments', 'shifts', 'attendances']);
      const panelListExpandedState = {};
      const tr3 = (enText, frText, itText) => (isFr ? frText : (isIt ? itText : enText));
      if (tabButtons.length === 0 || panels.length === 0) return;

      const getPanelName = (panel) => String(panel?.getAttribute('data-settings-panel') || '');

      const getCollapsibleListWrap = (panel) => {
        const panelName = getPanelName(panel);
        if (!collapsiblePanelNames.has(panelName)) return null;
        return panel.querySelector(':scope > .settings-list-wrap') || panel.querySelector('.settings-list-wrap');
      };

      const getOrCreatePanelToggleHost = (panel) => {
        const panelHead = panel.querySelector('.settings-panel-head');
        if (!panelHead) return null;
        const existingPillRow = panelHead.querySelector('.settings-pill-row');
        if (existingPillRow) return existingPillRow;
        const pillRow = document.createElement('div');
        pillRow.className = 'settings-pill-row';
        panelHead.appendChild(pillRow);
        return pillRow;
      };

      const applyPanelListVisibility = (panel) => {
        const panelName = getPanelName(panel);
        const listWrap = getCollapsibleListWrap(panel);
        const toggleButton = panel.querySelector('[data-settings-list-toggle]');
        if (!listWrap || !toggleButton || !collapsiblePanelNames.has(panelName)) return;
        const isExpanded = !!panelListExpandedState[panelName];
        listWrap.hidden = !isExpanded;
        toggleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        toggleButton.textContent = isExpanded
          ? tr3('Hide list', 'Masquer la liste', 'Nascondi lista')
          : tr3('Show list', 'Afficher la liste', 'Mostra lista');
      };

      const ensurePanelListToggle = (panel) => {
        const panelName = getPanelName(panel);
        const listWrap = getCollapsibleListWrap(panel);
        if (!listWrap || !collapsiblePanelNames.has(panelName)) return;
        listWrap.classList.add('settings-panel-list-collapsible');

        if (typeof panelListExpandedState[panelName] !== 'boolean') {
          panelListExpandedState[panelName] = false;
        }

        let toggleButton = panel.querySelector('[data-settings-list-toggle]');
        if (!toggleButton) {
          const host = getOrCreatePanelToggleHost(panel);
          if (!host) return;
          toggleButton = document.createElement('button');
          toggleButton.type = 'button';
          toggleButton.className = 'admin-action-link admin-action-link-secondary settings-list-collapse-toggle';
          toggleButton.setAttribute('data-settings-list-toggle', panelName);
          toggleButton.addEventListener('click', () => {
            panelListExpandedState[panelName] = !panelListExpandedState[panelName];
            applyPanelListVisibility(panel);
          });
          host.appendChild(toggleButton);
        }

        applyPanelListVisibility(panel);
      };

      const activateTab = (tabName) => {
        tabButtons.forEach((button) => {
          const isActive = button.getAttribute('data-settings-tab') === tabName;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach((panel) => {
          const isActive = panel.getAttribute('data-settings-panel') === tabName;
          panel.classList.toggle('is-active', isActive);
          panel.hidden = !isActive;
          if (isActive) {
            ensurePanelListToggle(panel);
          }
        });

        settingsModal.classList.toggle('has-active-tab', Boolean(tabName));

        if (tabName) {
          const modalBody = settingsModal.querySelector('.crud-modal-body');
          if (modalBody) modalBody.scrollTop = 0;
          settingsModal.scrollTop = 0;
        }

        if (tabInput) {
          tabInput.value = tabName || '';
        }
      };

      const resetTabs = () => {
        tabButtons.forEach((button) => {
          button.classList.remove('is-active');
          button.setAttribute('aria-selected', 'false');
        });

        panels.forEach((panel) => {
          panel.classList.remove('is-active');
          panel.hidden = true;
        });

        settingsModal.classList.remove('has-active-tab');
      };

      tabButtons.forEach((button) => {
        button.addEventListener('click', () => activateTab(button.getAttribute('data-settings-tab')));
      });

      if (companySelect) {
        companySelect.addEventListener('change', () => {
          const activeTab = settingsModal.querySelector('[data-settings-tab].is-active')?.getAttribute('data-settings-tab') || '';
          if (tabInput) tabInput.value = activeTab;
          companySelect.form?.submit();
        });
      }

      // Start with all panels closed; open only when a tab is clicked.
      resetTabs();

      // Initialize toggles for list-based panels in collapsed state.
      panels.forEach((panel) => {
        const panelName = getPanelName(panel);
        if (!collapsiblePanelNames.has(panelName)) return;
        panelListExpandedState[panelName] = false;
        ensurePanelListToggle(panel);
      });

      // Each time settings modal opens, keep all panels closed until a tab click.
      settingsModal.addEventListener('modal:open', () => {
        panels.forEach((panel) => {
          const panelName = getPanelName(panel);
          if (!collapsiblePanelNames.has(panelName)) return;
          panelListExpandedState[panelName] = false;
          ensurePanelListToggle(panel);
        });
        resetTabs();
        const requestedTab = window.__dashboardRequestedSettingsTab || '';
        if (requestedTab && panels.some((panel) => panel.getAttribute('data-settings-panel') === requestedTab)) {
          activateTab(requestedTab);
        }
        window.__dashboardRequestedSettingsTab = '';
      });
    };

    window.setTimeout(openModalFromQuery, 0);
    setupSettingsTabs();
  })();

  /**
   * setupCompanyActions
   * Attach lightweight event handlers to directory/company cards. Actions
   * call `AppAPI` methods that perform API requests and then react to the
   * returned JSON (showing alerts or reloading when necessary). This code
   * intentionally keeps interactions simple and synchronous for the demo.
   */
  (function setupCompanyActions(){
    if (!window.AppAPI) return;
    const feedback = window.DashboardFeedback;
    const notifyError = (message) => {
      if (feedback?.error) {
        feedback.error('Oops!', message);
        return;
      }
      console.error(message);
    };
    const notifySuccess = (message) => {
      if (feedback?.success) {
        feedback.success('Done', message);
      }
    };
    const confirmAction = async (message) => {
      if (feedback?.confirm) {
        return feedback.confirm(message, tr('Confirm action', 'Confirmer l action', 'Conferma azione'));
      }
      notifyError(tr('Confirmation dialog is not available.', 'La boite de confirmation n est pas disponible.', 'La finestra di conferma non è disponibile.'));
      return false;
    };

    document.querySelectorAll('.dashboard-directory-card').forEach(card => {
      const companyId = card.getAttribute('data-company-id');
      if (!companyId) return;
      card.querySelectorAll('.company-actions [data-action]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const action = btn.getAttribute('data-action');
          try {
            if (action === 'set-ip') {
              const ip = prompt(tr('Signature IP address (leave blank to remove):', 'Adresse IP de signature (laisser vide pour supprimer) :', 'Indirizzo IP di firma (lascia vuoto per rimuovere):'));
              if (ip === null) return;
              const j = await AppAPI.companies.setSignatureIp(apiCompanies, companyId, ip);
              if (!j.ok) notifyError(tr('Error: ', 'Erreur : ', 'Errore: ') + (j.error || tr('unknown', 'inconnue', 'sconosciuto'))); else notifySuccess(tr('Company Wi-Fi IP updated.', 'IP Wi-Fi entreprise mise a jour.', 'IP Wi-Fi aziendale aggiornato.'));
              return;
            }

            if (action === 'delete') {
              if (!await confirmAction(tr('Confirm deletion of this company?', 'Confirmer la suppression de cette entreprise ?', 'Confermare l\'eliminazione di questa azienda?'))) return;
              const j = await AppAPI.companies.delete(apiCompanies, companyId);
              if (!j.ok) notifyError(tr('Error: ', 'Erreur : ', 'Errore: ') + (j.error || tr('unknown', 'inconnue', 'sconosciuto'))); else location.reload();
              return;
            }

            if (action === 'manage-departments') {
              const j = await AppAPI.departments.list(apiDepartments, companyId);
              if (!j.ok) { notifyError('Error: ' + (j.error || 'unknown')); return; }
              const list = j.departments.map(d => `${d.id}: ${d.name}`).join('\n') || tr('No departments', 'Aucun departement', 'Nessun dipartimento');
              const cmd = prompt(tr('Departments:\n', 'Departements :\n', 'Dipartimenti:\n') + list + tr('\n\nTo create: type a new name. To delete: del:<id>', '\n\nPour creer : tapez un nouveau nom. Pour supprimer : del:<id>', '\n\nPer creare: digita un nuovo nome. Per eliminare: del:<id>'));
              if (!cmd) return;
              if (cmd.startsWith('del:')) {
                const id = cmd.split(':')[1];
                const jr = await AppAPI.departments.delete(apiDepartments, id);
                if (!jr.ok) notifyError('Error: ' + (jr.error || 'unknown')); else location.reload();
              } else {
                const jr = await AppAPI.departments.create(apiDepartments, companyId, cmd);
                if (!jr.ok) notifyError('Error: ' + (jr.error || 'unknown')); else location.reload();
              }
              return;
            }

            if (action === 'manage-employees') {
              const j = await AppAPI.users.listByCompany(apiUsers, companyId);
              if (!j.ok) { notifyError('Error: ' + (j.error || 'unknown')); return; }
              const list = j.users.map(u => `${u.id}: ${u.first_name} ${u.last_name} (${u.role})`).join('\n') || tr('No employees', 'Aucun employe', 'Nessun dipendente');
              const cmd = prompt(tr('Employees:\n', 'Employes :\n', 'Dipendenti:\n') + list + tr('\n\nTo create: new:First Last,email,role. To delete: del:<id>', '\n\nPour creer : new:Prenom Nom,email,role. Pour supprimer : del:<id>', '\n\nPer creare: new:Nome Cognome,email,ruolo. Per eliminare: del:<id>'));
              if (!cmd) return;
              if (cmd.startsWith('del:')) {
                const id = cmd.split(':')[1];
                const jr = await AppAPI.users.delete(apiUsers, id);
                if (!jr.ok) notifyError(tr('Error: ', 'Erreur : ', 'Errore: ') + (jr.error || tr('unknown', 'inconnue', 'sconosciuto'))); else location.reload();
              } else if (cmd.startsWith('new:')) {
                const payload = cmd.substring(4).split(',');
                const name = payload[0] || ''; const email = payload[1] || ''; const role = payload[2] || 'employee';
                const names = name.split(' '); const first = names.shift(); const last = names.join(' ') || '';
                const jr = await AppAPI.users.create(apiUsers, { department_id: null, first_name: first, last_name: last, email, role });
                if (!jr.ok) notifyError(tr('Error: ', 'Erreur : ', 'Errore: ') + (jr.error || tr('unknown', 'inconnue', 'sconosciuto'))); else location.reload();
              }
              return;
            }

            if (action === 'assign-head') { notifyError(tr('Use the Manage Employees flow and assign-head through the next UI step.', 'Utilisez le flux Gerer les employes puis assignez le responsable a l etape suivante.', 'Usa il flusso Gestisci dipendenti e assegna il responsabile nel passaggio successivo.')); return; }
            if (action === 'edit') { notifyError(tr('Edit company UI is not implemented yet.', 'L interface de modification entreprise n est pas encore implementee.', 'L\'interfaccia di modifica azienda non è ancora implementata.')); return; }

          } catch (err) { notifyError(tr('Network error: ', 'Erreur reseau : ', 'Errore di rete: ') + err.message); }
        });
      });
    });
  })();

  /**
   * setupSidebarAndCalendar()
   * Wire the hover sidebar, the calendar navigator, the planner view, and
   * drag-and-drop shift assignments.
   */
  (function setupSidebarAndCalendar(){
    const feedback = window.DashboardFeedback;
    const notifyError = (message) => {
      if (feedback?.error) {
        feedback.error(tr('Oops!', 'Erreur', 'Errore'), message);
        return;
      }
      console.error(message);
    };
    const notifySuccess = (message) => {
      if (feedback?.success) {
        feedback.success(tr('Done', 'Termine', 'Fatto'), message);
        return;
      }
      console.info(message);
    };
    const sidebar = document.getElementById('dashboard-sidebar');
    const sidebarHandle = document.querySelector('[data-sidebar-hover-handle]');
    const navigatorToggleButtons = document.querySelectorAll('[data-calendar-navigator-toggle]');
    const navigatorPanel = document.getElementById('dashboard-calendar-navigator');
    const calendarShell = document.querySelector('[data-dashboard-calendar-shell]');
    const calendarSection = calendarShell ? calendarShell.closest('.dashboard-calendar-shell') : null;
    const calendarDetail = document.querySelector('[data-calendar-detail]');
    const plannerDetail = document.querySelector('[data-sidebar-planner-detail]');
    const plannerDepartmentButtons = document.querySelectorAll('[data-planner-department-id]');
    const plannerData = window.DashboardPlannerData || {};
    const apiDashboard = (window.DashboardConfig || {}).apiDashboard;
    const RULES_STORAGE_KEY = 'staffease:auto-assign-rules:v1';
    const SIDEBAR_PLAN_WIDE_CLASS = 'sidebar-plan-wide';

    document.body.classList.remove('sidebar-expanded');
    document.body.classList.remove(SIDEBAR_PLAN_WIDE_CLASS);

    if (!sidebar && !calendarShell && !navigatorPanel) {
      return;
    }

    const safeParseJson = (value, fallback) => {
      try {
        return JSON.parse(value);
      } catch (error) {
        return fallback;
      }
    };

    const loadAssignmentRules = () => {
      try {
        const raw = window.localStorage.getItem(RULES_STORAGE_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (_error) {
        return {};
      }
    };

    const saveAssignmentRules = (rules) => {
      try {
        window.localStorage.setItem(RULES_STORAGE_KEY, JSON.stringify(rules && typeof rules === 'object' ? rules : {}));
      } catch (_error) {
        // Ignore storage failures.
      }
    };

    const toLocalDate = (value) => {
      if (!value) return new Date();
      const candidate = new Date(`${value}T12:00:00`);
      return Number.isNaN(candidate.getTime()) ? new Date() : candidate;
    };

    const pad = (value) => String(value).padStart(2, '0');
    const dateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const addDays = (date, amount) => {
      const next = new Date(date);
      next.setDate(next.getDate() + amount);
      return next;
    };
    const addMonths = (date, amount) => {
      const next = new Date(date);
      next.setMonth(next.getMonth() + amount);
      return next;
    };
    const addYears = (date, amount) => {
      const next = new Date(date);
      next.setFullYear(next.getFullYear() + amount);
      return next;
    };
    const startOfWeek = (date) => {
      const start = new Date(date);
      const day = start.getDay();
      const offset = day === 0 ? -6 : 1 - day;
      start.setDate(start.getDate() + offset);
      return start;
    };
    const localeForDates = isFr ? 'fr-FR' : (isIt ? 'it-IT' : 'en-US');
    const monthNames = Array.from({ length: 12 }, (_, index) => new Intl.DateTimeFormat(localeForDates, { month: 'short' }).format(new Date(2024, index, 1)));
    const weekdayNames = Array.from({ length: 7 }, (_, index) => new Intl.DateTimeFormat(localeForDates, { weekday: 'short' }).format(new Date(2024, 0, 1 + index)));
    const fullDateFormatter = new Intl.DateTimeFormat(localeForDates, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    const weekdayShortFormatter = new Intl.DateTimeFormat(localeForDates, { weekday: 'short' });
    const shortDateFormatter = new Intl.DateTimeFormat(isFr ? 'fr-FR' : (isIt ? 'it-IT' : 'en-GB'), { day: '2-digit', month: '2-digit', year: '2-digit' });
    const monthYearFormatter = new Intl.DateTimeFormat(localeForDates, { month: 'long', year: 'numeric' });
    const monthLabelFormatter = new Intl.DateTimeFormat(localeForDates, { month: 'long' });

    const rawAssignments = Array.isArray(plannerData.assignments) ? plannerData.assignments : [];
    const attendances = Array.isArray(plannerData.attendances) ? plannerData.attendances : [];
    const departments = Array.isArray(plannerData.departments) ? plannerData.departments : [];
    const calendarToday = toLocalDate(plannerData.today || (calendarShell ? calendarShell.getAttribute('data-calendar-today') : ''));
    const events = rawAssignments.map((item) => ({ ...item }));
    let nextAssignmentId = events.reduce((max, item) => Math.max(max, Number(item.assignment_id || 0)), 0) + 1;

    const initialCalendarMode = ['day', 'week', 'fortnight', 'month', 'year'].includes(plannerData.mode) ? plannerData.mode : 'week';

    const state = {
      mode: initialCalendarMode === 'day' ? 'week' : initialCalendarMode,
      navigationMode: initialCalendarMode,
      focusDate: calendarToday,
      selectedDate: calendarToday,
      activeDepartmentId: Number(plannerData.active_department_id || departments[0]?.id || 0),
      activeShiftId: Number(plannerData.active_shift_id || 0),
      activeUserId: 0,
      activeUserName: '',
      calendarExpanded: false,
      draggingUserId: null,
      draggingAssignmentId: null,
    };

    const sidebarPlanState = {
      departmentId: 0,
      weekdayAssignments: {},
      periodMode: 'auto',
      weekStart: dateKey(startOfWeek(calendarToday)),
      monthKey: dateKey(calendarToday).slice(0, 7),
      allowOverride: false,
      preview: [],
      summary: null,
      dayOverrides: {},
      applying: false,
    };

    const publishPlannerRuntime = () => {
      window.DashboardPlannerRuntime = {
        getState: () => ({
          mode: state.mode,
          navigationMode: state.navigationMode,
          focusDate: new Date(state.focusDate),
          selectedDate: new Date(state.selectedDate),
          activeDepartmentId: Number(state.activeDepartmentId || 0),
          activeShiftId: Number(state.activeShiftId || 0),
          activeUserId: Number(state.activeUserId || 0),
          activeUserName: String(state.activeUserName || ''),
          calendarExpanded: !!state.calendarExpanded,
        }),
        getDepartments: () => (departments || []).slice(),
        getEvents: () => (events || []).slice(),
      };
      document.dispatchEvent(new CustomEvent('dashboard:planner-updated'));
    };

    const eventDateKey = (event) => event.work_date || '';
    const eventsByDate = () => events.reduce((map, event) => {
      const key = eventDateKey(event);
      if (!key) return map;
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(event);
      return map;
    }, new Map());

    const getDepartmentById = (departmentId) => departments.find((department) => Number(department.id) === Number(departmentId)) || departments[0] || null;
    const getActiveDepartment = () => getDepartmentById(state.activeDepartmentId);
    const getActiveShifts = () => (getActiveDepartment()?.shifts || []);
    const getActiveUsers = () => (getActiveDepartment()?.users || []);
    const getActiveShift = () => getActiveShifts().find((shift) => Number(shift.id) === Number(state.activeShiftId)) || getActiveShifts()[0] || null;
    const getActiveUser = () => getActiveUsers().find((user) => Number(user.id) === Number(state.activeUserId)) || null;
    const getActiveUserForCalendar = () => {
      const user = getActiveUser();
      if (!user) {
        return null;
      }
      const fullName = String((user.first_name || '') + ' ' + (user.last_name || '')).trim();
      return {
        id: Number(user.id || 0),
        name: fullName || String(user.email || 'Employee'),
      };
    };
    const normalizeAbsenceKind = (value) => {
      const normalized = String(value || '').toLowerCase().replace(/[^a-z]/g, '');
      if (!normalized) return '';
      if (normalized === 'rest' || normalized === 'restday' || normalized === 'dayoff' || normalized === 'reposo') return 'rest';
      if (normalized === 'vacation' || normalized === 'vacations' || normalized === 'holiday' || normalized === 'holidays' || normalized === 'leave' || normalized === 'conge' || normalized === 'conges' || normalized === 'ferie') return 'vacation';
      if (normalized === 'sick' || normalized === 'sickness' || normalized === 'sickleave' || normalized === 'maladie' || normalized === 'malattia') return 'sick';
      return normalized;
    };

    const getAbsenceTemplateShift = (kind) => {
      const targetKind = normalizeAbsenceKind(kind);
      if (!targetKind) return null;
      return getActiveShifts().find((shift) => normalizeAbsenceKind(shift?.kind || '') === targetKind) || null;
    };

    const getAbsenceTemplateShiftId = (kind) => {
      const match = getAbsenceTemplateShift(kind);
      return Number(match?.id || 0);
    };

    const resolveUserRecordById = (userId) => {
      const normalizedUserId = Number(userId || 0);
      if (!normalizedUserId) return null;

      const plannerUser = Array.isArray(plannerData?.users)
        ? plannerData.users.find((entry) => Number(entry?.id || 0) === normalizedUserId)
        : null;
      if (plannerUser) return plannerUser;

      for (const department of departments) {
        const users = Array.isArray(department?.users) ? department.users : [];
        const match = users.find((entry) => Number(entry?.id || 0) === normalizedUserId);
        if (match) return match;
      }

      return null;
    };

    const getUserAvailabilityStatus = (userId, slotDate) => {
      const normalizedUserId = Number(userId || 0);
      const normalizedDate = String(slotDate || '').trim();
      if (!normalizedUserId || !/^\d{4}-\d{2}-\d{2}$/.test(normalizedDate)) {
        return { available: true, reason: '', reasonCode: '' };
      }

      const assignedOnDate = events.find((item) =>
        Number(item.user_id || 0) === normalizedUserId
        && String(item.work_date || '') === normalizedDate
        && String(item.status || '').toLowerCase() !== 'cancelled'
      );
      if (assignedOnDate) {
        const assignedKind = String(assignedOnDate.shift_kind || 'work').toLowerCase();
        if (assignedKind === 'rest') {
          return { available: false, reason: 'Unavailable: rest day.', reasonCode: 'rest' };
        }
        if (assignedKind === 'sick') {
          return { available: false, reason: 'Unavailable: sick leave.', reasonCode: 'sick' };
        }
        if (assignedKind === 'vacation') {
          return { available: false, reason: 'Unavailable: vacation.', reasonCode: 'vacation' };
        }
        return { available: false, reason: 'Unavailable: employee already assigned for this day.', reasonCode: 'assigned' };
      }

      const rules = loadAssignmentRules();
      const rule = rules[String(normalizedUserId)] || rules[normalizedUserId] || null;
      if (!rule || typeof rule !== 'object') {
        return { available: true, reason: '' };
      }

      const slotMonth = normalizedDate.slice(0, 7);
      const currentMonth = dateKey(calendarToday).slice(0, 7);
      const nextMonthDate = new Date(calendarToday.getFullYear(), calendarToday.getMonth() + 1, 1, 12, 0, 0, 0);
      const nextMonth = dateKey(nextMonthDate).slice(0, 7);
      const scope = String(rule.scope || 'all');
      if (scope === 'current' && slotMonth !== currentMonth) {
        return { available: true, reason: '', reasonCode: '' };
      }
      if (scope === 'next' && slotMonth < nextMonth) {
        return { available: true, reason: '', reasonCode: '' };
      }

      const specialDates = Array.isArray(rule.special_dates) ? rule.special_dates : [];
      const specialMatch = specialDates.find((item) => String(item?.date || '') === normalizedDate);
      if (specialMatch) {
        const reason = String(specialMatch.reason || 'special').toLowerCase();
        if (reason === 'rest') {
          return { available: false, reason: 'Unavailable: rest day.', reasonCode: 'rest' };
        }
        if (reason === 'sick') {
          return { available: false, reason: 'Unavailable: sick leave.', reasonCode: 'sick' };
        }
        if (reason === 'vacation') {
          return { available: false, reason: 'Unavailable: vacation.', reasonCode: 'vacation' };
        }
        if (reason === 'leave') {
          return { available: false, reason: 'Unavailable: leave.', reasonCode: 'leave' };
        }
        return { available: false, reason: 'Unavailable.', reasonCode: reason || 'special' };
      }

      const offWeekdays = Array.isArray(rule.off_weekdays) ? rule.off_weekdays.map((value) => Number(value)) : [];
      const weekday = new Date(`${normalizedDate}T12:00:00`).getDay();
      if (offWeekdays.includes(weekday)) {
        return { available: false, reason: 'Unavailable: rest day.', reasonCode: 'rest' };
      }

      return { available: true, reason: '', reasonCode: '' };
    };

    const isUserAvailableForDate = (userId, slotDate) => getUserAvailabilityStatus(userId, slotDate).available;

    const getSuggestedAssignableUser = (slotDate, shiftId, departmentId, preferredUserId) => {
      const normalizedDate = String(slotDate || '').trim();
      const normalizedDepartmentId = Number(departmentId || 0);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(normalizedDate)) return null;

      const seen = new Set();
      const candidates = [];
      const pushCandidatesFromDepartment = (department, priority) => {
        const users = Array.isArray(department?.users) ? department.users : [];
        users.forEach((user) => {
          const userId = Number(user?.id || 0);
          if (!userId || seen.has(userId)) return;
          seen.add(userId);
          const fullName = `${user?.first_name || ''} ${user?.last_name || ''}`.trim() || `${tr('Employee', 'Employe', 'Dipendente')} #${userId}`;
          candidates.push({
            id: userId,
            name: fullName,
            role: String(user?.role || '').toLowerCase(),
            departmentId: Number(department?.id || 0),
            departmentName: String(department?.name || tr('Department', 'Departement', 'Dipartimento')),
            priority,
          });
        });
      };

      const targetDepartment = getDepartmentById(normalizedDepartmentId);
      if (targetDepartment) {
        pushCandidatesFromDepartment(targetDepartment, 2);
      }
      departments.forEach((department) => {
        if (Number(department?.id || 0) === normalizedDepartmentId) return;
        pushCandidatesFromDepartment(department, 1);
      });

      const preferredId = Number(preferredUserId || 0);
      const preferredUser = resolveUserRecordById(preferredId);
      const preferredRole = String(preferredUser?.role || '').toLowerCase();
      const preferredAvailability = preferredId > 0
        ? getUserAvailabilityStatus(preferredId, normalizedDate)
        : { available: true, reason: '', reasonCode: '' };
      const isPreferredAdmin = preferredRole === 'admin';
      const adminReplacementAllowed = preferredAvailability.reasonCode === 'sick' || preferredAvailability.reasonCode === 'vacation';

      if (isPreferredAdmin && !adminReplacementAllowed) {
        return null;
      }

      let best = null;
      candidates.forEach((candidate) => {
        if (isPreferredAdmin && adminReplacementAllowed && candidate.role !== 'department_manager') {
          return;
        }
        if (!isUserAvailableForDate(candidate.id, normalizedDate)) {
          return;
        }
        const preferredBoost = candidate.id === preferredId ? 10 : 0;
        const score = Number(candidate.priority || 0) + preferredBoost;
        if (!best || score > best.score) {
          best = { score, candidate };
        }
      });

      return best ? best.candidate : null;
    };

    const setActiveDepartment = (departmentId) => {
      const department = getDepartmentById(departmentId);
      if (!department) return;
      state.activeDepartmentId = Number(department.id);
      const workShifts = (department.shifts || []).filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const firstShift = workShifts[0] || (department.shifts || [])[0] || null;
      state.activeShiftId = firstShift ? Number(firstShift.id) : 0;
      const nextUser = (department.users || []).find((user) => Number(user.id) === Number(state.activeUserId));
      state.activeUserId = nextUser ? Number(nextUser.id) : 0;
      state.activeUserName = nextUser ? `${nextUser.first_name || ''} ${nextUser.last_name || ''}`.trim() : '';
      renderSidebarPlanner();
      renderCalendar();
    };

    const setActiveShift = (shiftId) => {
      state.activeShiftId = Number(shiftId);
      renderSidebarPlanner();
      renderCalendar();
    };

    const setActiveUser = (userId, userName) => {
      const normalizedId = Number(userId || 0);
      if (normalizedId > 0 && normalizedId === Number(state.activeUserId || 0)) {
        state.activeUserId = 0;
        state.activeUserName = '';
      } else {
        state.activeUserId = normalizedId;
        state.activeUserName = String(userName || '').trim();
      }
      renderSidebarPlanner();
      renderCalendar();
    };

    const sortDepartmentUsers = (users) => {
      return (Array.isArray(users) ? users : []).slice().sort((left, right) => {
        const leftName = `${left?.first_name || ''} ${left?.last_name || ''}`.trim().toLowerCase();
        const rightName = `${right?.first_name || ''} ${right?.last_name || ''}`.trim().toLowerCase();
        return leftName.localeCompare(rightName);
      });
    };

    const transferEmployeeToDepartment = async ({ userId, targetDepartmentId, targetDepartmentName }) => {
      const normalizedUserId = Number(userId || 0);
      const normalizedTargetDepartmentId = Number(targetDepartmentId || 0);
      if (!normalizedUserId || !normalizedTargetDepartmentId) return;

      const currentRole = String(currentUser?.role || '').toLowerCase();
      if (!['super_admin', 'admin'].includes(currentRole)) {
        notifyError(tr('Only admin profiles can transfer employees between departments.', 'Seuls les profils admin peuvent transferer des employes entre departements.', 'Solo i profili admin possono trasferire dipendenti tra dipartimenti.'));
        return;
      }

      if (!apiUsers || !window.AppAPI || typeof window.AppAPI.postJSON !== 'function') {
        notifyError(tr('Users API is not available.', 'API utilisateurs indisponible.', 'API utenti non disponibile.'));
        return;
      }

      const sourceDepartment = departments.find((department) =>
        Array.isArray(department?.users) && department.users.some((entry) => Number(entry?.id || 0) === normalizedUserId)
      ) || null;
      const targetDepartment = departments.find((department) => Number(department?.id || 0) === normalizedTargetDepartmentId) || null;
      if (!targetDepartment) {
        notifyError(tr('Target department not found.', 'Departement cible introuvable.', 'Dipartimento di destinazione non trovato.'));
        return;
      }
      if (sourceDepartment && Number(sourceDepartment.id || 0) === normalizedTargetDepartmentId) {
        return;
      }

      const userRecord = (plannerData.users || []).find((entry) => Number(entry?.id || 0) === normalizedUserId)
        || (sourceDepartment?.users || []).find((entry) => Number(entry?.id || 0) === normalizedUserId)
        || null;
      if (!userRecord) {
        notifyError(tr('Employee not found.', 'Employe introuvable.', 'Dipendente non trovato.'));
        return;
      }

      const userName = `${userRecord.first_name || ''} ${userRecord.last_name || ''}`.trim()
        || `${tr('Employee', 'Employe', 'Dipendente')} #${normalizedUserId}`;
      const response = await window.AppAPI.postJSON(apiUsers, {
        action: 'transfer_department',
        user_id: normalizedUserId,
        department_id: normalizedTargetDepartmentId,
      });

      if (!response || response.ok === false || response.success === false) {
        notifyError((response && (response.error || response.message))
          || tr('Unable to transfer employee.', 'Impossible de transferer l employe.', 'Impossibile trasferire il dipendente.'));
        return;
      }

      const nextDepartmentName = String(targetDepartmentName || targetDepartment?.name || tr('Department', 'Departement', 'Dipartimento'));

      departments.forEach((department) => {
        if (!Array.isArray(department.users)) {
          department.users = [];
        }
        department.users = department.users.filter((entry) => Number(entry?.id || 0) !== normalizedUserId);
      });

      const movedUser = {
        ...userRecord,
        department_id: normalizedTargetDepartmentId,
        department_ids: [normalizedTargetDepartmentId],
      };
      targetDepartment.users = sortDepartmentUsers([...(Array.isArray(targetDepartment.users) ? targetDepartment.users : []), movedUser]);

      if (Array.isArray(plannerData.users)) {
        plannerData.users = plannerData.users.map((entry) => {
          if (Number(entry?.id || 0) !== normalizedUserId) return entry;
          return {
            ...entry,
            department_id: normalizedTargetDepartmentId,
            department_ids: [normalizedTargetDepartmentId],
          };
        });
      }

      if (Number(state.activeDepartmentId || 0) !== Number(targetDepartment.id || 0) && Number(state.activeUserId || 0) === normalizedUserId) {
        state.activeUserId = 0;
        state.activeUserName = '';
      }

      renderSidebarPlanner();
      renderCalendar();
      if (feedback?.success) {
        feedback.success(tr('Done', 'Termine', 'Fatto'), tr(
          `${userName} moved to ${nextDepartmentName}.`,
          `${userName} transfere vers ${nextDepartmentName}.`,
          `${userName} trasferito in ${nextDepartmentName}.`
        ));
      }
    };

    const formatShiftTime = (shift) => {
      const start = typeof shift.start_time === 'string' ? shift.start_time.slice(0, 5) : '--:--';
      const end = typeof shift.end_time === 'string' ? shift.end_time.slice(0, 5) : '--:--';
      return `${start} - ${end}`;
    };

    const formatEventTime = (event) => formatShiftTime(event);

    const formatRangeLabel = () => {
      if (state.mode === 'year') return String(state.focusDate.getFullYear());
      if (state.mode === 'month') return monthYearFormatter.format(state.focusDate);
      if (state.mode === 'day') return fullDateFormatter.format(state.focusDate);
      const isSlidingWeek = state.mode === 'week' && state.navigationMode === 'day';
      const start = state.mode === 'fortnight'
        ? state.focusDate
        : (isSlidingWeek ? state.focusDate : startOfWeek(state.focusDate));
      const end = state.mode === 'fortnight' ? addDays(start, 14) : addDays(start, 6);
      return `${shortDateFormatter.format(start)} - ${shortDateFormatter.format(end)}`;
    };

    const getVisibleRange = () => {
      if (state.mode === 'year') {
        return { start: new Date(state.focusDate.getFullYear(), 0, 1, 12, 0, 0, 0), end: new Date(state.focusDate.getFullYear(), 11, 31, 12, 0, 0, 0) };
      }
      if (state.mode === 'month') {
        const first = startOfWeek(new Date(state.focusDate.getFullYear(), state.focusDate.getMonth(), 1, 12, 0, 0, 0));
        const last = addDays(startOfWeek(new Date(state.focusDate.getFullYear(), state.focusDate.getMonth() + 1, 0, 12, 0, 0, 0)), 6);
        return { start: first, end: last };
      }
      if (state.mode === 'fortnight') {
        const start = startOfWeek(state.focusDate);
        return { start, end: addDays(start, 14) };
      }
      if (state.mode === 'day') {
        return { start: state.selectedDate, end: state.selectedDate };
      }
      const start = state.navigationMode === 'day' ? new Date(state.focusDate) : startOfWeek(state.focusDate);
      return { start, end: addDays(start, 6) };
    };

    const formatNavigatorRange = () => {
      const range = getVisibleRange();
      return `${shortDateFormatter.format(range.start)} - ${shortDateFormatter.format(range.end)}`;
    };

    const getVisibleDateKeys = () => {
      const range = getVisibleRange();
      const keys = [];
      for (let cursor = new Date(range.start); cursor <= range.end; cursor = addDays(cursor, 1)) {
        keys.push(dateKey(cursor));
      }
      return keys;
    };

    const getDateRangeKeys = (startDate, endDate) => {
      const keys = [];
      for (let cursor = new Date(startDate); cursor <= endDate; cursor = addDays(cursor, 1)) {
        keys.push(dateKey(cursor));
      }
      return keys;
    };

    const parseDateKeyToLocalDate = (value) => {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return null;
      const parsed = new Date(`${value}T12:00:00`);
      return Number.isNaN(parsed.getTime()) ? null : parsed;
    };

    const normalizeMonthKey = (value) => {
      const normalized = String(value || '').trim();
      return /^\d{4}-\d{2}$/.test(normalized) ? normalized : dateKey(state.focusDate).slice(0, 7);
    };

    const isPastDate = (dateValue) => {
      const todayValue = dateKey(new Date());
      return String(dateValue || '') < todayValue;
    };

    const summarizeShiftName = (shift) => {
      if (!shift) return tr('Shift', 'Poste', 'Turno');
      const name = String(shift.name || tr('Shift', 'Poste', 'Turno'));
      return `${name} ${formatShiftTime(shift)}`;
    };

    const getActiveRestTemplateShift = () => {
      const activeDepartment = getActiveDepartment();
      if (!activeDepartment) return null;
      return (activeDepartment.shifts || []).find((shift) => String(shift?.kind || '').toLowerCase() === 'rest') || null;
    };

    const ensureSidebarPlanDefaults = (activeDepartment) => {
      if (!activeDepartment) return;
      const departmentId = Number(activeDepartment.id || 0);
      const workShifts = (activeDepartment.shifts || []).filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const restShift = (activeDepartment.shifts || []).find((shift) => String(shift?.kind || '').toLowerCase() === 'rest') || null;

      const buildDefaultWeekdayAssignments = () => {
        const firstShiftId = Number(workShifts[0]?.id || 0);
        const defaults = {};
        for (let weekdayIndex = 0; weekdayIndex < 7; weekdayIndex += 1) {
          if (weekdayIndex >= 5) {
            defaults[String(weekdayIndex)] = restShift ? `rest:${Number(restShift.id || 0)}` : 'rest';
          } else {
            defaults[String(weekdayIndex)] = firstShiftId > 0 ? String(firstShiftId) : 'work';
          }
        }
        return defaults;
      };

      if (sidebarPlanState.departmentId !== departmentId) {
        sidebarPlanState.departmentId = departmentId;
        sidebarPlanState.weekdayAssignments = buildDefaultWeekdayAssignments();
        sidebarPlanState.periodMode = 'auto';
        sidebarPlanState.weekStart = dateKey(startOfWeek(state.focusDate));
        sidebarPlanState.monthKey = dateKey(state.focusDate).slice(0, 7);
        sidebarPlanState.allowOverride = false;
        sidebarPlanState.preview = [];
        sidebarPlanState.summary = null;
        sidebarPlanState.dayOverrides = {};
        if (!state.activeShiftId && firstShiftId > 0) {
          state.activeShiftId = firstShiftId;
        }
        return;
      }

      if (!sidebarPlanState.weekdayAssignments || Object.keys(sidebarPlanState.weekdayAssignments).length !== 7) {
        sidebarPlanState.weekdayAssignments = buildDefaultWeekdayAssignments();
      }

      for (let weekdayIndex = 0; weekdayIndex < 7; weekdayIndex += 1) {
        const key = String(weekdayIndex);
        const currentValue = String(sidebarPlanState.weekdayAssignments[key] || '').trim();
        const isRestValue = currentValue === 'rest' || currentValue.startsWith('rest:');
        const isExistingWorkShift = workShifts.some((shift) => String(shift.id) === currentValue);
        if (!isRestValue && !isExistingWorkShift) {
          sidebarPlanState.weekdayAssignments[key] = weekdayIndex >= 5
            ? (restShift ? `rest:${Number(restShift.id || 0)}` : 'rest')
            : (String(workShifts[0]?.id || '') || 'work');
        }
      }
    };

    const getSidebarPlanRange = () => {
      const mode = String(sidebarPlanState.periodMode || 'auto');

      if (mode === 'week') {
        const weekStartDate = parseDateKeyToLocalDate(sidebarPlanState.weekStart) || startOfWeek(state.focusDate);
        const startDate = startOfWeek(weekStartDate);
        const endDate = addDays(startDate, 6);
        return { startDate, endDate, label: tr('Selected week', 'Semaine selectionnee', 'Settimana selezionata') };
      }

      if (mode === 'month') {
        const monthKey = normalizeMonthKey(sidebarPlanState.monthKey);
        const [yearRaw, monthRaw] = monthKey.split('-');
        const year = Number(yearRaw);
        const month = Number(monthRaw);
        const startDate = new Date(year, month - 1, 1, 12, 0, 0, 0);
        const endDate = new Date(year, month, 0, 12, 0, 0, 0);
        return { startDate, endDate, label: tr('Selected month', 'Mois selectionne', 'Mese selezionato') };
      }

      const range = getVisibleRange();
      return { startDate: new Date(range.start), endDate: new Date(range.end), label: tr('Current calendar view', 'Vue calendrier courante', 'Vista calendario corrente') };
    };

    const getSidebarWeekdayPlan = () => {
      const activeDepartment = getActiveDepartment();
      const workShifts = (activeDepartment?.shifts || []).filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const restShift = getActiveRestTemplateShift();
      const weekPattern = [];

      for (let weekdayIndex = 0; weekdayIndex < 7; weekdayIndex += 1) {
        const rawValue = String(sidebarPlanState.weekdayAssignments[String(weekdayIndex)] || '').trim();
        if (!rawValue) {
          weekPattern.push(null);
          continue;
        }

        if (rawValue === 'rest' || rawValue.startsWith('rest:')) {
          weekPattern.push({
            type: 'rest',
            shiftId: Number(restShift?.id || rawValue.split(':')[1] || 0),
            label: tr('Rest day', 'Repos', 'Riposo'),
          });
          continue;
        }

        const shiftId = Number(rawValue || 0);
        const shift = workShifts.find((item) => Number(item.id || 0) === shiftId) || null;
        weekPattern.push({
          type: 'work',
          shiftId,
          label: shift ? summarizeShiftName(shift) : tr('Work day', 'Jour de travail', 'Giorno di lavoro'),
        });
      }

      return weekPattern;
    };

    const syncSidebarPlanRulesForActiveUser = () => {
      const activeUser = getActiveUser();
      if (!activeUser) return;
      const rules = loadAssignmentRules();
      const userKey = String(Number(activeUser.id || 0));
      const existingRule = (rules[userKey] && typeof rules[userKey] === 'object') ? rules[userKey] : {};
      const offWeekdays = [];
      for (let weekdayIndex = 0; weekdayIndex < 7; weekdayIndex += 1) {
        const rawValue = String(sidebarPlanState.weekdayAssignments[String(weekdayIndex)] || '').trim();
        if (rawValue === 'rest' || rawValue.startsWith('rest:')) {
          offWeekdays.push((weekdayIndex + 1) % 7);
        }
      }
      rules[userKey] = {
        scope: String(existingRule.scope || 'all'),
        off_weekdays: offWeekdays,
        special_dates: Array.isArray(existingRule.special_dates) ? existingRule.special_dates : [],
        monthly_overrides: existingRule.monthly_overrides && typeof existingRule.monthly_overrides === 'object' ? existingRule.monthly_overrides : {},
        rotating: existingRule.rotating && typeof existingRule.rotating === 'object'
          ? existingRule.rotating
          : { enabled: false, start_month: dateKey(state.focusDate).slice(0, 7) },
      };
      saveAssignmentRules(rules);
    };

    const getExistingAssignmentsByUserAndDate = (userId, dateValue) => {
      return events.filter((event) =>
        Number(event.user_id || 0) === Number(userId || 0)
        && String(event.work_date || '') === String(dateValue || '')
        && String(event.status || '').toLowerCase() !== 'cancelled'
      );
    };

    const makeSidebarPlanPreview = () => {
      const activeDepartment = getActiveDepartment();
      const activeUser = getActiveUser();
      if (!activeDepartment || !activeUser) {
        return {
          items: [],
          summary: {
            total: 0,
            planned: 0,
            available: 0,
            conflicts: 0,
            past: 0,
            unavailable: 0,
            alreadyAssigned: 0,
            rangeLabel: '',
          },
          error: tr('Select an employee first.', 'Selectionnez d abord un employe.', 'Seleziona prima un dipendente.'),
        };
      }

      const workShifts = (activeDepartment.shifts || []).filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const allShifts = (activeDepartment.shifts || []).slice();
      const restShift = getActiveRestTemplateShift();
      const weekPattern = getSidebarWeekdayPlan();
      if (weekPattern.length !== 7 || weekPattern.some((entry) => !entry)) {
        return {
          items: [],
          summary: {
            total: 0,
            planned: 0,
            available: 0,
            conflicts: 0,
            past: 0,
            unavailable: 0,
            alreadyAssigned: 0,
            rangeLabel: '',
          },
          error: tr('Choose one shift or rest day for each weekday.', 'Choisissez un poste ou un repos pour chaque jour de la semaine.', 'Scegli un turno o un riposo per ogni giorno della settimana.'),
        };
      }

      const range = getSidebarPlanRange();
      const dates = getDateRangeKeys(range.startDate, range.endDate);
      const items = [];
      const summary = {
        total: dates.length,
        planned: 0,
        available: 0,
        conflicts: 0,
        past: 0,
        unavailable: 0,
        alreadyAssigned: 0,
        rangeLabel: `${range.label}: ${shortDateFormatter.format(range.startDate)} - ${shortDateFormatter.format(range.endDate)}`,
      };

      dates.forEach((dateValue) => {
        const dateObj = parseDateKeyToLocalDate(dateValue);
        if (!dateObj) return;
        const weekdayIndex = (dateObj.getDay() + 6) % 7;
        const dayPlan = weekPattern[weekdayIndex] || null;
        if (!dayPlan) return;

        const isPast = isPastDate(dateValue);
        const existingAssignments = getExistingAssignmentsByUserAndDate(activeUser.id, dateValue);
        const availability = getUserAvailabilityStatus(activeUser.id, dateValue);
        const basePlannedShift = dayPlan.type === 'work'
          ? workShifts.find((shift) => Number(shift.id) === Number(dayPlan.shiftId)) || null
          : restShift;
        const overrideValue = sidebarPlanState.dayOverrides[dateValue];
        let plannedShift = basePlannedShift;
        let targetShiftId = Number(basePlannedShift?.id || dayPlan.shiftId || 0);
        let targetShiftKind = String(dayPlan.type || 'work');

        if (overrideValue === 'skip') {
          plannedShift = null;
          targetShiftId = 0;
          targetShiftKind = 'skip';
        } else if (overrideValue !== undefined && overrideValue !== null && String(overrideValue) !== '') {
          const overrideShiftId = Number(overrideValue || 0);
          if (overrideShiftId > 0) {
            const overrideShift = allShifts.find((shift) => Number(shift.id || 0) === overrideShiftId) || null;
            if (overrideShift) {
              plannedShift = overrideShift;
              targetShiftId = Number(overrideShift.id || 0);
              targetShiftKind = String(overrideShift.kind || 'work').toLowerCase();
            }
          }
        }

        const plannedLabel = targetShiftId <= 0
          ? tr('Skip day', 'Ignorer le jour', 'Salta giorno')
          : summarizeShiftName(plannedShift);

        let status = 'available';
        let note = tr('Ready to assign.', 'Pret a assigner.', 'Pronto per l\'assegnazione.');
        let actionable = true;

        if (isPast) {
          status = 'past';
          note = tr('Past day: skipped.', 'Jour passe : ignore.', 'Giorno passato: saltato.');
          actionable = false;
          summary.past += 1;
        } else if (targetShiftId <= 0) {
          status = 'skip';
          note = tr('No assignment planned for this day.', 'Aucune affectation prevue pour ce jour.', 'Nessuna assegnazione prevista per questo giorno.');
          actionable = false;
        } else if (dayPlan.type === 'rest' && !restShift) {
          status = 'unavailable';
          note = tr('No rest shift template configured for this department.', 'Aucun modele de repos configure pour ce departement.', 'Nessun modello di riposo configurato per questo dipartimento.');
          actionable = false;
          summary.unavailable += 1;
        } else if (existingAssignments.length > 1) {
          status = 'conflict';
          note = tr('Employee already has multiple assignments on this day.', 'Employe deja affecte plusieurs fois ce jour.', 'Il dipendente ha già più assegnazioni in questo giorno.');
          actionable = !!sidebarPlanState.allowOverride;
          summary.conflicts += 1;
        } else if (existingAssignments.length === 1) {
          const existing = existingAssignments[0];
          const existingShiftId = Number(existing.shift_id || 0);
          const existingKind = String(existing.shift_kind || 'work').toLowerCase();
          const sameAsPlan = existingShiftId === targetShiftId || (targetShiftKind === 'rest' && existingKind === 'rest');
          if (sameAsPlan) {
            status = 'already';
            note = tr('Already assigned with the same plan.', 'Deja affecte avec le meme plan.', 'Già assegnato con lo stesso piano.');
            actionable = false;
            summary.alreadyAssigned += 1;
          } else {
            status = 'conflict';
            note = sidebarPlanState.allowOverride
              ? tr('Will replace existing assignment.', 'Remplacera l affectation existante.', 'Sostituirà l\'assegnazione esistente.')
              : tr('Existing assignment found. Enable override to replace.', 'Affectation existante detectee. Activez le remplacement.', 'Assegnazione esistente trovata. Attiva la sostituzione per procedere.');
            actionable = !!sidebarPlanState.allowOverride;
            summary.conflicts += 1;
          }
        } else if (targetShiftKind === 'work' && !availability.available) {
          status = 'unavailable';
          note = availability.reason || tr('Unavailable by rules.', 'Indisponible selon les regles.', 'Non disponibile secondo le regole.');
          actionable = false;
          summary.unavailable += 1;
        } else {
          const otherAssignedSameShift = events.filter((event) =>
            Number(event.user_id || 0) > 0
            && Number(event.user_id || 0) !== Number(activeUser.id || 0)
            && Number(event.shift_id || 0) === Number(targetShiftId || 0)
            && String(event.work_date || '') === String(dateValue || '')
            && String(event.status || '').toLowerCase() !== 'cancelled'
          );
          if (otherAssignedSameShift.length > 0) {
            const firstName = String(otherAssignedSameShift[0].user_name || '').trim();
            status = 'conflict';
            note = firstName
              ? tr(
                `Shift already assigned to ${firstName}.`,
                `Poste deja assigne a ${firstName}.`,
                `Turno già assegnato a ${firstName}.`
              )
              : tr('Shift already assigned to another employee.', 'Poste deja assigne a un autre employe.', 'Turno già assegnato a un altro dipendente.');
            actionable = !!sidebarPlanState.allowOverride;
            summary.conflicts += 1;
          } else {
            summary.available += 1;
          }
        }

        summary.planned += 1;
        items.push({
          date: dateValue,
          weekday: weekdayShortFormatter.format(dateObj),
          targetShiftId,
          targetShiftKind,
          targetLabel: plannedLabel,
          status,
          note,
          actionable,
          existingCount: existingAssignments.length,
        });
      });

      return { items, summary, error: '' };
    };

    const renderSidebarPlanPreview = (host, result) => {
      if (!host) return;
      const previewWrap = host.querySelector('[data-sidebar-plan-preview]');
      const summaryWrap = host.querySelector('[data-sidebar-plan-summary]');
      const applyBtn = host.querySelector('[data-sidebar-plan-apply]');
      if (!previewWrap || !summaryWrap || !applyBtn) return;

      if (result.error) {
        summaryWrap.innerHTML = `<div class="dashboard-sidebar-plan-alert is-error">${escapeHtml(result.error)}</div>`;
        previewWrap.innerHTML = '';
        applyBtn.disabled = true;
        return;
      }

      const summary = result.summary || {};
      summaryWrap.innerHTML = `
        <div class="dashboard-sidebar-plan-stats">
          <span>${escapeHtml(summary.rangeLabel || '')}</span>
          <span>${tr('Planned', 'Planifies', 'Pianificati')}: <strong>${Number(summary.planned || 0)}</strong></span>
          <span>${tr('Assignable', 'A affecter', 'Assegnabili')}: <strong>${Number(summary.available || 0)}</strong></span>
          <span>${tr('Conflicts', 'Conflits', 'Conflitti')}: <strong>${Number(summary.conflicts || 0)}</strong></span>
        </div>
      `;

      const activeDepartment = getActiveDepartment();
      const editableShifts = (activeDepartment?.shifts || []).slice();
      previewWrap.innerHTML = result.items.map((item) => {
        const statusLabel = item.status === 'available'
          ? tr('Available', 'Disponible', 'Disponibile')
          : item.status === 'already'
            ? tr('Already assigned', 'Deja affecte', 'Già assegnato')
            : item.status === 'past'
              ? tr('Past day', 'Jour passe', 'Giorno passato')
              : item.status === 'skip'
                ? tr('Skipped', 'Ignore', 'Saltato')
              : item.status === 'conflict'
                ? tr('Conflict', 'Conflit', 'Conflitto')
                : tr('Unavailable', 'Indisponible', 'Non disponibile');

        const options = [
          `<option value="skip" ${Number(item.targetShiftId || 0) <= 0 ? 'selected' : ''}>${escapeHtml(tr('Skip day', 'Ignorer le jour', 'Salta giorno'))}</option>`,
          ...editableShifts.map((shift) => {
            const shiftId = Number(shift.id || 0);
            const selected = shiftId === Number(item.targetShiftId || 0) ? 'selected' : '';
            const kind = String(shift.kind || 'work').toLowerCase();
            const shiftLabel = `${summarizeShiftName(shift)}${kind !== 'work' ? ` (${kind})` : ''}`;
            return `<option value="${shiftId}" ${selected}>${escapeHtml(shiftLabel)}</option>`;
          })
        ].join('');

        return `
          <article class="dashboard-sidebar-plan-row is-${escapeHtml(item.status)}">
            <div>
              <strong>${escapeHtml(item.date)}</strong>
              <small>${escapeHtml(String(item.weekday || ''))}</small>
            </div>
            <div>
              <strong>${escapeHtml(item.targetLabel)}</strong>
              <small>${escapeHtml(item.note || '')}</small>
              <select class="dashboard-sidebar-plan-row-select" data-sidebar-plan-row-shift="${escapeHtml(item.date)}" ${item.status === 'past' ? 'disabled' : ''}>
                ${options}
              </select>
            </div>
            <span class="dashboard-sidebar-plan-tag is-${escapeHtml(item.status)}">${escapeHtml(statusLabel)}</span>
          </article>
        `;
      }).join('') || `<div class="dashboard-sidebar-plan-alert">${tr('No days in selected range.', 'Aucun jour dans la plage selectionnee.', 'Nessun giorno nell\'intervallo selezionato.')}</div>`;

      previewWrap.querySelectorAll('[data-sidebar-plan-row-shift]').forEach((select) => {
        select.addEventListener('change', () => {
          const dateValue = String(select.getAttribute('data-sidebar-plan-row-shift') || '');
          const rawValue = String(select.value || '');
          if (!dateValue) return;
          if (rawValue === 'skip') {
            sidebarPlanState.dayOverrides[dateValue] = 'skip';
          } else {
            const shiftId = Number(rawValue || 0);
            sidebarPlanState.dayOverrides[dateValue] = shiftId > 0 ? shiftId : 'skip';
          }
          handleSidebarPlanPreview(host);
        });
      });

      const actionableCount = result.items.filter((item) => item.actionable).length;
      applyBtn.disabled = actionableCount <= 0;
      applyBtn.textContent = tr('Apply plan', 'Appliquer le plan', 'Applica piano') + ` (${actionableCount})`;
    };

    const handleSidebarPlanPreview = (host) => {
      const result = makeSidebarPlanPreview();
      sidebarPlanState.preview = Array.isArray(result.items) ? result.items : [];
      sidebarPlanState.summary = result.summary || null;
      renderSidebarPlanPreview(host, result);
    };

    const applySidebarPlan = async (host) => {
      if (sidebarPlanState.applying) return;
      const activeUser = getActiveUser();
      if (!activeUser) {
        notifyError(tr('Select an employee first.', 'Selectionnez d abord un employe.', 'Seleziona prima un dipendente.'));
        return;
      }
      if (!Array.isArray(sidebarPlanState.preview) || sidebarPlanState.preview.length === 0) {
        notifyError(tr('Generate a preview before applying.', 'Generez un apercu avant d appliquer.', 'Genera un\'anteprima prima di applicare.'));
        return;
      }

      const tasks = sidebarPlanState.preview.filter((item) => item.actionable && Number(item.targetShiftId || 0) > 0);
      if (!tasks.length) {
        notifyError(tr('No assignable days in the current preview.', 'Aucun jour affectable dans l apercu courant.', 'Nessun giorno assegnabile nell\'anteprima corrente.'));
        return;
      }

      const applyBtn = host?.querySelector('[data-sidebar-plan-apply]') || null;
      if (applyBtn) applyBtn.disabled = true;
      sidebarPlanState.applying = true;
      let successCount = 0;
      let errorCount = 0;

      for (const task of tasks) {
        try {
          await assignShift({
            user_id: Number(activeUser.id || 0),
            shift_id: Number(task.targetShiftId || 0),
            work_date: task.date,
            status: 'assigned',
            force_override: sidebarPlanState.allowOverride ? 1 : 0,
          });
          successCount += 1;
        } catch (_error) {
          errorCount += 1;
        }
      }

      sidebarPlanState.applying = false;
      if (successCount > 0) {
        notifySuccess(tr(
          `Shift plan applied on ${successCount} day(s).`,
          `Plan de postes applique sur ${successCount} jour(s).`,
          `Piano turni applicato su ${successCount} giorno/i.`
        ));
      }
      if (errorCount > 0) {
        notifyError(tr(
          `${errorCount} day(s) could not be assigned.`,
          `${errorCount} jour(s) n ont pas pu etre affectes.`,
          `Impossibile assegnare ${errorCount} giorno/i.`
        ));
      }

      renderSidebarPlanner();
      renderCalendar();
    };

    const clearAssignmentsForActiveUserInPlanRange = async () => {
      if (!apiDashboard || !window.AppAPI) {
        notifyError(tr('Dashboard API is not available.', 'API dashboard indisponible.', 'API dashboard non disponibile.'));
        return;
      }
      const activeUser = getActiveUser();
      if (!activeUser) {
        notifyError(tr('Select an employee first.', 'Selectionnez d abord un employe.', 'Seleziona prima un dipendente.'));
        return;
      }

      const range = getSidebarPlanRange();
      const start = dateKey(range.startDate);
      const end = dateKey(range.endDate);
      const canClear = feedback?.confirm
        ? await feedback.confirm(
          tr(
            `Clear all assignments for ${activeUser.first_name || ''} ${activeUser.last_name || ''} in selected period?`,
            `Effacer toutes les affectations pour ${activeUser.first_name || ''} ${activeUser.last_name || ''} sur la periode selectionnee ?`,
            `Cancellare tutte le assegnazioni di ${activeUser.first_name || ''} ${activeUser.last_name || ''} nel periodo selezionato?`
          ),
          tr('Confirm action', 'Confirmer l action', 'Conferma azione')
        )
        : window.confirm(tr('Clear all assignments for selected employee in this period?', 'Effacer toutes les affectations de l employe selectionne sur cette periode ?', 'Cancellare tutte le assegnazioni del dipendente selezionato in questo periodo?'));

      if (!canClear) return;

      try {
        const response = await AppAPI.postJSON(apiDashboard, {
          action: 'clear_assignments_scope',
          target_user_id: Number(activeUser.id || 0),
          scope_shift_id: 0,
          allowed_shift_ids: [],
          include_rest_assignments: true,
          range_start: start,
          range_end: end,
        });

        if (!response || response.ok === false || response.success === false) {
          throw new Error(response?.error || response?.message || tr('Unable to clear assignments.', 'Impossible d effacer les affectations.', 'Impossibile cancellare le assegnazioni.'));
        }

        const clearedCount = Number(response.cleared_count || 0);
        if (clearedCount > 0) {
          events.forEach((event) => {
            if (
              Number(event.user_id || 0) === Number(activeUser.id || 0)
              && String(event.work_date || '') >= start
              && String(event.work_date || '') <= end
            ) {
              event.user_id = 0;
              event.user_name = '';
              event.status = 'open';
              event.assignment_source = 'open';
            }
          });
        }

        sidebarPlanState.preview = [];
        sidebarPlanState.summary = null;
        notifySuccess(tr(
          `Cleared ${clearedCount} assignment(s) for selected employee.`,
          `${clearedCount} affectation(s) effacee(s) pour l employe selectionne.`,
          `${clearedCount} assegnazione/i cancellata/e per il dipendente selezionato.`
        ));
        renderSidebarPlanner();
        renderCalendar();
      } catch (error) {
        notifyError((error && error.message) || tr('Unable to clear assignments.', 'Impossible d effacer les affectations.', 'Impossibile cancellare le assegnazioni.'));
      }
    };

    const setExpandedPlanStep = (planFlow, stepValue) => {
      if (!planFlow) return;
      const activeUser = getActiveUser();
      const weekdayAssignments = sidebarPlanState.weekdayAssignments && typeof sidebarPlanState.weekdayAssignments === 'object'
        ? Object.values(sidebarPlanState.weekdayAssignments).filter((value) => String(value || '').trim() !== '')
        : [];
      const distributionValid = weekdayAssignments.length === 7;
      const hasPreview = Array.isArray(sidebarPlanState.preview) && sidebarPlanState.preview.length > 0;
      const maxUnlockedStep = !activeUser ? 1 : (!distributionValid ? 2 : (!hasPreview ? 3 : 4));

      const requestedStep = Number(stepValue || 0);
      const normalizedRequested = Number.isFinite(requestedStep) ? requestedStep : 0;
      const resolvedStep = normalizedRequested > 0 ? Math.min(normalizedRequested, maxUnlockedStep) : 0;

      const steps = Array.from(planFlow.querySelectorAll('[data-plan-step]'));
      steps.forEach((step) => {
        const stepNo = Number(step.getAttribute('data-plan-step') || '0');
        const isLocked = stepNo > maxUnlockedStep;
        const isExpanded = stepNo === resolvedStep;
        step.classList.toggle('is-expanded', isExpanded);
        step.classList.toggle('is-locked', isLocked);
        const titleButton = step.querySelector('[data-sidebar-plan-step-title]');
        if (titleButton) {
          titleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
          titleButton.setAttribute('aria-disabled', isLocked ? 'true' : 'false');
          titleButton.tabIndex = isLocked ? -1 : 0;
        }
      });
      document.body.classList.remove(SIDEBAR_PLAN_WIDE_CLASS);
    };

    const getCalendarCounters = () => {
      const activeDepartment = getActiveDepartment();
      if (!activeDepartment) {
        return { title: tr('Calendar', 'Calendrier', 'Calendario'), totalShifts: 0, assignedShifts: 0, freeShifts: 0 };
      }

      const shifts = (activeDepartment.shifts || []).filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const visibleDateKeys = new Set(getVisibleDateKeys());
      const departmentAssignments = events.filter((event) => Number(event.department_id) === Number(activeDepartment.id));
      const assignedShifts = departmentAssignments.filter((event) => {
        if (!visibleDateKeys.has(event.work_date || '')) return false;
        return Number(event.user_id || 0) > 0;
      }).length;
      const totalShifts = shifts.length * visibleDateKeys.size;
      const freeShifts = Math.max(totalShifts - assignedShifts, 0);

      const departmentName = activeDepartment.name || 'Department';
      const departmentIcon = (activeDepartment.icon || '').toString().trim();
      const departmentColor = (activeDepartment.color || '#b98b12').toString();

      return {
        title: departmentName,
        titleIcon: departmentIcon,
        titleColor: departmentColor,
        totalShifts,
        assignedShifts,
        freeShifts,
      };
    };

    const updateChrome = () => {
      if (calendarShell) {
        calendarShell.dataset.calendarView = state.mode;
      }

      document.querySelectorAll('[data-calendar-mode]').forEach((button) => {
        const buttonMode = button.getAttribute('data-calendar-mode');
        const isActive = buttonMode === 'day'
          ? state.navigationMode === 'day'
          : (buttonMode === state.mode && state.navigationMode !== 'day');
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });

      document.querySelectorAll('[data-calendar-navigator-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', navigatorPanel && navigatorPanel.classList.contains('is-open') ? 'true' : 'false');
      });

      if (calendarSection) {
        const title = calendarSection.querySelector('[data-calendar-title]');
        const stats = calendarSection.querySelector('[data-calendar-stats]');
        const counters = getCalendarCounters();
        if (title) {
          title.innerHTML = renderDepartmentTitleHtml(counters.titleIcon || '', counters.title || '', counters.titleColor || '');
          title.style.color = counters.titleColor || '';
        }
        if (stats) {
          stats.textContent = isFr
            ? `${counters.totalShifts} postes • ${counters.assignedShifts} attribues • ${counters.freeShifts} libres`
            : `${counters.totalShifts} shifts • ${counters.assignedShifts} assigned • ${counters.freeShifts} free`;
        }
      }

      const navigatorRange = document.querySelector('[data-calendar-range-display]');
      if (navigatorRange) navigatorRange.value = formatNavigatorRange();
    };

    const calendarRenderer = (window.DashboardCalendarRenderer && typeof window.DashboardCalendarRenderer.create === 'function')
      ? window.DashboardCalendarRenderer.create({
        state,
        events,
        calendarToday,
        calendarShell,
        calendarDetail,
        monthNames,
        addDays,
        dateKey,
        startOfWeek,
        toLocalDate,
        pad,
        formatEventTime,
        fullDateFormatter,
        monthLabelFormatter,
        updateChrome,
        getActiveDepartment,
        isUserAvailableForDate,
        getUserAvailabilityStatus,
        getSuggestedAssignableUser,
        getVisibleDateKeys,
      })
      : null;

    const renderCalendar = () => {
      if (calendarRenderer && typeof calendarRenderer.renderCalendar === 'function') {
        calendarRenderer.renderCalendar();
      }
      publishPlannerRuntime();
    };

    const syncPlannerSelection = () => {
      plannerDepartmentButtons.forEach((button) => {
        const isActive = Number(button.getAttribute('data-planner-department-id')) === Number(state.activeDepartmentId);
        button.classList.toggle('is-active', isActive);
      });
    };

    const renderSidebarPlanner = () => {
      syncPlannerSelection();
      if (!plannerDetail) return;
      const activeDepartment = getActiveDepartment();
      if (!activeDepartment) {
        plannerDetail.innerHTML = `<div class="dashboard-sidebar-planner-placeholder">${tr('No departments available.', 'Aucun departement disponible.', 'Nessun dipartimento disponibile.')}</div>`;
        return;
      }

      const users = activeDepartment.users || [];
      const shifts = activeDepartment.shifts || [];
      const workShifts = shifts.filter((shift) => String(shift?.kind || 'work').toLowerCase() === 'work');
      const restShift = getActiveRestTemplateShift();

      ensureSidebarPlanDefaults(activeDepartment);
      if (workShifts.length > 0 && !workShifts.some((shift) => Number(shift.id) === Number(state.activeShiftId))) {
        state.activeShiftId = Number(workShifts[0].id || 0);
      }
      const deptName = activeDepartment.name || tr('Department', 'Departement', 'Dipartimento');
      const deptIcon = (activeDepartment.icon || '').toString().trim() || '🏷️';
      const deptColor = (activeDepartment.color || '#b98b12').toString();
      const activeShift = getActiveShift();
      const activeUser = getActiveUser();
      const activeUserName = activeUser
        ? `${activeUser.first_name || ''} ${activeUser.last_name || ''}`.trim() || `${tr('Employee', 'Employe', 'Dipendente')} #${activeUser.id}`
        : '';
      const periodOptions = [
        { value: 'auto', label: tr('Current calendar view', 'Vue calendrier courante', 'Vista calendario corrente') },
        { value: 'week', label: tr('Specific week', 'Semaine specifique', 'Settimana specifica') },
        { value: 'month', label: tr('Full month', 'Mois complet', 'Mese completo') },
      ];
      const weekdayLabels = [
        tr('Monday', 'Lundi', 'Lunedì'),
        tr('Tuesday', 'Mardi', 'Martedì'),
        tr('Wednesday', 'Mercredi', 'Mercoledì'),
        tr('Thursday', 'Jeudi', 'Giovedì'),
        tr('Friday', 'Vendredi', 'Venerdì'),
        tr('Saturday', 'Samedi', 'Sabato'),
        tr('Sunday', 'Dimanche', 'Domenica'),
      ];
      plannerDetail.innerHTML = `
        <div class="dashboard-sidebar-planner-title">
          <span style="color:${escapeHtml(deptColor)}">${renderIconHtml(deptIcon, deptColor)} ${escapeHtml(deptName)}</span>
          <span>${users.length} ${tr('staff', 'personnel', 'personale')}</span>
        </div>
        <div class="dashboard-sidebar-planner-description">${activeDepartment.description || tr('Assigned team and shift list.', 'Equipe assignee et liste des postes.', 'Team assegnato ed elenco dei turni.')}</div>
        <div>
          <div class="dashboard-sidebar-group-title"><span>👤</span> ${tr('Employees', 'Employes', 'Dipendenti')}</div>
          <div class="dashboard-sidebar-chip-group">
            ${users.length ? users.map((user) => {
              const userId = Number(user.id || 0);
              const userName = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `${tr('Employee', 'Employe', 'Dipendente')} #${userId}`;
              const isActiveUser = userId === Number(state.activeUserId || 0);
              return `
              <article class="dashboard-sidebar-user-card ${isActiveUser ? 'is-active' : ''}" data-sidebar-user-card="${userId}" data-user-id="${userId}" data-user-name="${userName}">
                <button type="button" class="dashboard-sidebar-user-chip ${isActiveUser ? 'is-active' : ''}" draggable="true" data-user-id="${userId}" data-user-name="${userName}" title="${tr('Click or drag to calendar', 'Cliquez ou glissez vers le calendrier', 'Clicca o trascina verso il calendario')}">
                  ${userName}
                </button>
              </article>
            `;
            }).join('') : `<div class="dashboard-sidebar-planner-placeholder">${tr('No employees in this department.', 'Aucun employe dans ce departement.', 'Nessun dipendente in questo dipartimento.')}</div>`}
          </div>
        </div>
        <div>
          <div class="dashboard-sidebar-group-title"><span>⏱</span> ${tr('Shifts', 'Postes', 'Turni')}</div>
          <div class="dashboard-sidebar-chip-group">
            ${workShifts.length ? workShifts.map((shift) => `
              <button type="button" class="dashboard-sidebar-shift-chip ${Number(shift.id) === Number(state.activeShiftId) ? 'is-active' : ''}" data-shift-id="${shift.id}" style="--shift-chip-color:${(shift.color || '#2f6fed')}">
                <span class="dashboard-sidebar-shift-icon">${renderIconHtml(shift.icon, shift.color || '#2f6fed')}</span>
                <span>${shift.name || tr('Shift', 'Poste', 'Turno')} ${formatShiftTime(shift)}</span>
              </button>
            `).join('') : `<div class="dashboard-sidebar-planner-placeholder">${tr('No work shifts configured.', 'Aucun poste de travail configure.', 'Nessun turno di lavoro configurato.')}</div>`}
          </div>
        </div>
        <section class="dashboard-sidebar-plan-flow ${activeUser ? 'is-ready' : 'is-disabled'}" data-sidebar-plan-flow>
          <div class="dashboard-sidebar-group-title"><span>🧩</span> ${tr('Shift Assignment Wizard', 'Assistant d affectation des postes', 'Assistente assegnazione turni')}</div>
          <div class="dashboard-sidebar-plan-step" data-plan-step="1">
            <strong class="dashboard-sidebar-plan-step-title" data-sidebar-plan-step-title="1" role="button" tabindex="0" aria-expanded="false">
              <span>${tr('Step 1', 'Etape 1', 'Passaggio 1')}: ${tr('Choose employee', 'Choisir un employe', 'Scegli un dipendente')}</span>
              <span class="dashboard-sidebar-plan-step-chevron" aria-hidden="true">▸</span>
            </strong>
            <p>${activeUser
              ? `${tr('Selected', 'Selectionne', 'Selezionato')} : ${escapeHtml(activeUserName)}`
              : tr('Select an employee above to continue.', 'Selectionnez un employe ci-dessus pour continuer.', 'Seleziona un dipendente qui sopra per continuare.')}</p>
          </div>
          <div class="dashboard-sidebar-plan-step" data-plan-step="2">
            <strong class="dashboard-sidebar-plan-step-title" data-sidebar-plan-step-title="2" role="button" tabindex="0" aria-expanded="false">
              <span>${tr('Step 2', 'Etape 2', 'Passaggio 2')}: ${tr('Choose working and rest days', 'Choisir les jours de travail et de repos', 'Scegli i giorni di lavoro e di riposo')}</span>
              <span class="dashboard-sidebar-plan-step-chevron" aria-hidden="true">▸</span>
            </strong>
            <div class="dashboard-sidebar-plan-week-grid">
              ${weekdayLabels.map((label, weekdayIndex) => {
                const selectedValue = String(sidebarPlanState.weekdayAssignments[String(weekdayIndex)] || '');
                return `
                  <label class="dashboard-sidebar-plan-select-row dashboard-sidebar-plan-weekday-row">
                    <span>${escapeHtml(label)}</span>
                    <select data-sidebar-plan-weekday="${weekdayIndex}">
                      ${workShifts.map((shift) => `<option value="${shift.id}" ${String(shift.id) === selectedValue ? 'selected' : ''}>${escapeHtml(summarizeShiftName(shift))}</option>`).join('')}
                      <option value="${escapeHtml(restShift ? `rest:${Number(restShift.id || 0)}` : 'rest')}" ${(selectedValue === 'rest' || selectedValue.startsWith('rest:')) ? 'selected' : ''}>${escapeHtml(tr('Rest day', 'Jour de repos', 'Giorno di riposo'))}</option>
                    </select>
                  </label>
                `;
              }).join('')}
            </div>
            <p>${tr('Choose one rule per weekday: work on a specific shift or weekly rest.', 'Choisissez une regle par jour : travail sur un poste precis ou repos hebdomadaire.', 'Scegli una regola per ogni giorno: lavoro su un turno specifico o riposo settimanale.')}</p>
          </div>
          <div class="dashboard-sidebar-plan-step" data-plan-step="3">
            <strong class="dashboard-sidebar-plan-step-title" data-sidebar-plan-step-title="3" role="button" tabindex="0" aria-expanded="false">
              <span>${tr('Step 3', 'Etape 3', 'Passaggio 3')}: ${tr('Choose period and conflict policy', 'Choisir la periode et la politique de conflit', 'Scegli il periodo e la politica dei conflitti')}</span>
              <span class="dashboard-sidebar-plan-step-chevron" aria-hidden="true">▸</span>
            </strong>
            <label class="dashboard-sidebar-plan-select-row">
              <span>${tr('Planning period', 'Periode de planification', 'Periodo di pianificazione')}</span>
              <select data-sidebar-plan-period>
                ${periodOptions.map((option) => `<option value="${option.value}" ${option.value === sidebarPlanState.periodMode ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}
              </select>
            </label>
            <label class="dashboard-sidebar-plan-select-row">
              <span>${tr('Week start', 'Debut semaine', 'Inizio settimana')}</span>
              <input type="date" value="${escapeHtml(sidebarPlanState.weekStart || dateKey(startOfWeek(state.focusDate)))}" data-sidebar-plan-week-start>
            </label>
            <label class="dashboard-sidebar-plan-select-row">
              <span>${tr('Month', 'Mois', 'Mese')}</span>
              <input type="month" value="${escapeHtml(sidebarPlanState.monthKey || dateKey(state.focusDate).slice(0, 7))}" data-sidebar-plan-month>
            </label>
            <label class="dashboard-sidebar-plan-toggle-row">
              <input type="checkbox" ${sidebarPlanState.allowOverride ? 'checked' : ''} data-sidebar-plan-override>
              <span>${tr('Allow override on double assignment days', 'Autoriser le remplacement sur les jours de double affectation', 'Consenti la sostituzione nei giorni con doppia assegnazione')}</span>
            </label>
            <button type="button" class="dashboard-sidebar-control-button" data-sidebar-plan-preview-trigger>${tr('Preview availability and conflicts', 'Apercu disponibilites et conflits', 'Anteprima disponibilità e conflitti')}</button>
          </div>
          <div class="dashboard-sidebar-plan-step" data-plan-step="4">
            <strong class="dashboard-sidebar-plan-step-title" data-sidebar-plan-step-title="4" role="button" tabindex="0" aria-expanded="false">
              <span>${tr('Step 4', 'Etape 4', 'Passaggio 4')}: ${tr('Review and apply', 'Verifier et appliquer', 'Verifica e applica')}</span>
              <span class="dashboard-sidebar-plan-step-chevron" aria-hidden="true">▸</span>
            </strong>
            <div class="dashboard-sidebar-plan-summary" data-sidebar-plan-summary></div>
            <div class="dashboard-sidebar-plan-preview" data-sidebar-plan-preview></div>
            <button type="button" class="dashboard-sidebar-control-button" data-sidebar-plan-clear-employee>${tr('Clear all assignments for this employee', 'Effacer toutes les affectations de cet employe', 'Cancella tutte le assegnazioni di questo dipendente')}</button>
            <button type="button" class="dashboard-sidebar-control-button is-active" data-sidebar-plan-apply disabled>${tr('Apply plan', 'Appliquer le plan', 'Applica piano')}</button>
          </div>
        </section>
      `;

      plannerDetail.querySelectorAll('[data-sidebar-user-card]').forEach((card) => {
        card.addEventListener('pointerdown', (event) => {
          if (event.button !== 0) return;
          const chip = card.querySelector('.dashboard-sidebar-user-chip');
          if (chip && (chip.disabled || chip.getAttribute('aria-disabled') === 'true')) return;
          setActiveUser(card.getAttribute('data-user-id'), card.getAttribute('data-user-name'));
        });
      });

      plannerDetail.querySelectorAll('.dashboard-sidebar-user-chip[data-user-id]').forEach((button) => {
        button.addEventListener('dragstart', (event) => {
          state.draggingUserId = button.getAttribute('data-user-id');
          state.draggingAssignmentId = null;
          event.dataTransfer?.setData('text/plain', JSON.stringify({ type: 'user', userId: state.draggingUserId }));
          event.dataTransfer?.setData('application/json', JSON.stringify({ type: 'user', userId: state.draggingUserId }));
        });
      });

      plannerDetail.querySelectorAll('[data-shift-id]').forEach((button) => {
        button.addEventListener('click', () => setActiveShift(button.getAttribute('data-shift-id')));
      });

      const planFlow = plannerDetail.querySelector('[data-sidebar-plan-flow]');
      if (!planFlow) return;

      planFlow.querySelectorAll('[data-sidebar-plan-step-title]').forEach((title) => {
        const toggleStep = (event) => {
          event.preventDefault();
          if (title.getAttribute('aria-disabled') === 'true') {
            return;
          }
          const stepValue = String(title.getAttribute('data-sidebar-plan-step-title') || '');
          const parentStep = title.closest('[data-plan-step]');
          const isExpanded = parentStep?.classList.contains('is-expanded');
          setExpandedPlanStep(planFlow, isExpanded ? '' : stepValue);
        };
        title.addEventListener('click', toggleStep);
        title.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') return;
          toggleStep(event);
        });
      });

      if (!activeUser) {
        const summaryNode = planFlow.querySelector('[data-sidebar-plan-summary]');
        if (summaryNode) {
          summaryNode.innerHTML = `<div class="dashboard-sidebar-plan-alert">${tr('Select an employee to unlock planning steps.', 'Selectionnez un employe pour activer les etapes de planification.', 'Seleziona un dipendente per sbloccare i passaggi di pianificazione.')}</div>`;
        }
        setExpandedPlanStep(planFlow, '1');
        return;
      }

      planFlow.querySelectorAll('[data-sidebar-plan-weekday]').forEach((input) => {
        input.addEventListener('change', () => {
          const weekdayIndex = String(input.getAttribute('data-sidebar-plan-weekday') || '');
          sidebarPlanState.weekdayAssignments[weekdayIndex] = String(input.value || '');
          sidebarPlanState.preview = [];
          sidebarPlanState.summary = null;
          sidebarPlanState.dayOverrides = {};
          syncSidebarPlanRulesForActiveUser();
          setExpandedPlanStep(planFlow, '2');
        });
      });

      const periodInput = planFlow.querySelector('[data-sidebar-plan-period]');
      if (periodInput) {
        periodInput.addEventListener('change', () => {
          sidebarPlanState.periodMode = periodInput.value || 'auto';
          sidebarPlanState.preview = [];
          sidebarPlanState.summary = null;
          sidebarPlanState.dayOverrides = {};
          setExpandedPlanStep(planFlow, '3');
        });
      }

      const weekStartInput = planFlow.querySelector('[data-sidebar-plan-week-start]');
      if (weekStartInput) {
        weekStartInput.addEventListener('change', () => {
          sidebarPlanState.weekStart = String(weekStartInput.value || dateKey(startOfWeek(state.focusDate)));
          sidebarPlanState.preview = [];
          sidebarPlanState.summary = null;
          sidebarPlanState.dayOverrides = {};
          setExpandedPlanStep(planFlow, '3');
        });
      }

      const monthInput = planFlow.querySelector('[data-sidebar-plan-month]');
      if (monthInput) {
        monthInput.addEventListener('change', () => {
          sidebarPlanState.monthKey = normalizeMonthKey(monthInput.value || dateKey(state.focusDate).slice(0, 7));
          sidebarPlanState.preview = [];
          sidebarPlanState.summary = null;
          sidebarPlanState.dayOverrides = {};
          setExpandedPlanStep(planFlow, '3');
        });
      }

      const overrideInput = planFlow.querySelector('[data-sidebar-plan-override]');
      if (overrideInput) {
        overrideInput.addEventListener('change', () => {
          sidebarPlanState.allowOverride = !!overrideInput.checked;
          sidebarPlanState.preview = [];
          sidebarPlanState.summary = null;
          sidebarPlanState.dayOverrides = {};
          setExpandedPlanStep(planFlow, '3');
        });
      }

      const previewTrigger = planFlow.querySelector('[data-sidebar-plan-preview-trigger]');
      if (previewTrigger) {
        previewTrigger.addEventListener('click', () => {
          syncSidebarPlanRulesForActiveUser();
          handleSidebarPlanPreview(planFlow);
          const hasPreview = Array.isArray(sidebarPlanState.preview) && sidebarPlanState.preview.length > 0;
          setExpandedPlanStep(planFlow, hasPreview ? '4' : '3');
        });
      }

      const applyTrigger = planFlow.querySelector('[data-sidebar-plan-apply]');
      if (applyTrigger) {
        applyTrigger.addEventListener('click', async () => {
          await applySidebarPlan(planFlow);
        });
      }

      const clearEmployeeTrigger = planFlow.querySelector('[data-sidebar-plan-clear-employee]');
      if (clearEmployeeTrigger) {
        clearEmployeeTrigger.addEventListener('click', async () => {
          await clearAssignmentsForActiveUserInPlanRange();
        });
      }

      if (Array.isArray(sidebarPlanState.preview) && sidebarPlanState.preview.length > 0) {
        renderSidebarPlanPreview(planFlow, {
          items: sidebarPlanState.preview,
          summary: sidebarPlanState.summary || {},
          error: '',
        });
      }
      const initialStep = Array.isArray(sidebarPlanState.preview) && sidebarPlanState.preview.length > 0
        ? '4'
        : '3';
      setExpandedPlanStep(planFlow, initialStep);
    };

    const upsertAssignment = (assignment) => {
      const normalized = {
        assignment_id: Number(assignment.assignment_id || assignment.id || nextAssignmentId),
        work_date: assignment.work_date,
        status: assignment.status || 'assigned',
        notes: assignment.notes || null,
        shift_id: Number(assignment.shift_id || 0),
        shift_name: assignment.shift_name || '',
        shift_kind: assignment.shift_kind || 'work',
        shift_icon: assignment.shift_icon || '',
        shift_color: assignment.shift_color || '',
        start_time: assignment.start_time || null,
        end_time: assignment.end_time || null,
        department_id: Number(assignment.department_id || 0),
        department_name: assignment.department_name || '',
        department_color: assignment.department_color || '',
        user_id: Number(assignment.user_id || 0),
        user_name: assignment.user_name || '',
        assignment_source: assignment.assignment_source || (Number(assignment.user_id || 0) > 0 ? 'assigned' : 'open'),
      };
      const index = events.findIndex((item) => Number(item.assignment_id) === Number(normalized.assignment_id));
      if (index >= 0) {
        events[index] = normalized;
      } else {
        events.push(normalized);
      }
      nextAssignmentId += 1;
    };

    const assignShift = async (payload) => {
      if (!apiDashboard || !window.AppAPI) return;
      const response = await AppAPI.postJSON(apiDashboard, {
        action: 'assign_shift',
        ...payload,
      });
      if (!response || response.ok === false || response.success === false) {
        throw new Error(response?.error || response?.message || tr('Unable to save assignment', 'Impossible d enregistrer l affectation', 'Impossibile salvare l\'assegnazione'));
      }
      if (response.assignment) {
        upsertAssignment(response.assignment);
      }
      renderCalendar();
    };

    const moveShift = async (payload) => {
      if (!apiDashboard || !window.AppAPI) return;
      const response = await AppAPI.postJSON(apiDashboard, {
        action: 'move_shift',
        ...payload,
      });
      if (!response || response.ok === false || response.success === false) {
        throw new Error(response?.error || response?.message || tr('Unable to move assignment', 'Impossible de deplacer l affectation', 'Impossibile spostare l\'assegnazione'));
      }
      if (response.assignment) {
        upsertAssignment(response.assignment);
      }
      renderCalendar();
    };

    const unassignAssignment = async (assignmentId) => {
      if (!apiDashboard || !window.AppAPI || !assignmentId) return;
      const response = await AppAPI.postJSON(apiDashboard, {
        action: 'unassign_shift',
        assignment_id: Number(assignmentId),
      });
      if (!response || response.ok === false || response.success === false) {
        throw new Error(response?.error || response?.message || tr('Unable to unassign shift', 'Impossible de desaffecter le poste', 'Impossibile rimuovere l\'assegnazione del turno'));
      }
      const index = events.findIndex((item) => Number(item.assignment_id) === Number(assignmentId));
      if (index >= 0) {
        events[index] = {
          ...events[index],
          user_id: 0,
          user_name: '',
          status: 'open',
          assignment_source: 'open',
        };
      }
      renderCalendar();
    };

    const openDate = (date) => {
      state.selectedDate = new Date(date);
      if (state.mode === 'day') {
        state.focusDate = new Date(date);
      }
      renderCalendar();
    };

    const initCalendarRangePicker = () => {
      const rangeInput = document.querySelector('[data-calendar-range-display]');
      if (!rangeInput) return;

      const parseTypedDate = (rawValue) => {
        const value = String(rawValue || '').trim();
        if (!value) return null;

        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
          const isoDate = toLocalDate(value);
          return Number.isNaN(isoDate.getTime()) ? null : isoDate;
        }

        const match = value.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$/);
        if (!match) return null;

        const day = Number(match[1]);
        const month = Number(match[2]);
        const year = Number(match[3].length === 2 ? `20${match[3]}` : match[3]);
        if (!day || !month || !year) return null;
        const parsed = new Date(year, month - 1, day, 12, 0, 0, 0);
        if (Number.isNaN(parsed.getTime())) return null;
        if (parsed.getDate() !== day || parsed.getMonth() !== month - 1 || parsed.getFullYear() !== year) return null;
        return parsed;
      };

      const applyTypedRange = (rawInputValue) => {
        const typed = String(rawInputValue || '').trim();
        if (!typed) {
          rangeInput.value = formatNavigatorRange();
          return;
        }

        const parts = typed.includes(' - ') ? typed.split(' - ') : [typed];
        const startDate = parseTypedDate(parts[0]);
        const endDate = parts[1] ? parseTypedDate(parts[1]) : startDate;
        if (!startDate || !endDate) {
          rangeInput.value = formatNavigatorRange();
          return;
        }

        const start = startDate <= endDate ? startDate : endDate;
        const end = endDate >= startDate ? endDate : startDate;
        const diffDays = Math.max(Math.round((end.getTime() - start.getTime()) / 86400000), 0);

        state.focusDate = new Date(start);
        state.selectedDate = new Date(start);

        if (diffDays === 14) {
          state.mode = 'fortnight';
          state.navigationMode = 'fortnight';
        } else if (diffDays >= 27 && diffDays <= 31) {
          state.mode = 'month';
          state.navigationMode = 'month';
          state.focusDate = new Date(start.getFullYear(), start.getMonth(), 1, 12, 0, 0, 0);
        } else if (diffDays === 6) {
          state.mode = 'week';
          state.navigationMode = 'day';
        } else if (diffDays === 0) {
          state.mode = 'week';
          state.navigationMode = 'day';
        } else {
          state.mode = 'week';
          state.navigationMode = 'day';
        }

        renderCalendar();
      };

      rangeInput.readOnly = false;
      rangeInput.setAttribute('inputmode', 'numeric');
      rangeInput.setAttribute('spellcheck', 'false');
      rangeInput.setAttribute('aria-label', tr('Type a date range for the calendar', 'Saisissez une plage de dates pour le calendrier', 'Digita un intervallo di date per il calendario'));

      let previousRangeValue = rangeInput.value;

      rangeInput.addEventListener('focus', () => {
        previousRangeValue = rangeInput.value;
        rangeInput.select();
      });

      rangeInput.addEventListener('click', () => {
        rangeInput.focus();
        rangeInput.select();
      });

      rangeInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyTypedRange(rangeInput.value);
          return;
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          rangeInput.value = previousRangeValue || formatNavigatorRange();
          rangeInput.blur();
        }
      });

      rangeInput.addEventListener('blur', () => {
        applyTypedRange(rangeInput.value);
      });
    };

    const safeDashboardInit = (label, initializer) => {
      try {
        initializer();
      } catch (error) {
        console.error(`[dashboard:${label}]`, error);
      }
    };

    if (window.DashboardSidebar && typeof window.DashboardSidebar.init === 'function') {
      safeDashboardInit('sidebar', () => window.DashboardSidebar.init({
        sidebar,
        sidebarHandle,
        plannerDepartmentButtons,
        setActiveDepartment,
        onTransferEmployeeDepartment: transferEmployeeToDepartment,
      }));
    }

    if (window.DashboardNavigator && typeof window.DashboardNavigator.init === 'function') {
      safeDashboardInit('navigator', () => window.DashboardNavigator.init({
        navigatorPanel,
        navigatorToggleButtons,
        calendarToday,
        state,
        calendarShell,
        plannerData,
        toLocalDate,
        addDays,
        renderCalendar,
        updateChrome,
      }));
    }

    if (window.DashboardCalendar && typeof window.DashboardCalendar.init === 'function') {
      safeDashboardInit('calendar', () => window.DashboardCalendar.init({
        calendarShell,
        events,
        attendances,
        state,
        toLocalDate,
        openDate,
        unassignAssignment,
        assignShift,
        isUserAvailableForDate,
        getUserAvailabilityStatus,
        getActiveUser: getActiveUserForCalendar,
        getActiveShift,
        getAbsenceTemplateShiftId,
        getAbsenceTemplateShift,
      }));
    }

    if (window.DashboardDnd && typeof window.DashboardDnd.init === 'function') {
      safeDashboardInit('dnd', () => window.DashboardDnd.init({
        calendarShell,
        state,
        events,
        getActiveShift,
        assignShift,
        moveShift,
        safeParseJson,
        isUserAvailableForDate,
        getUserAvailabilityStatus,
      }));
    }

    safeDashboardInit('range-picker', () => initCalendarRangePicker());
    safeDashboardInit('sidebar-render', () => renderSidebarPlanner());
    safeDashboardInit('calendar-render', () => renderCalendar());
    safeDashboardInit('runtime-publish', () => publishPlannerRuntime());
  })();
})();
