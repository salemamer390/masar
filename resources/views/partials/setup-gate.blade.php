@if(auth()->check() && \App\Http\Controllers\EmployeeImportController::importGateBlocks(request()))
<div aria-hidden="true" class="modal fade" data-bs-backdrop="static" data-bs-keyboard="false" id="importSetupGateModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border:2px solid #d92d20;border-radius:16px">
<div class="modal-body text-center p-4">
<i class="bi bi-exclamation-octagon-fill" style="font-size:48px;color:#d92d20"></i>
<h4 class="mt-2 mb-2">{{ __('اضبط أوقات الدوام أولًا') }}</h4>
<p class="mb-3" style="line-height:1.9">{{ __('قبل استيراد أي بيانات أو إضافة موظف، لازم تضبط في الإعدادات (تبويب «أوقات الدوام»): الدوام اليومي، دورة المسير وتصحيح الحضور، وأيام الراحة الأسبوعية. كل حسابات الحضور والرواتب بتعتمد عليها.') }}</p>
<button type="button" class="btn btn-danger px-5" id="importSetupGateOk">{{ __('موافق') }}</button>
</div></div></div></div>
<script>
(function () {
  const gateEl = document.getElementById('importSetupGateModal');
  if (!gateEl) return;
  let acknowledged = false;
  let after = null;
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const gateModal = () => bootstrap.Modal.getOrCreateInstance(gateEl);
  // Used by the guided tour: show the notice, then run the callback after موافق.
  window.masarSetupGate = {
    open(callback) {
      after = callback;
      gateModal().show();
    },
  };
  // Import and add-employee windows stay closed until موافق was pressed once.
  document.addEventListener('show.bs.modal', (event) => {
    const id = event.target && event.target.id;
    if (acknowledged || (id !== 'employeesImportModal' && id !== 'addEmployeeModal')) return;
    event.preventDefault();
    after = () => bootstrap.Modal.getOrCreateInstance(event.target).show();
    gateModal().show();
  });
  document.getElementById('importSetupGateOk').addEventListener('click', () => {
    fetch(@json(route('employees.import.setup-ack')), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      credentials: 'same-origin',
    }).then((r) => {
      if (!r.ok) return;
      acknowledged = true;
      const run = after;
      after = null;
      gateEl.addEventListener('hidden.bs.modal', () => { if (run) run(); }, { once: true });
      gateModal().hide();
    });
  });
})();
</script>
@endif
