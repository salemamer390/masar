@extends('layouts.app')
@section('title',__('القوائم الأساسية'))
@section('content')
<div class="page-head"><div><h1>{{ __('القوائم الأساسية') }}</h1><p>{{ __('إدارة البيانات المرجعية المستخدمة في نماذج النظام.') }}</p></div></div>
<div class="master-layout">
    <aside class="master-sidebar">
        <div class="master-menu-title">{{ __('القوائم') }}</div>
        @foreach($meta as $key=>$m)
            <a class="master-menu text-decoration-none {{$type===$key?'active':''}}" href="{{route('master.index',['type'=>$key])}}"><span>{{__($m['title'])}}</span><span>{{$counts[$key]??0}}</span></a>
        @endforeach
    </aside>
    <section class="panel master-data-panel">
        <div class="panel-head">
            <div><h3>{{__($currentMeta['title'])}}</h3><span>{{__($currentMeta['desc'])}}</span></div>
            <div class="d-flex gap-2">
                @if(auth()->user()?->hasPermission('roles.edit'))<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#masterModal"><i class="bi bi-plus-lg"></i> {{ __('إضافة') }}</button>@endif
            </div>
        </div>
        <div class="p-3"><div class="search-table master-search"><i class="bi bi-search"></i><input id="masterSearch" placeholder="{{ __('بحث...') }}"></div></div>
        <div class="master-rows" id="masterRows">
            @if($type === 'documents')
                @php($documentGroups=['basic'=>__('مستندات أساسية'),'job'=>__('مستندات خاصة بالوظيفة'),'workplace'=>__('مستندات خاصة بمكتب العمل')])
                @foreach($documentGroups as $groupKey=>$groupLabel)
                    <div class="master-group-title">{{$groupLabel}}</div>
                    @forelse($items->where('category',$groupKey) as $item)
                        <div class="master-row master-document-row document-type-card">
                            <b>{{$item->name}}</b>
                            <span>{{$item->is_required?__('مطلوب لكل موظف'):__('حسب الحاجة')}}</span>
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <em>{{$item->is_active?__('نشط'):__('غير نشط')}}</em>
                                @if(auth()->user()?->hasPermission('roles.edit') && $item->is_active)
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editDocumentType{{$item->id}}"><i class="bi bi-pencil-square"></i> {{ __('تعديل') }}</button>
                                    <form method="POST" action="{{route('master.documents.destroy',$item)}}" onsubmit="return confirm('هل تريد حذف نوع المستند: {{$item->name}}؟')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="{{ __('حذف') }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-muted small p-2">{{ __('لا توجد عناصر في هذا القسم.') }}</div>
                    @endforelse
                @endforeach
            @else
                @forelse($items as $item)
                    <div class="master-row">
                        <b>{{$item->{$currentMeta['name_col']}??'—'}}</b>
                        <span>{{$item->{$currentMeta['code_col']}??'—'}}</span>
                        <em>{{($item->is_active ?? true) ? __('نشط') : __('غير نشط')}}</em>
                        @if(auth()->user()?->hasPermission('roles.edit') && ($item->is_active ?? true))
                            <div class="master-row-actions">
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editMaster{{$item->id}}" title="{{ __('تعديل') }}"><i class="bi bi-pencil-square"></i> {{ __('تعديل') }}</button>
                                <form method="POST" action="{{route('master.destroy', [$type, $item->id])}}" onsubmit="return confirm('هل تريد حذف هذا العنصر؟')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('حذف') }}"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center text-muted py-5">{{ __('لا توجد عناصر.') }}</div>
                @endforelse
            @endif
        </div>
    </section>
</div>

<div class="modal fade" id="masterModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="{{route('master.store',$type)}}">@csrf
            <div class="modal-header"><h5>{{ __('إضافة') }} {{__($currentMeta['title'])}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="form-label">{{ __('الاسم') }}</label><input name="name" class="form-control" required>
                <label class="form-label mt-3">{{ __('الكود / البيان الإضافي') }}</label><input name="code" class="form-control">
                @if($type === 'documents')
                    <label class="form-label mt-3">{{ __('القسم') }}</label>
                    <select name="category" class="form-select"><option value="basic">{{ __('مستندات أساسية') }}</option><option value="job">{{ __('مستندات خاصة بالوظيفة') }}</option><option value="workplace">{{ __('مستندات خاصة بمكتب العمل') }}</option></select>
                    <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="required" value="1"><span class="form-check-label">{{ __('مستند مطلوب لكل موظف') }}</span></label>
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ') }}</button></div>
        </form>
    </div></div>
</div>

@if($type === 'documents' && auth()->user()?->hasPermission('roles.edit'))
    @foreach($items as $item)
        <div class="modal fade" id="editDocumentType{{$item->id}}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <form method="POST" action="{{route('master.documents.update',$item)}}">@csrf @method('PUT')
                    <div class="modal-header"><h5>{{ __('تعديل نوع المستند') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('الاسم') }}</label><input name="name" class="form-control" value="{{$item->name}}" required>
                        <label class="form-label mt-3">{{ __('القسم') }}</label>
                        <select name="category" class="form-select"><option value="basic" @selected($item->category==='basic')>{{ __('مستندات أساسية') }}</option><option value="job" @selected($item->category==='job')>{{ __('مستندات خاصة بالوظيفة') }}</option><option value="workplace" @selected($item->category==='workplace')>{{ __('مستندات خاصة بمكتب العمل') }}</option></select>
                        <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="required" value="1" @checked($item->is_required)><span class="form-check-label">{{ __('مستند مطلوب لكل موظف') }}</span></label>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ التعديلات') }}</button></div>
                </form>
            </div></div>
        </div>
    @endforeach
@endif

@if($type !== 'documents' && auth()->user()?->hasPermission('roles.edit'))
    @foreach($items as $item)
        <div class="modal fade" id="editMaster{{$item->id}}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <form method="POST" action="{{route('master.update', [$type, $item->id])}}">@csrf @method('PUT')
                    <div class="modal-header"><h5>{{ __('تعديل العنصر') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('الاسم') }}</label>
                        <input name="name" class="form-control" value="{{$item->{$currentMeta['name_col']} ?? ''}}" required>
                        <label class="form-label mt-3">{{ __('الكود / البيان الإضافي') }}</label>
                        <input name="code" class="form-control" value="{{$item->{$currentMeta['code_col']} ?? ''}}">
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ التعديل') }}</button></div>
                </form>
            </div></div>
        </div>
    @endforeach
@endif

@push('scripts')
<script>
document.getElementById('masterSearch')?.addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('#masterRows .master-row').forEach(row => row.style.display = row.innerText.toLowerCase().includes(q) ? 'grid' : 'none');
});

