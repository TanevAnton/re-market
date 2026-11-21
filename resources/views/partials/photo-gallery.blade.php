{{-- Listing photo gallery: swipe on the page, swipe and pinch-zoom in the viewer.

     $images   the listing's photos (ListingImage collection, in order)
     $title    alt text

     Alpine rather than Livewire: switching photo is a client-side concern, and a
     server round trip per swipe makes a gallery feel broken on a phone. wire:ignore
     so a Livewire re-render (the admin takedown form, for one) cannot reach in and
     reset which photo is open.

     The behaviour is the script at the bottom, written once on the window.
     Pointer events rather than a touch library: one code path for finger,
     pen and mouse, and nothing to download. --}}
@php
    $photos    = $images->map(fn ($img) => ['jpeg' => $img->url(), 'webp' => $img->webpUrl()])->values();
    $firstWebp = $images->first()?->webpUrl();
@endphp

<script>
/*
 * Listing photo gallery: swipe on the page, swipe and pinch-zoom in the viewer.
 * Called from the gallery Alpine data attribute with photos = [{ jpeg, webp|null }, ...].
 * Plain pointer events, no library: one code path for touch, pen and mouse.
 */
window.photoGallery = window.photoGallery || function (photos) {
    var SWIPE_PX = 48, TAP_PX = 10, MAX_ZOOM = 4, DOUBLE_ZOOM = 2.5, DOUBLE_MS = 300;

    return {
        photos: photos,
        i: 0,
        open: false,
        scale: 1,
        tx: 0,
        ty: 0,
        dx: 0,            // live horizontal drag offset of the page image
        dragging: false,
        pointers: {},     // id -> {x, y}
        start: null,      // gesture bookkeeping
        lastTap: null,

        init() {
            // Leaving the page with the viewer open (browser back, a link) must
            // not leave the next page unable to scroll.
            this._release = function () { document.body.style.overflow = ''; };
            document.addEventListener('livewire:navigating', this._release);
        },
        destroy() {
            document.removeEventListener('livewire:navigating', this._release);
            document.body.style.overflow = '';
        },

        get count() { return this.photos.length; },
        get cur() { return this.photos[this.i] || { jpeg: '', webp: null }; },
        get zoomed() { return this.scale > 1.01; },

        go(n) {
            if (this.count < 2) { return; }
            this.i = (n + this.count) % this.count;
            this.resetZoom();
            this.preload();
        },
        next() { this.go(this.i + 1); },
        prev() { this.go(this.i - 1); },

        preload() {
            var self = this;
            [1, -1].forEach(function (d) {
                var p = self.photos[(self.i + d + self.count) % self.count];
                if (p) { var im = new Image(); im.src = p.webp || p.jpeg; }
            });
        },

        show(n) { this.i = n; this.resetZoom(); this.preload(); },

        openViewer() {
            this.open = true;
            this.resetZoom();
            this.preload();
            document.body.style.overflow = 'hidden';
        },
        closeViewer() {
            this.open = false;
            this.resetZoom();
            document.body.style.overflow = '';
        },

        resetZoom() { this.scale = 1; this.tx = 0; this.ty = 0; },

        // ---- the geometry ------------------------------------------------
        // The image's un-transformed box, from the element that carries the
        // transform. Pan is limited so an edge of the photograph can reach the
        // edge of the screen and no further.
        box() {
            var el = this.$refs.zoomImg;
            return el ? { w: el.offsetWidth, h: el.offsetHeight } : { w: 0, h: 0 };
        },
        clampPan() {
            var b = this.box();
            var mx = Math.max(0, (b.w * this.scale - window.innerWidth) / 2);
            var my = Math.max(0, (b.h * this.scale - window.innerHeight) / 2);
            this.tx = Math.min(mx, Math.max(-mx, this.tx));
            this.ty = Math.min(my, Math.max(-my, this.ty));
        },
        // Zoom to `s` keeping the screen point (fx, fy) where it is.
        zoomAt(s, fx, fy) {
            s = Math.min(MAX_ZOOM, Math.max(1, s));
            var cx = fx - window.innerWidth / 2;
            var cy = fy - window.innerHeight / 2;
            var k = s / this.scale;
            this.tx = cx - k * (cx - this.tx);
            this.ty = cy - k * (cy - this.ty);
            this.scale = s;
            if (s <= 1.01) { this.resetZoom(); } else { this.clampPan(); }
        },
        toggleZoom(x, y) {
            this.zoomed ? this.resetZoom() : this.zoomAt(DOUBLE_ZOOM, x, y);
        },
        step(f) {
            this.zoomAt(this.scale * f, window.innerWidth / 2, window.innerHeight / 2);
        },
        wheel(e) {
            if (!this.open) { return; }
            this.zoomAt(this.scale * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX, e.clientY);
        },

        // ---- the page image: horizontal swipe, tap to open ---------------
        pageDown(e) {
            this.dragging = true;
            this.dx = 0;
            this.start = { x: e.clientX, y: e.clientY, t: Date.now(), moved: false };
            if (e.target.setPointerCapture) { try { e.target.setPointerCapture(e.pointerId); } catch (_) {} }
        },
        pageMove(e) {
            if (!this.dragging || !this.start) { return; }
            var dx = e.clientX - this.start.x, dy = e.clientY - this.start.y;
            if (Math.abs(dx) > TAP_PX || Math.abs(dy) > TAP_PX) { this.start.moved = true; }
            // Follow the finger only for a horizontal gesture: the vertical
            // ones belong to the page scrolling (touch-action: pan-y).
            if (Math.abs(dx) > Math.abs(dy)) { this.dx = dx; }
        },
        pageUp(e) {
            if (!this.dragging || !this.start) { return; }
            var dx = e.clientX - this.start.x;
            var moved = this.start.moved;
            this.dragging = false;
            this.dx = 0;
            this.start = null;
            if (!moved) { this.openViewer(); return; }
            if (dx <= -SWIPE_PX) { this.next(); } else if (dx >= SWIPE_PX) { this.prev(); }
        },
        pageCancel() { this.dragging = false; this.dx = 0; this.start = null; },

        // ---- the viewer: swipe, drag-to-pan, pinch, double-tap -----------
        viewDown(e) {
            // Buttons are not part of the gesture: two quick taps on "next"
            // must not read as a double tap on the photograph.
            if (e.target.closest && e.target.closest('button')) { return; }
            this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            if (e.target.setPointerCapture) { try { e.target.setPointerCapture(e.pointerId); } catch (_) {} }
            var ids = Object.keys(this.pointers);
            if (ids.length === 2) {
                var a = this.pointers[ids[0]], b = this.pointers[ids[1]];
                this.start = {
                    pinch: true,
                    dist: Math.hypot(a.x - b.x, a.y - b.y) || 1,
                    scale: this.scale,
                };
            } else {
                this.start = {
                    x: e.clientX, y: e.clientY, tx: this.tx, ty: this.ty,
                    t: Date.now(), moved: false,
                };
            }
        },
        viewMove(e) {
            if (!this.pointers[e.pointerId]) { return; }
            this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            var ids = Object.keys(this.pointers);

            if (ids.length >= 2 && this.start && this.start.pinch) {
                var a = this.pointers[ids[0]], b = this.pointers[ids[1]];
                var d = Math.hypot(a.x - b.x, a.y - b.y) || 1;
                this.zoomAt(this.start.scale * d / this.start.dist, (a.x + b.x) / 2, (a.y + b.y) / 2);
                return;
            }
            if (!this.start || this.start.pinch) { return; }

            var dx = e.clientX - this.start.x, dy = e.clientY - this.start.y;
            if (Math.abs(dx) > TAP_PX || Math.abs(dy) > TAP_PX) { this.start.moved = true; }

            if (this.zoomed) {
                this.tx = this.start.tx + dx;
                this.ty = this.start.ty + dy;
                this.clampPan();
            } else if (Math.abs(dx) > Math.abs(dy)) {
                this.dx = dx;
            } else {
                this.ty = dy;       // pull down to dismiss
            }
        },
        viewUp(e) {
            var was = this.pointers[e.pointerId];
            delete this.pointers[e.pointerId];
            if (!was) { return; }

            if (this.start && this.start.pinch) {
                // A pinch that ends near 1x is a reset, not a 1.04x photograph.
                if (this.scale < 1.1) { this.resetZoom(); }
                if (Object.keys(this.pointers).length === 0) { this.start = null; }
                return;
            }
            if (!this.start) { return; }

            var dx = e.clientX - this.start.x, dy = e.clientY - this.start.y;
            var moved = this.start.moved;
            var wasZoomed = this.zoomed;
            this.start = null;

            if (!moved) {
                // A tap. Two close together are a double tap.
                var now = Date.now();
                if (this.lastTap && now - this.lastTap.t < DOUBLE_MS
                    && Math.hypot(e.clientX - this.lastTap.x, e.clientY - this.lastTap.y) < 40) {
                    this.lastTap = null;
                    this.toggleZoom(e.clientX, e.clientY);
                } else {
                    this.lastTap = { t: now, x: e.clientX, y: e.clientY };
                }
                return;
            }

            if (!wasZoomed) {
                if (dy > 110 && Math.abs(dy) > Math.abs(dx)) { this.dx = 0; this.closeViewer(); return; }
                this.dx = 0;
                this.ty = 0;
                if (dx <= -SWIPE_PX) { this.next(); } else if (dx >= SWIPE_PX) { this.prev(); }
            }
        },
        viewCancel(e) {
            delete this.pointers[e.pointerId];
            this.start = null;
            this.dx = 0;
            if (!this.zoomed) { this.ty = 0; }
        },

        key(e) {
            if (!this.open) { return; }
            if (e.key === 'Escape') { this.closeViewer(); }
            else if (e.key === 'ArrowRight' && !this.zoomed) { this.next(); }
            else if (e.key === 'ArrowLeft' && !this.zoomed) { this.prev(); }
            else if (e.key === '+' || e.key === '=') { this.step(1.4); }
            else if (e.key === '-') { this.step(1 / 1.4); }
        },
    };
};

