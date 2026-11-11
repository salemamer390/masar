@extends('layouts.app')

@section('title', __('الأدوار والصلاحيات'))

@section('content')
<div class="roles-page">
  <div class="roles-breadcrumb">{{ __('الإعدادات') }} <span>‹</span> {{ __('الأدوار والصلاحيات') }}</div>
  <div class="roles-page-title">
    <span class="roles-title-icon"><i class="bi bi-people-fill"></i></span>
    <div><h1>{{ __('الأدوار والصلاحيات') }}</h1><p>{{ __('اختر دورًا لإدارة بياناته والصلاحيات الممنوحة له.') }}</p></div>
    @if($canCreate = auth()->user()?->hasPermission('roles.create'))
      <button class="btn btn-primary roles-add-btn" data-bs-toggle="modal" data-bs-target="#createRoleModal"><i class="bi bi-plus-lg"></i> {{ __('إضافة دور جديد') }}</button>
    @endif
  </div>

  <div class="roles-layout">
    <section class="roles-panel roles-editor">
      <div class="roles-editor-head"><span class="roles-emblem"><i class="bi bi-shield-lock-fill"></i></span><div><h2 id="roleHeading">—</h2><p id="roleSub">—</p></div><span id="roleStatus" class="roles-status"><i class="bi bi-check-circle-fill"></i> {{ __('دور أساسي') }}</span></div>
      <div class="roles-tabs"><button class="roles-tab active" data-tab="permissions"><i class="bi bi-shield-check"></i> {{ __('الصلاحيات') }}</button><button class="roles-tab" data-tab="details"><i class="bi bi-sliders"></i> {{ __('البيانات الأساسية') }}</button></div>
      <div id="permissionsView">
        <div class="roles-fields"><label>{{ __('اسم الدور') }} <em>*</em><input id="roleName" maxlength="150"></label><label>{{ __('وصف الدور') }}<input id="roleDescription" maxlength="255"></label></div>
        <div class="roles-perm-heading"><div><h3><i class="bi bi-gear-wide-connected"></i> {{ __('الصلاحيات') }}</h3><small>{{ __('حدد ما يمكن لهذا الدور تنفيذه داخل كل قسم.') }}</small></div><div class="roles-bulk"><button class="btn btn-soft" id="selectAll" type="button">{{ __('تحديد الكل') }}</button><button class="btn btn-outline-secondary" id="clearAll" type="button">{{ __('إلغاء تحديد الكل') }}</button></div></div>
        <div id="moduleList" class="roles-module-list"></div>
        <div class="roles-footer"><span><i class="bi bi-info-circle"></i> {{ __('راجع الصلاحيات قبل الحفظ.') }}</span><div><button class="btn btn-light" id="cancelChanges" type="button">{{ __('إلغاء') }}</button><button class="btn btn-primary" id="saveRole" type="button"><i class="bi bi-floppy"></i> {{ __('حفظ التغييرات') }}</button></div></div>
      </div>
      <div id="detailsView" class="roles-details-view" hidden><div class="roles-fields roles-fields-single"><label>{{ __('اسم الدور') }}<input id="detailName" maxlength="150"></label><label>{{ __('وصف الدور') }}<textarea id="detailDescription" rows="3" maxlength="255"></textarea></label></div><p class="roles-hint">{{ __('يمكن تعديل اسم ووصف الأدوار المخصصة. الأدوار النظامية محمية من التعديل.') }}</p><button class="btn btn-primary" id="saveDetails" type="button">{{ __('حفظ البيانات الأساسية') }}</button></div>
    </section>

    <aside class="roles-panel roles-list-panel"><div class="roles-list-head"><div><h2>{{ __('قائمة الأدوار') }}</h2><p>{{ __('اختر دورًا لعرض صلاحياته وتعديلها.') }}</p></div><i class="bi bi-person-gear"></i></div><label class="roles-search"><i class="bi bi-search"></i><input id="roleSearch" placeholder="{{ __('البحث في الأدوار...') }}"></label><div id="roleList" class="roles-list"></div><div class="roles-list-foot"><span id="roleCount"></span></div></aside>
  </div>
</div>

@if($canCreate)
<div class="modal fade" id="createRoleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('roles.store') }}">@csrf<div class="modal-header"><h5>{{ __('إضافة دور جديد') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('اسم الدور') }}</label><input name="display_name" class="form-control" required maxlength="150"><label class="form-label mt-3">{{ __('الوصف') }}</label><textarea name="description" class="form-control" rows="3" maxlength="255"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('إنشاء الدور') }}</button></div></form></div></div></div>
@endif
@endsection

