/*
 * Masar first-login guided tour.
 *
 * The tour walks the user through the app's pages in a fixed setup order.
 * Each stop highlights a real element on its own page; moving between pages
 * is a plain GET navigation, so the tour never creates or changes business
 * data. The current stop key is stored server-side (users.tour_step) before
 * every navigation, which lets the tour resume after the page loads, and a
 * small resume chip appears if the user wanders to another page mid-tour.
 * Skipping or finishing POSTs to tour.complete and the tour never starts
 * again unless the user replays it from the user menu.
 */
(function () {
  'use strict';

  const config = window.masarGuidedTour;
  if (!config || !config.active || !Array.isArray(config.steps)) return;

  const labels = config.labels || {};
  let steps = [];
  let index = 0;
  let active = false;
  let backdropEl = null;
  let highlightEl = null;
  let popoverEl = null;
  let chipEl = null;

  function samePage(url) {
    if (!url) return true;
    try {
      const target = new URL(url, window.location.origin);
      const norm = (p) => (p.replace(/\/+$/, '') || '/');
      return norm(target.pathname) === norm(window.location.pathname);
    } catch (e) {
      return false;
    }
  }

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
        ...(data ? { 'Content-Type': 'application/json' } : {}),
      },
      body: data ? JSON.stringify(data) : null,
      credentials: 'same-origin',
    }).catch(() => {});
  }

  function targetFor(step) {
    if (!step.target) return null;
    const selectors = Array.isArray(step.target) ? step.target : [step.target];
    const el = selectors.map((selector) => document.querySelector(selector)).find((candidate) => {
      if (!candidate) return false;
      const style = window.getComputedStyle(candidate);
      if (style.display === 'none' || style.visibility === 'hidden') return false;
      const rect = candidate.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    });
    if (!el) return null;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return null;
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return null;
    return el;
  }

  function buildDom() {
    backdropEl = document.createElement('div');
    backdropEl.className = 'masar-tour-backdrop';

    highlightEl = document.createElement('div');
    highlightEl.className = 'masar-tour-highlight';
    highlightEl.hidden = true;

    popoverEl = document.createElement('div');
    popoverEl.className = 'masar-tour-popover';
    popoverEl.setAttribute('role', 'dialog');
    popoverEl.setAttribute('aria-label', labels.dialog || 'Guided tour');

    document.body.append(backdropEl, highlightEl, popoverEl);
  }

  function positionFor(target) {
    const rect = target.getBoundingClientRect();
    highlightEl.hidden = false;
    highlightEl.style.top = `${Math.max(rect.top - 6, 4)}px`;
    highlightEl.style.left = `${Math.max(rect.left - 6, 4)}px`;
    highlightEl.style.width = `${rect.width + 12}px`;
    highlightEl.style.height = `${rect.height + 12}px`;

    const popRect = popoverEl.getBoundingClientRect();
    const margin = 12;
    const rtl = document.documentElement.dir === 'rtl';
    let top = rect.bottom + margin;
    if (top + popRect.height > window.innerHeight - 8) {
      top = Math.max(rect.top - popRect.height - margin, 8);
    }
    let left = rtl ? rect.right - popRect.width : rect.left;
    left = Math.min(Math.max(left, 8), Math.max(window.innerWidth - popRect.width - 8, 8));
    popoverEl.style.top = `${top}px`;
    popoverEl.style.left = `${left}px`;
  }

  function positionCentered() {
    highlightEl.hidden = true;
    popoverEl.style.top = '50%';
    popoverEl.style.left = '50%';
    popoverEl.style.transform = 'translate(-50%, -50%)';
  }

  function clearCentered() {
    popoverEl.style.transform = '';
  }

  function onReposition() {
    if (!active) return;
    const step = steps[index];
    const target = targetFor(step);
    if (target) {
      clearCentered();
      positionFor(target);
    } else {
      positionCentered();
    }
  }

  function scheduleRescroll(step) {
    [350, 900].forEach((delay) => {
      setTimeout(() => {
        if (!active || steps[index] !== step) return;
        const t = targetFor(step);
        if (t) {
          clearCentered();
          t.scrollIntoView({ block: 'center', inline: 'center' });
          positionFor(t);
        }
      }, delay);
    });
  }

  function render() {
    const step = steps[index];
    popoverEl.innerHTML = '';

    const title = document.createElement('h3');
    title.className = 'tour-title';
    if (step.icon) {
      const icon = document.createElement('i');
      icon.className = `bi ${step.icon}`;
      title.appendChild(icon);
    }
    title.appendChild(document.createTextNode(step.title));
    const body = document.createElement('p');
    body.className = 'tour-body';
    body.textContent = step.body;

    const footer = document.createElement('div');
    footer.className = 'tour-footer';

    const skipBtn = document.createElement('button');
    skipBtn.type = 'button';
    skipBtn.className = 'tour-btn tour-btn-ghost';
    skipBtn.textContent = labels.skip || 'Skip';
    skipBtn.addEventListener('click', () => finish());

    const progress = document.createElement('span');
    progress.className = 'tour-progress';
    progress.textContent = `${index + 1} / ${steps.length}`;

    const actions = document.createElement('div');
    actions.className = 'tour-actions';

    const backBtn = document.createElement('button');
    backBtn.type = 'button';
    backBtn.className = 'tour-btn tour-btn-secondary';
    backBtn.textContent = labels.back || 'Back';
    backBtn.disabled = index === 0;
    backBtn.addEventListener('click', () => advanceTo(index - 1));

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'tour-btn tour-btn-primary';
    const last = index === steps.length - 1;
    nextBtn.textContent = last ? (labels.finish || 'Done') : (labels.next || 'Next');
    nextBtn.addEventListener('click', () => (last ? finish() : advanceTo(index + 1)));

    actions.append(backBtn, nextBtn);
    footer.append(skipBtn, progress, actions);
    popoverEl.append(title, body, footer);

    const target = targetFor(step);
    if (target) {
      clearCentered();
      target.scrollIntoView({ block: 'center', inline: 'center' });
      requestAnimationFrame(() => requestAnimationFrame(() => positionFor(target)));
      scheduleRescroll(step);
    } else {
      positionCentered();
    }
    nextBtn.focus({ preventScroll: true });
  }

  function advanceTo(i) {
    const step = steps[i];
    if (!step) {
      finish();
      return;
    }
    if (samePage(step.url)) {
      index = i;
      render();
      return;
    }
    // Persist where we are heading before leaving the page; the next page
    // boots straight into this stop.
    post(config.progressUrl, { step: step.key }).then(() => {
      window.location.href = step.url;
    });
  }

  function finish() {
    post(config.completeUrl);
    teardown();
  }

  function teardown() {
    active = false;
    [backdropEl, highlightEl, popoverEl].forEach((el) => el && el.remove());
    backdropEl = highlightEl = popoverEl = null;
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', onReposition);
    window.removeEventListener('scroll', onReposition, true);
  }

  function onKeydown(event) {
    if (!active) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      finish();
    }
  }

  function start(i) {
    if (active) return;
    active = true;
    index = i;
    buildDom();
    render();
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);
  }

  function showResumeChip(step) {
    if (chipEl) return;
    chipEl = document.createElement('div');
    chipEl.className = 'masar-tour-resume';

    const text = document.createElement('span');
    text.textContent = `${labels.resumeTitle || 'Your tour is still in progress'}: ${step.title}`;

    const resumeBtn = document.createElement('button');
    resumeBtn.type = 'button';
    resumeBtn.className = 'tour-btn tour-btn-primary';
    resumeBtn.textContent = labels.resume || 'Resume tour';
    resumeBtn.addEventListener('click', () => {
      window.location.href = step.url;
    });

    const skipBtn = document.createElement('button');
    skipBtn.type = 'button';
    skipBtn.className = 'tour-btn tour-btn-ghost';
    skipBtn.textContent = labels.skip || 'Skip';
    skipBtn.addEventListener('click', () => {
      post(config.completeUrl);
      chipEl.remove();
      chipEl = null;
    });

    chipEl.append(text, resumeBtn, skipBtn);
    document.body.appendChild(chipEl);
  }

  function boot() {
    steps = config.steps.filter((step) => step && step.title && step.body);
    if (!steps.length) return;
    let startIndex = 0;
    if (config.current) {
      const found = steps.findIndex((step) => step.key === config.current);
      if (found >= 0) startIndex = found;
    }
    const step = steps[startIndex];
    if (samePage(step.url)) {
      start(startIndex);
    } else {
      showResumeChip(step);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(boot, 400));
  } else {
    setTimeout(boot, 400);
  }
})();