// Adding a reference item is safe to handle in-place: the server already
// returns the persisted model, while edits/deletes of document types remain
// normal submissions until their dependent-record behavior is migrated.
const masterForm = document.querySelector('#masterModal form');
if (masterForm) masterForm.dataset.ajax = 'true';
const masterType = @json($type);
const masterRows = document.getElementById('masterRows');
const escapeMaster = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
document.addEventListener('masar:success', event => {
    if (event.target !== masterForm || !masterRows || !event.detail?.item) return;
    if (masterType === 'documents') {
        bootstrap.Modal.getInstance(document.getElementById('masterModal'))?.hide();
        masterForm.reset();
        return;
    }
    const item = event.detail.item;
    const name = item.name ?? item.title ?? '—';
    const code = item.job_code ?? item.address ?? item.default_days ?? item.id ?? '—';
    const row = document.createElement('div');
    row.className = masterType === 'documents' ? 'master-row master-document-row document-type-card' : 'master-row';
    if (masterType === 'documents') {
        row.innerHTML = `<b>${escapeMaster(name)}</b><span>${item.is_required ? window.masarT('مطلوب لكل موظف') : window.masarT('حسب الحاجة')}</span><div><em>${item.is_active === false ? window.masarT('غير نشط') : window.masarT('نشط')}</em></div>`;
    } else {
        row.innerHTML = `<b>${escapeMaster(name)}</b><span>${escapeMaster(code)}</span><em>${window.masarT("نشط")}</em>`;
    }
    masterRows.querySelector('.text-center.text-muted')?.remove();
    masterRows.appendChild(row);
    const activeMenu = document.querySelector('.master-menu.active');
    const count = activeMenu?.querySelector('span:last-child');
    if (count) count.textContent = String(Number(count.textContent) + 1);
    bootstrap.Modal.getInstance(document.getElementById('masterModal'))?.hide();
    masterForm.reset();
});

// Document types have grouped rows and one edit modal per row.  Refresh just
// this server-rendered section after a create, edit or delete so its grouping,
// permissions and modal data stay accurate without reloading the whole page.
if (masterType === 'documents') {
    const documentForms = () => [
        ...(masterForm ? [masterForm] : []),
        ...document.querySelectorAll('[id^="editDocumentType"] form, form[action*="/master/documents/"]'),
    ];
    const isDocumentForm = form => {
        if (form === masterForm) return true;
        const path = new URL(form.action, window.location.origin).pathname;
        return /^\/master\/documents\/\d+$/.test(path);
    };
    const bindDocumentForms = () => documentForms().filter(isDocumentForm).forEach(form => {
        form.dataset.ajax = 'true';
        form.dataset.ajaxLabel = form.querySelector('input[name="_method"][value="DELETE"]') ? window.masarT('جارٍ الحذف') : window.masarT('جارٍ الحفظ');
        if (form.querySelector('input[name="_method"][value="DELETE"]')) {
            form.dataset.ajaxConfirm = window.masarT('هل تريد حذف نوع المستند؟');
            form.removeAttribute('onsubmit');
        }
    });
    const refreshDocumentTypes = async () => {
        const response = await fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const source = new DOMParser().parseFromString(await response.text(), 'text/html');
        const freshRows = source.getElementById('masterRows');
        const currentRows = document.getElementById('masterRows');
        if (freshRows && currentRows) currentRows.replaceWith(freshRows);
        document.querySelectorAll('[id^="editDocumentType"]').forEach(modal => modal.remove());
        source.querySelectorAll('[id^="editDocumentType"]').forEach(modal => document.body.appendChild(modal.cloneNode(true)));
        bindDocumentForms();
    };
    bindDocumentForms();
    document.addEventListener('masar:success', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !isDocumentForm(form)) return;
        bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
        try {
            await refreshDocumentTypes();
        } catch (error) {
            console.error('Document types refresh failed', error);
        }
    });
}
</script>
@endpush
@endsection
