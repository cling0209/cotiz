@php
    use App\Support\ThemePalette;
@endphp
<style>
:root {
    --admin-primary: {{ ThemePalette::primary() }};
    --admin-primary-hover: {{ ThemePalette::primaryHover() }};
    --admin-accent: {{ ThemePalette::accent() }};
    --shop-primary: {{ ThemePalette::primary() }};
    --shop-primary-dark: {{ ThemePalette::primaryHover() }};
    --bs-primary: {{ ThemePalette::primary() }};
    --bs-primary-rgb: {{ ThemePalette::primaryRgbCsv() }};
    --bs-link-color: {{ ThemePalette::primary() }};
    --bs-link-hover-color: {{ ThemePalette::primaryHover() }};
}
</style>
