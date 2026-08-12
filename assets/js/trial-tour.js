window.TrialTour = (function () {
  var config = window.TrialTourConfig || { autoStart: false, steps: [] };
  var currentIndex = 0;
  var highlightEl = null;
  var tooltipEl = null;

  function ensureDom() {
    if (highlightEl && tooltipEl) {
      return;
    }
    highlightEl = document.createElement('div');
    highlightEl.className = 'onboarding-tour-highlight';
    highlightEl.hidden = true;

    tooltipEl = document.createElement('div');
    tooltipEl.className = 'onboarding-tour-tooltip';
    tooltipEl.setAttribute('role', 'dialog');
    tooltipEl.setAttribute('aria-modal', 'false');

    document.body.appendChild(highlightEl);
    document.body.appendChild(tooltipEl);
  }

  function teardownDom() {
    if (highlightEl && highlightEl.parentNode) {
      highlightEl.parentNode.removeChild(highlightEl);
    }
    if (tooltipEl && tooltipEl.parentNode) {
      tooltipEl.parentNode.removeChild(tooltipEl);
    }
    highlightEl = null;
    tooltipEl = null;
  }

  function positionHighlight(rect) {
    if (!rect) {
      highlightEl.hidden = true;
      return;
    }
    highlightEl.hidden = false;
    var padding = 8;
    highlightEl.style.top = Math.max(0, rect.top - padding) + 'px';
    highlightEl.style.left = Math.max(0, rect.left - padding) + 'px';
    highlightEl.style.width = (rect.width + padding * 2) + 'px';
    highlightEl.style.height = (rect.height + padding * 2) + 'px';
  }

  function positionTooltip(rect) {
    var margin = 16;
    if (!rect) {
      tooltipEl.style.top = '50%';
      tooltipEl.style.left = '50%';
      tooltipEl.style.transform = 'translate(-50%, -50%)';
      return;
    }
    tooltipEl.style.transform = 'none';
    var tooltipRect = tooltipEl.getBoundingClientRect();
    var top = rect.bottom + margin;
    if (top + tooltipRect.height > window.innerHeight) {
      top = Math.max(margin, rect.top - tooltipRect.height - margin);
    }
    var left = rect.left;
    if (left + tooltipRect.width > window.innerWidth - margin) {
      left = Math.max(margin, window.innerWidth - tooltipRect.width - margin);
    }
    tooltipEl.style.top = top + 'px';
    tooltipEl.style.left = left + 'px';
  }

  function renderTooltip(step, rect, index) {
    var total = config.steps.length;
    var isFirst = index === 0;
    var isLast = index === total - 1;
    var labels = window.TrialTourLabels || {};

    tooltipEl.innerHTML =
      '<div class="onboarding-tour-step-badge">' + (index + 1) + '</div>' +
      '<h3 class="onboarding-tour-tooltip-title"></h3>' +
      '<p class="onboarding-tour-tooltip-body"></p>' +
      '<div class="onboarding-tour-progress"></div>' +
      '<div class="onboarding-tour-actions">' +
        (isFirst ? '' : '<button type="button" class="admin-action-link admin-action-link-secondary" data-onboarding-back></button>') +
        '<button type="button" class="admin-action-link admin-action-link-secondary" data-onboarding-skip></button>' +
        '<button type="button" class="admin-action-link" data-onboarding-next></button>' +
      '</div>';

    tooltipEl.querySelector('.onboarding-tour-tooltip-title').textContent = step.title || '';
    tooltipEl.querySelector('.onboarding-tour-tooltip-body').textContent = step.body || '';
    tooltipEl.querySelector('.onboarding-tour-progress').textContent =
      (labels.stepProgress || '{current}/{total}').replace('{current}', String(index + 1)).replace('{total}', String(total));

    var backBtn = tooltipEl.querySelector('[data-onboarding-back]');
    if (backBtn) {
      backBtn.textContent = labels.buttonBack || 'Back';
      backBtn.addEventListener('click', back);
    }
    var skipBtn = tooltipEl.querySelector('[data-onboarding-skip]');
    skipBtn.textContent = labels.buttonSkip || 'Skip';
    skipBtn.addEventListener('click', skip);
    var nextBtn = tooltipEl.querySelector('[data-onboarding-next]');
    nextBtn.textContent = isLast ? (labels.buttonFinish || 'Finish') : (labels.buttonNext || 'Next');
    nextBtn.addEventListener('click', next);

    positionTooltip(rect);
    positionHighlight(rect);
  }

  function showStep(index) {
    var step = config.steps[index];
    if (!step) {
      finish();
      return;
    }
    currentIndex = index;

    requestAnimationFrame(function () {
      var target = step.selector ? document.querySelector(step.selector) : null;
      var rect = target ? target.getBoundingClientRect() : null;
      var isVisible = rect && rect.width > 0 && rect.height > 0;
      if (isVisible && target.scrollIntoView) {
        target.scrollIntoView({ block: 'center', behavior: 'instant' in window ? 'instant' : 'auto' });
        rect = target.getBoundingClientRect();
      }
      renderTooltip(step, isVisible ? rect : null, index);
    });
  }

  function next() {
    showStep(currentIndex + 1);
  }

  function back() {
    showStep(Math.max(0, currentIndex - 1));
  }

  function skip() {
    finish();
  }

  function finish() {
    teardownDom();
  }

  function start() {
    if (!config.steps || !config.steps.length) {
      return;
    }
    currentIndex = 0;
    ensureDom();
    showStep(0);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var relaunch = document.querySelector('[data-trial-tour-relaunch]');
    if (relaunch) {
      relaunch.addEventListener('click', start);
    }
    if (config.autoStart) {
      start();
    }
  });

  return { start: start };
})();
