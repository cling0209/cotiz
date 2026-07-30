@php
    use App\Support\ThemePalette;

    $faviconUrl = route('theme.favicon', ['v' => ThemePalette::faviconVersion()]);
@endphp
<link rel="icon" href="{{ $faviconUrl }}" type="image/svg+xml">
<script>
(function () {
    var href = @json($faviconUrl);
    var links = document.querySelectorAll('link[rel="icon"]');
    links.forEach(function (link) {
        if (link.getAttribute('href') !== href) {
            link.setAttribute('href', href);
        }
    });
    if (links.length === 0) {
        var link = document.createElement('link');
        link.rel = 'icon';
        link.type = 'image/svg+xml';
        link.href = href;
        document.head.appendChild(link);
    }
})();
</script>
