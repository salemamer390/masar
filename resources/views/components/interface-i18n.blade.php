@php
  $interfaceMessages = json_decode(file_get_contents(lang_path(app()->getLocale().'.json')), true) ?: [];
@endphp
<script>
window.masarMessages = @json($interfaceMessages);
window.masarT = key => {
  if (typeof key !== 'string') return key;
  if (Object.hasOwn(window.masarMessages, key)) return window.masarMessages[key];
  const core = key.trim();
  return Object.hasOwn(window.masarMessages, core) ? key.replace(core, window.masarMessages[core]) : key;
};
</script>
<script>
// Only pass developer-authored static HTML fragments here, never fetched/user HTML.
window.masarStaticHtml = html => html
  .replace(/(^|>)([^<>]*)(?=<|$)/g, (all, start, text) => {
    const key = text.trim();
    const value = window.masarT(key);
    return start + (key && value !== key ? text.replace(key, value) : text);
  })
  .replace(/(title|placeholder|aria-label)="([^"]+)"/g,
    (all, name, value) => name + '="' + window.masarT(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"');
</script>
