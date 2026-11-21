{{-- One listing photograph, as WebP where the file exists and JPEG otherwise.

     $image  ListingImage
     $thumb  true for the grid-card size, false for the full photograph
     $alt    alt text
     $class  classes for the <img>, which is the element that is styled
     $lazy   loading="lazy" unless false (the first photograph on a page is
             the one that must not be lazy)

     The <img> keeps the JPEG as its own src, so a browser without <picture>
     support, a crawler, and every test that looks for the file name see what
     they always saw. --}}
@php
    $webp = ($thumb ?? false) ? $image->thumbWebpUrl() : $image->webpUrl();
    $jpeg = ($thumb ?? false) ? $image->thumbUrl() : $image->url();
@endphp

@if ($webp)
    <picture class="contents">
        <source srcset="{{ $webp }}" type="image/webp">
        <img src="{{ $jpeg }}" alt="{{ $alt ?? '' }}" @if (($lazy ?? true) !== false) loading="lazy" @endif
             decoding="async" class="{{ $class ?? '' }}">
    </picture>
@else
    <img src="{{ $jpeg }}" alt="{{ $alt ?? '' }}" @if (($lazy ?? true) !== false) loading="lazy" @endif
         decoding="async" class="{{ $class ?? '' }}">
@endif
