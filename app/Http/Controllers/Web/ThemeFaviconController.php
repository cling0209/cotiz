<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ThemePalette;
use Illuminate\Http\Response;

class ThemeFaviconController extends Controller
{
    public function svg(): Response
    {
        $primary = e(ThemePalette::primary());
        $gradientStart = e(ThemePalette::primaryGradientStart());
        $gradientEnd = e(ThemePalette::primaryGradientEnd());
        $accent = e(ThemePalette::accent());
        $letter = e(ThemePalette::faviconLetter());
        $label = e(config('app.name', 'Cotiz'));
        $version = ThemePalette::faviconVersion();

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" role="img" aria-label="{$label}">
  <defs>
    <linearGradient id="siteGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$gradientStart}"/>
      <stop offset="50%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$gradientEnd}"/>
    </linearGradient>
  </defs>
  <rect width="40" height="40" rx="10" fill="url(#siteGrad)"/>
  <text x="11" y="29" font-family="system-ui, sans-serif" font-size="22" font-weight="800" fill="#ffffff">{$letter}</text>
  <circle cx="32" cy="10" r="5" fill="{$accent}"/>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.'"',
        ]);
    }
}
