/*
 * Masar first-login guided tour (mandatory).
 *
 * The tour walks the user through the app's pages in a fixed setup order and
 * cannot be skipped or closed: a full-screen layer blocks the rest of the
 * site until the last step is finished (the server also refuses data changes
 * until then). For every page the tour first points at that page's entry in
 * the sidebar (opening the sidebar on mobile) and only then navigates to it,
 * so the user learns where to click. Each page stop highlights a real element
 * on that page; moving between pages is a plain GET navigation, so the tour
 * never creates or changes business data. The stop key is stored server-side
 * (users.tour_step) before every navigation so the tour resumes on reload or
 * if the user opens another address.
 */
(function () {
  'use strict';

  const config = window.masarGuidedTour;
  if (!config || !config.active || !Array.isArray(config.steps)) return;

  const labels = config.labels || {};
  let steps = [];
  let index = 0;
  let phase = 'page'; // 'nav' = pointing at the sidebar link, 'page' = on the page itself
  let active = false;
  let navigating = false;
  let backdropEl = null;
  let highlightEl = null;
  let popoverEl = null;

  const isMobile = () => window.matchMedia('(max-width: 800px)').matches;
  const sidebarEl = () => document.getElementById('sidebar');

  function normPath(p) {
    return p.replace(/\/+$/, '') || '/';
  }

  function samePage(url) {
    if (!url) return true;
    try {
      const target = new URL(url, window.location.origin);
      return normPath(target.pathname) === normPath(window.location.pathname);
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

  function visible(el) {
    if (!el) return false;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function targetFor(step) {
    if (!step.target) return null;
    const selectors = Array.isArray(step.target) ? step.target : [step.target];
    for (const selector of selectors) {
      const el = document.querySelector(selector);
      if (visible(el)) return el;
    }
    return null;
  }

  function sidebarLinkFor(step) {
    if (!step.url) return null;
    let path;
    try {
      path = normPath(new URL(step.url, window.location.origin).pathname);
    } catch (e) {
      return null;
    }
    const links = Array.from(document.querySelectorAll('#sidebar a.nav-item'));
    return links.find((a) => {
      try {
        return normPath(new URL(a.getAttribute('href'), window.location.origin).pathname) === path;
      } catch (e) {
        return false;
      }
    }) || null;
  }

  function currentTarget() {
    const step = steps[index];
    if (phase === 'nav') return sidebarLinkFor(step);
    return targetFor(step);
  }

  function setSidebarOpen(open) {
    const el = sidebarEl();
    if (!el || !isMobile()) return;
    el.classList.toggle('open', open);
  }

  function buildDom() {
    backdropEl = document.createElement('div');
    backdropEl.className = 'masar-tour-backdrop dim';

    highlightEl = document.createElement('div');
    highlightEl.className = 'masar-tour-highlight';
    highlightEl.hidden = true;

    popoverEl = document.createElement('div');
    popoverEl.className = 'masar-tour-popover';
    popoverEl.setAttribute('role', 'dialog');
    popoverEl.setAttribute('aria-modal', 'true');
    popoverEl.setAttribute('aria-label', labels.dialog || 'Guided tour');

    document.body.append(backdropEl, highlightEl, popoverEl);
  }

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), Math.max(max, min));
  }

  function positionFor(target) {
    const rect = target.getBoundingClientRect();
    highlightEl.hidden = false;
    backdropEl.classList.remove('dim');
    highlightEl.style.top = `${Math.max(rect.top - 6, 4)}px`;
    highlightEl.style.left = `${Math.max(rect.left - 6, 4)}px`;
    highlightEl.style.width = `${rect.width + 12}px`;
    highlightEl.style.height = `${rect.height + 12}px`;

    const popRect = popoverEl.getBoundingClientRect();
    const margin = 14;
    const rtl = document.documentElement.dir === 'rtl';
    let top;
    let left;

    if (phase === 'nav' && !isMobile()) {
      // Sidebar link: put the card beside the sidebar, level with the link.
      left = rtl ? rect.left - popRect.width - margin : rect.right + margin;
      top = rect.top + rect.height / 2 - popRect.height / 2;
    } else {
      top = rect.bottom + margin;
      if (top + popRect.height > window.innerHeight - 8) {
        top = rect.top - popRect.height - margin;
      }
      left = rtl ? rect.right - popRect.width : rect.left;
    }
    top = clamp(top, 8, window.innerHeight - popRect.height - 8);
    left = clamp(left, 8, window.innerWidth - popRect.width - 8);
    popoverEl.style.transform = '';
    popoverEl.style.top = `${top}px`;
    popoverEl.style.left = `${left}px`;
  }

  function positionCentered() {
    highlightEl.hidden = true;
    backdropEl.classList.add('dim');
    popoverEl.style.top = '50%';
    popoverEl.style.left = '50%';
    popoverEl.style.transform = 'translate(-50%, -50%)';
  }

  function placeAll() {
    const target = currentTarget();
    if (target) positionFor(target);
    else positionCentered();
  }

  function onReposition() {
    if (active && popoverEl) placeAll();
  }

  function scheduleRescroll(token) {
    [350, 900].forEach((delay) => {
      setTimeout(() => {
        if (!active || token !== renderToken) return;
        const t = currentTarget();
        if (t) {
          t.scrollIntoView({ block: 'center', inline: 'center' });
          positionFor(t);
        }
      }, delay);
    });
  }

  let renderToken = 0;

  function button(text, cls, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `tour-btn ${cls}`;
    btn.textContent = text;
    btn.addEventListener('click', onClick);
    return btn;
  }

  function render() {
    const step = steps[index];
    renderToken += 1;
    const token = renderToken;
    popoverEl.innerHTML = '';

    const title = document.createElement('h3');
    title.className = 'tour-title';
    const iconName = step.icon;
    if (iconName) {
      const icon = document.createElement('i');
      icon.className = `bi ${iconName}`;
      title.appendChild(icon);
    }
    const body = document.createElement('p');
    body.className = 'tour-body';

    if (phase === 'nav') {
      title.appendChild(document.createTextNode(labels.navTitle || 'Where is this page?'));
      body.textContent = (labels.navHint || 'Click «:page» in the sidebar.').replace(':page', step.title);
    } else {
      title.appendChild(document.createTextNode(step.title));
      body.textContent = step.body;
    }

    const footer = document.createElement('div');
    footer.className = 'tour-footer';

    const progress = document.createElement('span');
    progress.className = 'tour-progress';
    progress.textContent = `${index + 1} / ${steps.length}`;

    const actions = document.createElement('div');
    actions.className = 'tour-actions';

    const backBtn = button(labels.back || 'Back', 'tour-btn-secondary', onBack);
    backBtn.disabled = index === 0 && phase === 'page';

    const last = index === steps.length - 1;
    let nextLabel = last ? (labels.finish || 'Done') : (labels.next || 'Next');
    if (phase === 'nav') nextLabel = labels.goTo || 'Go to the page';
    const nextBtn = button(nextLabel, 'tour-btn-primary', onNext);

    actions.append(backBtn, nextBtn);
    footer.append(progress, actions);
    popoverEl.append(title, body, footer);

    if (phase === 'nav') setSidebarOpen(true);
    else setSidebarOpen(false);

    const target = currentTarget();
    if (target) {
      target.scrollIntoView({ block: 'center', inline: 'center' });
      requestAnimationFrame(() => requestAnimationFrame(() => {
        if (token === renderToken) placeAll();
      }));
      scheduleRescroll(token);
    } else {
      positionCentered();
    }
    nextBtn.focus({ preventScroll: true });
  }

  function navigateTo(step) {
    if (navigating) return;
    navigating = true;
    // Persist where we are heading before leaving the page; the next page
    // boots straight into this stop.
    post(config.progressUrl, { step: step.key }).then(() => {
      window.location.href = step.url;
    });
  }

  // Move to step i: point at its sidebar entry first when it lives on another page.
  function goTo(i) {
    const step = steps[i];
    if (!step) {
      finish();
      return;
    }
    if (samePage(step.url)) {
      index = i;
      phase = 'page';
      post(config.progressUrl, { step: step.key });
      render();
      return;
    }
    if (sidebarLinkFor(step)) {
      index = i;
      phase = 'nav';
      render();
      return;
    }
    navigateTo(step);
  }

  function onNext() {
    if (navigating) return;
    if (phase === 'nav') {
      navigateTo(steps[index]);
      return;
    }
    if (index === steps.length - 1) {
      finish();
      return;
    }
    goTo(index + 1);
  }

  function onBack() {
    if (navigating) return;
    if (phase === 'nav') {
      // Still on the previous step's page.
      index = Math.max(index - 1, 0);
      phase = 'page';
      render();
      return;
    }
    if (index > 0) goTo(index - 1);
  }

  function finish() {
    navigating = true;
    post(config.completeUrl).then(() => teardown());
  }

  function teardown() {
    active = false;
    setSidebarOpen(false);
    [backdropEl, highlightEl, popoverEl].forEach((el) => el && el.remove());
    backdropEl = highlightEl = popoverEl = null;
    document.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('focusin', onFocusIn, true);
    window.removeEventListener('resize', onReposition);
    window.removeEventListener('scroll', onReposition, true);
  }

  // No Escape, no clicking outside: the tour stays until it is finished.
  function onKeydown(event) {
    if (!active || !popoverEl) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (event.key === 'Tab') {
      const focusable = Array.from(popoverEl.querySelectorAll('button:not(:disabled)'));
      if (!focusable.length) return;
      const first = focusable[0];
      const lastEl = focusable[focusable.length - 1];
      if (!popoverEl.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        lastEl.focus();
      } else if (!event.shiftKey && document.activeElement === lastEl) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  function onFocusIn(event) {
    if (!active || !popoverEl || popoverEl.contains(event.target)) return;
    const btn = popoverEl.querySelector('.tour-btn-primary');
    if (btn) btn.focus({ preventScroll: true });
  }

  function start(i, startPhase) {
    if (active) return;
    active = true;
    index = i;
    phase = startPhase || 'page';
    buildDom();
    document.addEventListener('keydown', onKeydown, true);
    document.addEventListener('focusin', onFocusIn, true);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);
    render();
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
    // Opened another address mid-tour: block the page and return to the stop.
    if (!samePage(step.url)) {
      start(startIndex, 'page');
      navigating = true;
      window.location.replace(step.url);
      return;
    }
    start(startIndex, 'page');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
