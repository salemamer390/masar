@props(['paginator', 'default' => 25])
@if($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
<div class="d-flex justify-content-center align-items-center gap-3 flex-wrap mt-3 pagination-controls">
    @if($paginator->hasPages()) {!! $paginator->links() !!} @endif
    <form method="GET" action="{{ url()->current() }}" class="d-flex align-items-center gap-2">
        @foreach(request()->except(['page', 'per_page']) as $key => $value)
            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label class="small text-muted mb-0" for="perPageControl">عدد الصفوف</label>
        <select id="perPageControl" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="عدد الصفوف في الصفحة">
            @foreach([25, 50, 100, 200] as $size)
                <option value="{{ $size }}" @selected((int) request('per_page', $default) === $size)>{{ $size }}</option>
            @endforeach
        </select>
    </form>
</div>
@endif
