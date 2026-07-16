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
        <div class="page-loader__status" id="page-loader-status" hidden>
            <p class="page-loader__msg" id="page-loader-msg"></p>
            <div class="page-loader__progress-wrap" id="page-loader-progress-wrap" hidden>
                <div class="progress page-loader__progress">
                    <div
                        id="page-loader-progress-bar"
                        class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                        role="progressbar"
                        style="width: 0%"
                        aria-valuenow="0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</div>
