@push('head')
<script>
(function () {
    try {
        if (sessionStorage.getItem('page-loader-pending') === '1' || document.readyState !== 'complete') {
            document.documentElement.classList.add('page-loader-active');
        }
    } catch (e) {}
})();
</script>
@endpush

<div id="page-loader" aria-hidden="true" aria-live="polite" role="status">
    <div class="page-loader__scene">
        @php
            $loaderSvg = file_get_contents(public_path('images/cotiz-loader.svg'));
            $loaderSvg = preg_replace(
                '/<svg([^>]*)>/',
                '<svg class="page-loader__icon"$1 aria-hidden="true">',
                $loaderSvg,
                1
            );
            $loaderSvg = preg_replace('/\s*role="img"\s*/', ' ', $loaderSvg, 1);
            $loaderSvg = preg_replace('/\s*aria-label="[^"]*"\s*/', ' ', $loaderSvg, 1);
        @endphp
        {!! $loaderSvg !!}
    </div>
</div>