</script>

<div class="mt-5 space-y-2" wire:ignore
     x-data="photoGallery({{ Illuminate\Support\Js::from($photos) }})"
     @keydown.window="key($event)">

    <div class="card overflow-hidden">
        @if ($images->isNotEmpty())
            {{-- touch-action: pan-y - a vertical drag still scrolls the page, a
                 horizontal one is ours. --}}
            <div class="relative aspect-[4/3] w-full cursor-zoom-in touch-pan-y select-none overflow-hidden"
                 data-gallery-stage
                 @pointerdown="pageDown($event)" @pointermove="pageMove($event)"
                 @pointerup="pageUp($event)" @pointercancel="pageCancel()">

                <picture class="contents">
                    <source type="image/webp" @if ($firstWebp) srcset="{{ $firstWebp }}" @endif :srcset="cur.webp">
                    <img src="{{ $images->first()->url() }}" :src="cur.jpeg" alt="{{ $title }}" draggable="false"
                         :style="dx ? 'transform: translateX(' + dx + 'px)' : ''"
                         :class="dragging ? '' : 'transition-transform duration-200'"
                         class="pointer-events-none aspect-[4/3] w-full object-cover">
                </picture>

                @if ($images->count() > 1)
                    <span class="pointer-events-none absolute bottom-2 right-2 rounded-md bg-black/55 px-2 py-0.5
                                 font-mono text-[11px] text-white backdrop-blur">
                        <span x-text="i + 1">1</span> / {{ $images->count() }}
                    </span>
                @endif
            </div>
        @else
            <div class="grid aspect-[4/3] place-items-center bg-surface-alt text-sm text-ink-faint">
                Няма снимки
            </div>
        @endif
    </div>

    @if ($images->count() > 1)
        <div class="grid grid-cols-5 gap-2 sm:grid-cols-6">
            @foreach ($images as $index => $img)
                <button type="button" @click="show({{ $index }})"
                        :class="i === {{ $index }} ? 'border-accent' : 'border-line hover:border-ink-faint'"
                        class="relative overflow-hidden rounded-md border transition"
                        aria-label="Снимка {{ $index + 1 }}">
                    @include('partials.photo', [
                        'image' => $img,
                        'thumb' => true,
                        'alt'   => '',
                        'class' => 'aspect-[4/3] w-full object-cover',
                    ])
                    @if ($img->is_timestamp_photo)
                        <span class="absolute inset-x-0 bottom-0 bg-accent px-1 py-0.5 text-center
                                     text-[9px] font-medium text-[var(--accent-ink)]">
                            с бележка
                        </span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- The viewer. touch-none: every gesture inside it is ours, the browser
         must not scroll or zoom the page underneath. --}}
    @if ($images->isNotEmpty())
        <div x-show="open" x-cloak x-transition.opacity
             class="fixed inset-0 z-50 touch-none select-none overflow-hidden bg-black/95"
             role="dialog" aria-modal="true" aria-label="Снимки"
             @click.self="closeViewer()"
             @wheel.prevent="wheel($event)"
             @pointerdown="viewDown($event)" @pointermove="viewMove($event)"
             @pointerup="viewUp($event)" @pointercancel="viewCancel($event)">

            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <picture class="contents">
                    <source type="image/webp" :srcset="cur.webp">
                    <img x-ref="zoomImg" :src="cur.jpeg" alt="{{ $title }}" draggable="false"
                         :style="'transform: translate(' + (tx + dx) + 'px,' + ty + 'px) scale(' + scale + ')'"
                         :class="(start || dragging) ? '' : 'transition-transform duration-150'"
                         class="max-h-full max-w-full object-contain will-change-transform">
                </picture>
            </div>

            <button type="button" @click="closeViewer()" aria-label="Затвори"
                    class="absolute right-3 top-3 rounded-md px-3 py-1.5 text-2xl leading-none
                           text-white/80 transition hover:bg-white/10 hover:text-white">
                &times;
            </button>

            <div class="absolute left-3 top-3 flex gap-1">
                <button type="button" @click="step(1.4)" aria-label="Увеличи"
                        class="rounded-md px-3 py-1.5 text-xl leading-none text-white/80 transition
                               hover:bg-white/10 hover:text-white">+</button>
                <button type="button" @click="step(1 / 1.4)" aria-label="Намали"
                        class="rounded-md px-3 py-1.5 text-xl leading-none text-white/80 transition
                               hover:bg-white/10 hover:text-white">&minus;</button>
            </div>

            @if ($images->count() > 1)
                <button type="button" @click="prev()" aria-label="Предишна"
                        class="absolute left-2 top-1/2 hidden -translate-y-1/2 rounded-md px-3 py-6 text-3xl
                               text-white/80 transition hover:bg-white/10 hover:text-white sm:block">
                    &lsaquo;
                </button>
                <button type="button" @click="next()" aria-label="Следваща"
                        class="absolute right-2 top-1/2 hidden -translate-y-1/2 rounded-md px-3 py-6 text-3xl
                               text-white/80 transition hover:bg-white/10 hover:text-white sm:block">
                    &rsaquo;
                </button>
            @endif

            <p class="pointer-events-none absolute inset-x-0 bottom-4 text-center font-mono text-xs text-white/60">
                @if ($images->count() > 1)
                    <span x-text="i + 1">1</span> / {{ $images->count() }} ·
                @endif
                двойно докосване или щипване за увеличение
            </p>
        </div>
    @endif
</div>
