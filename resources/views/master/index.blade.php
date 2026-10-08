@extends('layouts.app')
@section('title','القوائم الأساسية')
@section('content')
<div class="page-head"><div><h1>القوائم الأساسية</h1><p>إدارة البيانات المرجعية المستخدمة في نماذج النظام.</p></div></div>
<div class="master-layout">
    <aside class="master-sidebar">
        <div class="master-menu-title">القوائم</div>
        @foreach($meta as $key=>$m)
            <a class="master-menu text-decoration-none {{$type===$key?'active':''}}" href="{{route('master.index',['type'=>$key])}}"><span>{{$m['title']}}</span><span>{{$counts[$key]??0}}</span></a>
        @endforeach
    </aside>
    <section class="panel master-data-panel">
        <div class="panel-head">
            <div><h3>{{$currentMeta['title']}}</h3><span>{{$currentMeta['desc']}}</span></div>
            <div class="d-flex gap-2">
                @if(auth()->user()?->hasPermission('roles.edit'))<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#masterModal"><i class="bi bi-plus-lg"></i> إضافة</button>@endif
            </div>
        </div>
        <div class="p-3"><div class="search-table master-search"><i class="bi bi-search"></i><input id="masterSearch" placeholder="بحث..."></div></div>
        <div class="master-rows" id="masterRows">
            @if($type === 'documents')
                @php($documentGroups=['basic'=>'مستندات أساسية','job'=>'مستندات خاصة بالوظيفة','workplace'=>'مستندات خاصة بمكتب العمل'])
                @foreach($documentGroups as $groupKey=>$groupLabel)
                    <div class="master-group-title">{{$groupLabel}}</div>
                    @forelse($items->where('category',$groupKey) as $item)
                        <div class="master-row master-document-row">
                            <b>{{$item->name}}</b>
                            <span>{{$item->is_required?'مطلوب لكل موظف':'حسب الحاجة'}}</span>
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <em>{{$item->is_active?'نشط':'غير نشط'}}</em>
                                @if(auth()->user()?->hasPermission('roles.edit'))
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editDocumentType{{$item->id}}"><i class="bi bi-pencil-square"></i> تعديل</button>
                                    <form method="POST" action="{{route('master.documents.destroy',$item)}}" onsubmit="return confirm('هل تريد حذف نوع المستند: {{$item->name}}؟')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-muted small p-2">لا توجد عناصر في هذا القسم.</div>
                    @endforelse
                @endforeach
            @else
                @forelse($items as $item)
                    <div class="master-row"><b>{{$item->{$currentMeta['name_col']}??'—'}}</b><span>{{$item->{$currentMeta['code_col']}??'—'}}</span><em>نشط</em></div>
                @empty
                    <div class="text-center text-muted py-5">لا توجد عناصر.</div>
                @endforelse
            @endif
        </div>
    </section>
</div>

<div class="modal fade" id="masterModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="{{route('master.store',$type)}}">@csrf
            <div class="modal-header"><h5>إضافة {{$currentMeta['title']}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="form-label">الاسم</label><input name="name" class="form-control" required>
                <label class="form-label mt-3">الكود / البيان الإضافي</label><input name="code" class="form-control">
                @if($type === 'documents')
                    <label class="form-label mt-3">القسم</label>
                    <select name="category" class="form-select"><option value="basic">مستندات أساسية</option><option value="job">مستندات خاصة بالوظيفة</option><option value="workplace">مستندات خاصة بمكتب العمل</option></select>
                    <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="required" value="1"><span class="form-check-label">مستند مطلوب لكل موظف</span></label>
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ</button></div>
        </form>
    </div></div>
</div>

@if($type === 'documents' && auth()->user()?->hasPermission('roles.edit'))
    @foreach($items as $item)
        <div class="modal fade" id="editDocumentType{{$item->id}}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <form method="POST" action="{{route('master.documents.update',$item)}}">@csrf @method('PUT')
                    <div class="modal-header"><h5>تعديل نوع المستند</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">الاسم</label><input name="name" class="form-control" value="{{$item->name}}" required>
                        <label class="form-label mt-3">القسم</label>
                        <select name="category" class="form-select"><option value="basic" @selected($item->category==='basic')>مستندات أساسية</option><option value="job" @selected($item->category==='job')>مستندات خاصة بالوظيفة</option><option value="workplace" @selected($item->category==='workplace')>مستندات خاصة بمكتب العمل</option></select>
                        <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="required" value="1" @checked($item->is_required)><span class="form-check-label">مستند مطلوب لكل موظف</span></label>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديلات</button></div>
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
</script>
@endpush
@endsection
