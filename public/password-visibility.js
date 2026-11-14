/* Shared by login, owner/admin and dynamically loaded account forms. */
(function () {
  'use strict';
  const style = document.createElement('style');
  style.textContent = `
    .odir-password-field { position: relative; display: block; width: 100%; min-width: 0; }
    .input-group > .odir-password-field { flex: 1 1 auto; width: 1%; }
    .odir-password-field > input { width: 100%; padding-inline-end: 3rem !important; }
    .odir-password-field > .odir-password-toggle { position: absolute; inset-inline-end: 4px; top: 50%; transform: translateY(-50%); width: 38px; height: 36px; min-height: 0; padding: 8px; margin: 0; border: 0; border-radius: 6px; background: transparent; color: #64748b; display: grid; place-items: center; cursor: pointer; z-index: 5; box-shadow: none; }
    .odir-password-field > .odir-password-toggle:hover { color: #1769e0; background: #eff6ff; }
    .odir-password-field > .odir-password-toggle:focus-visible { outline: 2px solid #1769e0; outline-offset: -2px; }
    .odir-password-toggle svg { width: 20px; height: 20px; pointer-events: none; }
  `;
  document.head.appendChild(style);
  const arabic = document.documentElement.lang.startsWith('ar');
  const showLabel = arabic ? 'إظهار كلمة المرور' : 'Show password';
  const hideLabel = arabic ? 'إخفاء كلمة المرور' : 'Hide password';
  function setVisible(input, button, visible) {
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? hideLabel : showLabel);
    button.title = visible ? hideLabel : showLabel;
    button.querySelector('[data-eye-slash]').style.display = visible ? '' : 'none';
  }
  function enhance(root) {
    const inputs = root.querySelectorAll('input[type="password"]');
    inputs.forEach(function (input) {
      if (input.closest('.odir-password-field')) return;
      const wrapper = document.createElement('span');
      wrapper.className = 'odir-password-field';
      // Keep spacing utilities on the wrapper so the eye stays centered.
      for (const name of [...input.classList]) {
        if (/^m[bstexy]?-(?:\d|auto)/.test(name)) { wrapper.classList.add(name); input.classList.remove(name); }
      }
      input.before(wrapper);
      wrapper.appendChild(input);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'odir-password-toggle';
      button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path data-eye-slash d="m3 3 18 18"/></svg>';
      wrapper.appendChild(button);
      setVisible(input, button, false);
      button.addEventListener('click', function (event) {
        event.preventDefault(); // A wrapping label must not steal keyboard focus.
        setVisible(input, button, input.type === 'password');
      });
    });
  }
  function hideAll(root) {
    root.querySelectorAll('.odir-password-toggle').forEach(function (button) {
      setVisible(button.previousElementSibling, button, false);
    });
  }
  function start() {
    enhance(document);
    new MutationObserver(function (records) {
      if (records.some(record => record.addedNodes.length)) enhance(document);
    }).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('hidden.bs.modal', event => hideAll(event.target));
    document.addEventListener('reset', event => hideAll(event.target));
    window.addEventListener('pageshow', () => hideAll(document));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
