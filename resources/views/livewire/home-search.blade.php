{{-- The home page search, with catalogue suggestions underneath it.

     STILL A PLAIN GET FORM to the browse page with a `q` parameter - the same
     one the filters read from the URL. Everything else in this file is on top
     of that: take the script away and the field still searches.

     The slash key focuses the field from anywhere on the page, the way it does
     in most tools this audience uses. It is ignored while somebody is typing
     in another field.

     THE LIST IS DRIVEN FROM THE KEYBOARD AS WELL AS THE MOUSE. Arrows move a
     highlight, Enter follows it, Escape closes. With nothing highlighted Enter
     does what it always did and submits the search - so the fast path for
     somebody who just types and hits Enter has not changed at all.

     It closes on a click elsewhere and on Tab, and deliberately NOT on blur.
     Several browsers do not focus a link when it is pressed, so a list that
     hides when the field loses focus is gone between the press and the
     release, and the click lands on whatever was underneath it. --}}
<form method="GET" action="{{ route('browse') }}" class="relative w-full shrink-0 lg:w-[540px]"
      x-data="{
          open: false,
          active: -1,
          rows() { return Array.from(this.$root.querySelectorAll('[data-suggestion]')) },
          move(step) {
              const count = this.rows().length;
              if (! count) return;
              this.open = true;
              this.active = Math.max(-1, Math.min(count - 1, this.active + step));
          },
          follow(event) {
              const row = this.open ? this.rows()[this.active] : null;
              if (row) { event.preventDefault(); row.click() }
          },
      }"
      x-on:keydown.window="if ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && ! document.activeElement.isContentEditable) { $event.preventDefault(); $refs.q.focus() }"
      x-on:click.outside="open = false"
      x-on:keydown.escape="open = false; active = -1">

    <label class="group flex h-[50px] cursor-text items-center gap-3 rounded-xl border border-line
                  bg-surface/60 pr-2 pl-4 transition
                  hover:border-line-strong
                  focus-within:border-accent focus-within:bg-surface
                  focus-within:shadow-[0_0_0_3px_var(--accent-soft),0_0_28px_-8px_var(--glow)]">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" aria-hidden="true" class="h-4 w-4 shrink-0 text-ink-faint">
            <circle cx="11" cy="11" r="6.5"/>
            <path d="m16 16 4.5 4.5"/>
        </svg>

        {{-- No important modifiers. The base input style lives in the base
             layer and every utility here is in the utilities layer, which wins
             on layer order.

             Debounced, so the server is asked when the typing pauses rather
             than once per letter.

             The list opens on input only while there is something to search
             for. Chrome empties a search field on Escape and reports that as
             input - without the length check, the key that closes the list
             would reopen it for the moment before the server caught up. --}}
        <input x-ref="q" type="search" name="q" autocomplete="off"
               wire:model.live.debounce.250ms="q"
               x-on:focus="open = true"
               x-on:input="open = $event.target.value.trim().length > 1; active = -1"
               x-on:keydown.arrow-down.prevent="move(1)"
               x-on:keydown.arrow-up.prevent="move(-1)"
               x-on:keydown.enter="follow($event)"
               x-on:keydown.tab="open = false"
               placeholder="Търси модел — RTX 4070, 7800X3D…"
               aria-label="Търсене на компоненти"
               aria-autocomplete="list" aria-controls="home-suggestions"
               class="peer min-w-0 flex-1 border-0 bg-transparent p-0 text-[14.5px]
                      focus:border-0 focus:ring-0">

        <kbd class="hidden rounded-md border border-line-strong px-2 py-0.5 font-mono text-[11px] text-ink-faint
                    group-focus-within:hidden peer-[:not(:placeholder-shown)]:hidden sm:inline">/</kbd>

        {{-- Shown while the field is focused OR has text in it. The second
             half is not decoration: some browsers do not focus a button on
             click, so a button that only exists while the field is focused
             disappears between the press and the release, and the click lands
             on nothing. --}}
        <button type="submit"
                class="hidden h-8 shrink-0 rounded-lg bg-accent px-3.5 text-[12.5px] font-semibold
                       text-[var(--accent-ink)] transition hover:brightness-110
                       group-focus-within:block peer-[:not(:placeholder-shown)]:block">
            Търси
        </button>
    </label>

    {{-- ── Suggestions ───────────────────────────────────────────────────────
         Catalogue models, each a link to its own page - not to a search for
         its name. The page has the specs and the price band whether or not
         anybody is selling one today.

         The last row is the search itself, spelled out. It is what Enter does
         anyway; saying so means the list never looks like the only way on, and
         it is the whole list when the catalogue has no such model.

         Every row carries the same marker attribute, which is all the keyboard
         handling above looks for - so the order on screen is the order the
         arrows walk, with no index to keep in step by hand. --}}
    @if ($term !== '')
        <div id="home-suggestions" x-show="open" x-cloak
             class="absolute inset-x-0 top-[58px] z-30 overflow-hidden rounded-xl border border-line-strong bg-surface
                    shadow-[0_18px_50px_-14px_rgba(0,0,0,0.55)]">

            @foreach ($suggestions as $i => $part)
                <a href="{{ route('part', $part) }}" wire:navigate data-suggestion
                   wire:key="suggestion-{{ $part->id }}"
                   x-on:mouseenter="active = {{ $i }}"
                   x-bind:class="active === {{ $i }} ? 'bg-surface-alt' : ''"
                   class="flex items-center gap-3 px-4 py-2.5 transition-colors">
                    @include('partials.category-icon', [
                        'category' => $part->category,
                        'class'    => 'h-[18px] w-[18px] shrink-0 text-ink-faint',
                    ])

                    <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium">{{ $part->fullName() }}</span>

                    {{-- Only when there is something to buy. „0 обяви" beside
                         every model would turn a helpful list into a tally of
                         what the site does not have yet. --}}
                    @if ($part->live_count > 0)
                        <span class="shrink-0 font-mono text-[11px] tabular text-accent">
                            {{ $part->live_count }} {{ $part->live_count === 1 ? 'обява' : 'обяви' }}
                        </span>
                    @else
                        <span class="shrink-0 font-mono text-[11px] text-ink-faint">модел</span>
                    @endif
                </a>
            @endforeach

            <button type="submit" data-suggestion
                    x-on:mouseenter="active = {{ $suggestions->count() }}"
                    x-bind:class="active === {{ $suggestions->count() }} ? 'bg-surface-alt' : ''"
                    @class([
                        'flex w-full cursor-pointer items-center gap-3 px-4 py-2.5 text-left text-[12.5px] text-ink-muted transition-colors',
                        'border-t border-line' => $suggestions->isNotEmpty(),
                    ])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" aria-hidden="true" class="h-[18px] w-[18px] shrink-0 text-ink-faint">
                    <circle cx="11" cy="11" r="6.5"/>
                    <path d="m16 16 4.5 4.5"/>
                </svg>
                <span class="min-w-0 flex-1 truncate">
                    Търси <span class="text-ink">„{{ $term }}“</span> във всички обяви
                </span>
                <span class="hidden shrink-0 font-mono text-[11px] text-ink-faint sm:inline">Enter</span>
            </button>
        </div>
    @endif

    {{-- ── Shortcuts ─────────────────────────────────────────────────────────
         Straight into the series menu on the graphics-card page. These used to
         be four searches for a model name, which is the one kind of link that
         leads to an empty page the week nobody is selling that model. A series
         page always has its menu, its models and somewhere to leave an alert. --}}
    @if ($quickLinks)
        <p class="mt-2.5 flex flex-wrap gap-x-3.5 gap-y-1 font-mono text-[11.5px] text-ink-faint">
            <span>{{ mb_strtolower($gpuLabel) }}:</span>
            @foreach ($quickLinks as $link)
                <a href="{{ $link['url'] }}" wire:navigate
                   class="text-ink-muted transition hover:text-accent">{{ $link['label'] }}</a>
            @endforeach
        </p>
    @endif
</form>
