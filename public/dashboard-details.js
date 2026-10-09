document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('dashboardDetailModal');
  const body = document.getElementById('dashboardDetailBody');
  const title = document.getElementById('dashboardDetailTitle');
  const modal = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
  const detailUrl = (type) => {
    const generated = window.dashboardDetailUrls?.[type];
    if (generated) {
      const parsed = new URL(generated, window.location.origin);
      return `${parsed.pathname}${parsed.search}`;
    }
    const base = window.location.pathname.replace(/\/dashboard(?:\/.*)?$/, '').replace(/\/$/, '');
    return `${base}/dashboard/details/${encodeURIComponent(type)}`;
  };
  const load = (url, fallbackTried = false) => {
    if (!body || !modal) return;
    body.innerHTML = '<div class="text-center p-5"><span class="spinner-border"></span></div>';
    modal.show();
    fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}})
      .then(response => { if (!response.ok) { const error = new Error(`HTTP ${response.status}`); error.status = response.status; throw error; } return response.text(); })
      .then(html => {
        body.innerHTML = html;
        body.querySelector('[data-dashboard-detail-search]')?.addEventListener('submit', event => {
          event.preventDefault();
          const targetUrl = new URL(url, window.location.origin);
          new FormData(event.target).forEach((value, key) => value && targetUrl.searchParams.set(key, value));
          load(targetUrl.toString());
        });
      })
      .catch(error => {
        const path = new URL(url, window.location.origin).pathname;
        const alternate = path.includes('/index.php/') ? null : `${window.location.origin}${path.replace('/dashboard/', '/index.php/dashboard/')}`;
        if (!fallbackTried && error?.status === 404 && alternate && alternate !== url) return load(alternate, true);
        console.error('Dashboard details request failed', {url, error});
        body.innerHTML = `<div class="alert alert-danger mb-0">تعذر تحميل التفاصيل.<small class="d-block mt-2 text-muted">${error?.message || 'تحقق من اتصال قاعدة البيانات ومسار التطبيق.'}</small><small class="d-block mt-1 text-muted text-break">الرابط: ${url}</small><small class="d-block mt-1 text-muted text-break">الصفحة الحالية: ${window.location.pathname}</small></div>`;
      });
  };
  document.querySelectorAll('[data-dashboard-detail]').forEach(element => element.addEventListener('click', event => {
    event.preventDefault();
    const label = element.querySelector('b')?.textContent || 'تفاصيل المؤشر';
    const icon = element.dataset.detailIcon || 'grid-1x2-fill';
    title?.querySelector('span:last-child').replaceChildren(document.createTextNode(label));
    const iconElement = title?.querySelector('i');
    if (iconElement) iconElement.className = `bi bi-${icon}`;
    load(detailUrl(element.dataset.dashboardDetail));
  }));
  const panelDetails = { 'attendance-panel': ['attendance', 'pie-chart-fill'], 'recent-leaves': ['leaves', 'calendar2-check'], 'dates-panel': ['documents-expiring', 'file-earmark-text-fill'], 'payroll-panel': ['payroll-summary', 'cash-stack'], 'requests-panel': ['requests', 'file-earmark-text'], 'document-panel': ['documents-incomplete', 'folder2-open'], 'new-employees': ['new-employees', 'people-fill'] };
  document.querySelectorAll('.dashboard-reference-layout .reference-panel').forEach(panel => {
    const match = Object.entries(panelDetails).find(([className]) => panel.classList.contains(className));
    if (!match) return;
    panel.classList.add('dashboard-detail-card');
    panel.setAttribute('tabindex', '0');
    panel.setAttribute('role', 'button');
    panel.addEventListener('click', event => {
      if (event.target.closest('a,button,input,select,form')) return;
      const [type, icon] = match;
      const heading = panel.querySelector('.panel-head h3')?.textContent?.trim() || 'تفاصيل المؤشر';
      title?.querySelector('span:last-child').replaceChildren(document.createTextNode(heading));
      const iconElement = title?.querySelector('i');
      if (iconElement) iconElement.className = `bi bi-${icon}`;
      load(detailUrl(type));
    });
    panel.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      if (event.target.closest('a,button,input,select,form')) return;
      event.preventDefault(); panel.click();
    });
  });
  document.querySelector('[data-dashboard-action="document-upload"]')?.addEventListener('click', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('documentUploadModal')).show());
  const employeeSearch = document.getElementById('documentEmployeeSearch');
  const employeeResults = document.getElementById('documentEmployeeResults');
  employeeSearch?.addEventListener('input', () => {
    const term = employeeSearch.value.trim();
    if (term.length < 2) { employeeResults.innerHTML = ''; return; }
    const base = window.location.pathname.replace(/\/dashboard(?:\/.*)?$/, '').replace(/\/$/, '');
    fetch(`${base}/dashboard/employee-search?search=` + encodeURIComponent(term), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(response => response.json())
      .then(items => { employeeResults.innerHTML = items.length ? items.map(item => `<a class="list-group-item list-group-item-action" href="${item.url}">${item.name} <small class="text-muted">(${item.code})</small></a>`).join('') : '<div class="text-muted small p-2">لا توجد نتائج.</div>'; });
  });
});