@push('scripts')
<link rel="stylesheet" href="{{ asset('css/pages/roles-index-01.css') }}?v={{ filemtime(public_path('css/pages/roles-index-01.css')) }}">
<script>
(() => {
  const roles = @json($rolePayload);
  const modules = @json($permissionPayload);
  const canEdit = @json(auth()->user()?->hasPermission('roles.edit'));
  const canDelete = @json(auth()->user()?->hasPermission('roles.delete'));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
  let active = 0, draft = [], snapshot = [];
  const $ = (id) => document.getElementById(id);
  const icons = ['bi-shield-fill-check','bi-building-fill-gear','bi-person-workspace','bi-person-vcard','bi-person-plus','bi-cash-coin','bi-calendar2-week','bi-diagram-3','bi-calculator','bi-person','bi-eye'];
  const moduleIcons = {dashboard:'speedometer2',employees:'people-fill',attendance:'clock-history',leaves:'calendar2-check',documents:'folder2-open',salary:'cash-stack',payroll:'wallet2',insurance:'shield-check',penalties:'exclamation-triangle',movements:'arrow-left-right',requests:'inbox',reports:'bar-chart-line',roles:'shield-lock',users:'person-lock'};
  const colors = ['blue','green','purple','teal','purple','green','cyan','orange','gold','slate','blue'];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const role = () => roles[active];
  const editable = () => canEdit && !role()?.system && !role()?.company_manager;
  const toast = (message) => { let el = document.querySelector('.roles-toast'); if (!el) { el=document.createElement('div'); el.className='roles-toast'; document.body.appendChild(el); } el.textContent=message; setTimeout(()=>el.remove(),2500); };
  function renderRoles() { const q=($('roleSearch').value||'').trim().toLowerCase(); const list=$('roleList'); list.innerHTML=''; const filtered=roles.map((r,i)=>({...r,index:i})).filter(r=>(r.name+' '+r.description).toLowerCase().includes(q)); filtered.forEach((r)=>{ const b=document.createElement('button'); b.type='button'; b.className='roles-list-item'+(r.index===active?' selected':''); b.innerHTML=`<span class="roles-list-icon"><i class="bi ${icons[r.index%icons.length]}"></i></span><span class="roles-list-copy"><strong>${esc(r.name)}</strong><small>${esc(r.description||'')}</small></span><span class="roles-users"><b>${Number(r.users||0)}</b><small>${window.masarT("مستخدم")}</small></span>${canDelete&&!r.system?'<span class="text-danger"><i class="bi bi-trash"></i></span>':''}`; b.addEventListener('click',(e)=>{if(e.target.closest('.text-danger')) return; selectRole(r.index);}); if(canDelete&&!r.system){ const del=b.querySelector('.text-danger'); del.title=window.masarT('حذف الدور'); del.addEventListener('click',(e)=>{e.stopPropagation();deleteRole(r);}); } list.appendChild(b); }); $('roleCount').textContent=`${window.masarT("عرض")} ${filtered.length} ${window.masarT("من أصل")} ${roles.length} ${window.masarT("دور")}`; }
  function renderModules(){ const list=$('moduleList'); list.innerHTML=''; modules.forEach((m,mi)=>{ const granted=new Set(draft); const selected=m.items.filter(p=>granted.has(Number(p.id))).length; const section=document.createElement('section'); section.className='roles-module'+(mi===0?' open':''); section.innerHTML=`<div class="roles-module-head"><i class="bi bi-chevron-left chevron"></i><span class="module-icon roles-module-icon-${colors[mi%colors.length]}"><i class="bi bi-${moduleIcons[m.key]||'grid'}"></i></span><span class="roles-module-title"><strong>${esc(m.name)}</strong><small>${selected} ${window.masarT("من")} ${m.items.length} ${window.masarT("صلاحيات مفعّلة")}</small></span><span class="roles-module-count">${m.items.length} ${window.masarT("صلاحية")}</span><label class="roles-select-module"><input class="roles-check module-all" type="checkbox" ${selected===m.items.length?'checked':''} ${editable()?'':'disabled'}> ${window.masarT("تحديد الكل")}</label></div><div class="roles-module-body"><div class="roles-permission-grid">${m.items.map(p=>`<label class="roles-permission-item"><input class="roles-check permission" type="checkbox" data-id="${p.id}" ${granted.has(Number(p.id))?'checked':''} ${editable()?'':'disabled'}><span>${esc(p.name)}</span></label>`).join('')}</div></div>`; section.querySelector('.roles-module-head').addEventListener('click',(e)=>{if(e.target.closest('label'))return;section.classList.toggle('open');}); section.querySelector('.module-all').addEventListener('change',(e)=>{const ids=m.items.map(p=>Number(p.id)); draft=e.target.checked?[...new Set([...draft,...ids])]:draft.filter(id=>!ids.includes(Number(id)));renderModules();}); section.querySelectorAll('.permission').forEach(cb=>cb.addEventListener('change',()=>{const id=Number(cb.dataset.id);draft=cb.checked?[...new Set([...draft,id])]:draft.filter(x=>Number(x)!==id);renderModules();})); list.appendChild(section); }); }
  function selectRole(index){active=index;draft=[...role().permission_ids].map(Number);snapshot=[...draft]; $('roleHeading').textContent=role().name; $('roleSub').textContent=role().description||''; $('roleName').value=role().name; $('roleDescription').value=role().description||''; $('detailName').value=role().name; $('detailDescription').value=role().description||''; $('roleStatus').innerHTML=role().company_manager?window.masarStaticHtml('<i class="bi bi-check-circle-fill"></i> مالك الشركة'):(role().system?window.masarStaticHtml('<i class="bi bi-shield-fill-check"></i> دور نظامي'):window.masarStaticHtml('<i class="bi bi-check-circle-fill"></i> دور مخصص')); $('roleName').disabled=!editable(); $('roleDescription').disabled=!editable(); $('detailName').disabled=!editable(); $('detailDescription').disabled=!editable(); $('saveRole').hidden=!editable(); $('saveDetails').hidden=!editable(); $('selectAll').hidden=!editable(); $('clearAll').hidden=!editable(); renderRoles(); renderModules(); }
  async function saveRole(){if(!editable())return; const body=new URLSearchParams(); body.set('display_name',$('roleName').value.trim()); body.set('description',$('roleDescription').value.trim()); draft.forEach(id=>body.append('permissions[]',id)); const res=await fetch(`{{ url('/roles') }}/${role().id}`,{method:'PUT',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf,'Content-Type':'application/x-www-form-urlencoded'},body}); if(!res.ok){toast(window.masarT('تعذر حفظ التغييرات.'));return;} role().name=$('roleName').value.trim();role().description=$('roleDescription').value.trim();role().permission_ids=[...draft];snapshot=[...draft];selectRole(active);toast(window.masarT('تم حفظ التغييرات بنجاح.')); }
  async function deleteRole(r){if(!confirm(`${window.masarT("هل تريد حذف الدور")} «${r.name}»${window.masarT("؟")}`))return; const res=await fetch(`{{ url('/roles') }}/${r.id}`,{method:'DELETE',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}}); if(!res.ok){toast(window.masarT('لا يمكن حذف هذا الدور.'));return;} roles.splice(r.index,1);active=Math.max(0,active-1);selectRole(active);toast(window.masarT('تم حذف الدور بنجاح.'));}
  $('roleSearch').addEventListener('input',renderRoles); $('selectAll').addEventListener('click',()=>{if(!editable())return;draft=modules.flatMap(m=>m.items.map(p=>Number(p.id)));renderModules();}); $('clearAll').addEventListener('click',()=>{if(!editable())return;draft=[];renderModules();}); $('saveRole').addEventListener('click',saveRole); $('saveDetails').addEventListener('click',()=>{ $('roleName').value=$('detailName').value; $('roleDescription').value=$('detailDescription').value; saveRole(); }); $('cancelChanges').addEventListener('click',()=>{draft=[...snapshot];renderModules();toast(window.masarT('تم إلغاء التغييرات غير المحفوظة.'));}); document.querySelectorAll('.roles-tab').forEach(tab=>tab.addEventListener('click',()=>{document.querySelectorAll('.roles-tab').forEach(x=>x.classList.toggle('active',x===tab));const details=tab.dataset.tab==='details';$('detailsView').hidden=!details;$('permissionsView').hidden=details;})); if(roles.length)selectRole(0); else {$('roleHeading').textContent=window.masarT('لا توجد أدوار');$('saveRole').hidden=true;}
})();
</script>
@endpush
