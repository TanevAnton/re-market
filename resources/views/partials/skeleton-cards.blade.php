{{-- Placeholder cards with the same proportions as the real listing card.

     $count  how many (default 6)

     Same photo ratio, same text rows, so the grid keeps its height while the
     real cards load. aria-hidden: a screen reader should hear the results, not
     six grey boxes. --}}
@for ($n = 0; $n < ($count ?? 6); $n++)
    <div class="card overflow-hidden" aria-hidden="true">
        <div class="skeleton aspect-[4/3] w-full rounded-none"></div>
        <div class="space-y-2.5 p-4">
            <div class="skeleton h-4 w-3/4"></div>
            <div class="skeleton h-3 w-1/2"></div>
            <div class="flex items-center justify-between pt-1">
                <div class="skeleton h-5 w-20"></div>
                <div class="skeleton h-3 w-16"></div>
            </div>
        </div>
    </div>
@endfor
