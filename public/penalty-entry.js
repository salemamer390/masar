(() => {
  const sync = (form) => {
    const toggle = form.querySelector('[data-penalty-deduct]');
    const wrap = form.querySelector('[data-penalty-days-wrap]');
    const days = form.querySelector('[data-penalty-days]');
    if (!toggle || !wrap || !days) return;
    wrap.hidden = !toggle.checked;
    days.disabled = !toggle.checked;
    days.required = toggle.checked;
  };
  window.syncPenaltyDeduction = sync;
  document.addEventListener('change', event => {
    if (event.target.matches('[data-penalty-deduct]')) sync(event.target.form);
  });
  document.addEventListener('reset', event => {
    if (event.target.querySelector('[data-penalty-deduct]')) setTimeout(() => sync(event.target), 0);
  });
  // Shared by the discipline page and its existing dashboard-loaded form.
  document.addEventListener('shown.bs.modal', event => {
    event.target.querySelectorAll('form').forEach(sync);
  });
  document.addEventListener('masar:success', event => {
    const type = event.detail?.penalty?.penalty_type || event.detail?.penalty?.penaltyType;
    if (!type?.name) return;
    document.querySelectorAll('datalist[id$="Types"]').forEach(list => {
      if (![...list.options].some(option => option.value === type.name)) {
        const option = document.createElement('option');
        option.value = type.name;
        list.append(option);
      }
    });
  });
  document.querySelectorAll('form').forEach(sync);
})();
