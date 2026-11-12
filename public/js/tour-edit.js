/*
 * Owner tour editor: element picker (?tour_edit=1, owner session required).
 *
 * Adds a small bar on top of any app page. Hovering highlights the element
 * under the cursor; clicking it opens a compact form for the step text and
 * saves it as a draft tour step bound to the current page's route. The
 * selector prefers stable hooks (#id, [data-tour], [data-bs-target]) and
 * falls back to a short structural path; the saved step always keeps
 * '.page-head' as a fallback target so the tour never breaks if the page
 * markup changes later.
 */
(function () {
  'use strict';

  const config = window.masarTourEditor;
  if (!config || !config.saveUrl) return;

  const labels = config.labels || {};
  let barEl = null;
  let formEl = null;
  let hoverEl = null;
  let pickedEl = null;

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function esc(value) {
    return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/"/g, '\\"');
  }

  function selectorFor(el) {
    if (!el || el === document.body || el === document.documentElement) return null;
    if (el.id) return '#' + esc(el.id);
    const dataTour = el.closest('[data-tour]');
    if (dataTour) return '[data-tour="' + dataTour.getAttribute('data-tour') + '"]';
    const bsTarget = el.getAttribute && el.getAttribute('data-bs-target');
    if (bsTarget) return el.tagName.toLowerCase() + '[data-bs-target="' + bsTarget + '"]';

    const path = [];
    let node = el;
    while (node && node !== document.body && path.length < 4) {
      if (node.id) {
        path.unshift('#' + esc(node.id));
        break;
      }
      let part = node.tagName.toLowerCase();
      const classes = Array.from(node.classList || [])
        .filter((c) => c && !/^(active|show|open|is-)/.test(c))
        .slice(0, 2);
      if (classes.length) part += '.' + classes.map(esc).join('.');
      const parent = node.parentElement;
      if (parent) {
        const sameTag = Array.from(parent.children).filter((c) => c.tagName === node.tagName);
        if (sameTag.length > 1) part += ':nth-of-type(' + (sameTag.indexOf(node) + 1) + ')';
      }
      path.unshift(part);
      node = parent;
    }
    return path.length ? path.join(' > ') : null;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function clearHover() {
    if (hoverEl) hoverEl.classList.remove('masar-tour-pick-hover');
    hoverEl = null;
  }

  function buildBar() {
    barEl = el('div', 'masar-tour-pick-bar');
    const text = el('span', 'masar-tour-pick-text', labels.bar || 'Tour element picker');
    const openEditor = el('a', 'masar-tour-pick-link', labels.openEditor || 'Open tour management');
    openEditor.href = config.editorUrl;
    openEditor.target = '_blank';
    const exit = el('button', 'masar-tour-pick-exit', labels.exit || 'Exit');
    exit.type = 'button';
    exit.addEventListener('click', () => {
      const url = new URL(window.location.href);
      url.searchParams.delete('tour_edit');
      window.location.href = url.toString();
    });
    barEl.append(text, openEditor, exit);
    document.body.appendChild(barEl);
  }

  function field(labelText, input) {
    const label = el('label', 'masar-tour-pick-field');
    label.append(el('span', null, labelText), input);
    return label;
  }

  function buildForm(selector) {
    closeForm();
    formEl = el('div', 'masar-tour-pick-form');

    formEl.appendChild(el('div', 'masar-tour-pick-selector', (labels.picked || 'Picked:') + ' ' + selector));

    const titleAr = el('input'); titleAr.required = true; titleAr.maxLength = 500;
    const bodyAr = el('textarea'); bodyAr.required = true; bodyAr.rows = 3; bodyAr.maxLength = 2000;
    const titleEn = el('input'); titleEn.maxLength = 500;
    const bodyEn = el('textarea'); bodyEn.rows = 2; bodyEn.maxLength = 2000;
    const permission = el('select');
    permission.appendChild(new Option(labels.noPermission || 'No permission', ''));
    (config.permissions || []).forEach((name) => permission.appendChild(new Option(name, name)));

    formEl.append(
      field(labels.titleAr || 'Title (AR)', titleAr),
      field(labels.bodyAr || 'Body (AR)', bodyAr),
      field(labels.titleEn || 'Title (EN, optional)', titleEn),
      field(labels.bodyEn || 'Body (EN, optional)', bodyEn),
      field(labels.permission || 'Permission (optional)', permission)
    );

    const actions = el('div', 'masar-tour-pick-actions');
    const save = el('button', 'masar-tour-pick-save', labels.save || 'Save step');
    save.type = 'button';
    const cancel = el('button', 'masar-tour-pick-cancel', labels.cancel || 'Cancel');
    cancel.type = 'button';
    actions.append(save, cancel);
    formEl.appendChild(actions);
    document.body.appendChild(formEl);
    titleAr.focus();

    cancel.addEventListener('click', () => {
      closeForm();
      if (pickedEl) pickedEl.classList.remove('masar-tour-pick-chosen');
      pickedEl = null;
    });

    save.addEventListener('click', () => {
      if (!titleAr.value.trim() || !bodyAr.value.trim()) {
        titleAr.reportValidity();
        bodyAr.reportValidity();
        return;
      }
      save.disabled = true;
      fetch(config.saveUrl, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          route_name: config.routeName,
          selector,
          permission: permission.value || null,
          title_ar: titleAr.value.trim(),
          body_ar: bodyAr.value.trim(),
          title_en: titleEn.value.trim() || null,
          body_en: bodyEn.value.trim() || null,
        }),
      })
        .then((response) => (response.ok ? response.json() : Promise.reject(response)))
        .then(() => {
          closeForm();
          if (pickedEl) pickedEl.classList.remove('masar-tour-pick-chosen');
          pickedEl = null;
          showNotice(labels.saved || 'Saved to the draft.');
        })
        .catch(() => {
          save.disabled = false;
          showNotice(labels.failed || 'Save failed.', true);
        });
    });
  }

  function closeForm() {
    if (formEl) formEl.remove();
    formEl = null;
  }

  let noticeEl = null;
  let noticeTimer = null;
  function showNotice(text, isError) {
    if (!noticeEl) {
      noticeEl = el('div', 'masar-tour-pick-notice');
      document.body.appendChild(noticeEl);
    }
    noticeEl.textContent = text;
    noticeEl.classList.toggle('error', !!isError);
    noticeEl.classList.add('show');
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => noticeEl.classList.remove('show'), 4000);
  }

  function ourElement(el) {
    return !!(barEl && barEl.contains(el)) || !!(formEl && formEl.contains(el)) || !!(noticeEl && noticeEl.contains(el));
  }

  function onMouseOver(event) {
    if (formEl || ourElement(event.target)) return;
    clearHover();
    hoverEl = event.target;
    hoverEl.classList.add('masar-tour-pick-hover');
  }

  function onClick(event) {
    if (ourElement(event.target)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (formEl) return;
    if (!config.routeName) {
      showNotice(labels.missing || 'This page cannot hold a step.', true);
      return;
    }
    const selector = selectorFor(event.target);
    if (!selector) return;
    clearHover();
    pickedEl = event.target;
    pickedEl.classList.add('masar-tour-pick-chosen');
    buildForm(selector);
  }

  buildBar();
  document.addEventListener('mouseover', onMouseOver, true);
  // Stop before document-level delegated handlers (including Bootstrap modals).
  window.addEventListener('click', onClick, true);
})();
