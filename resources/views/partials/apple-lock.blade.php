{{-- The Apple ID question, answered on the page rather than buried in the
     spec table.

     A locked device is invisible until the buyer gets home and wipes it, and
     „Не мога да изляза" sitting as row eleven of a spec list is the same as not
     saying it. See App\Support\AppleLock.

     Expects: $listing. --}}

@if ($notice = \App\Support\AppleLock::notice($listing))
    <div @class([
        'rounded-lg border p-4',
        'border-warn/40 bg-warn-soft' => $notice['level'] === 'critical',
        'border-line bg-surface-alt'  => $notice['level'] !== 'critical',
        $class ?? '',
    ])>
        <p @class([
            'text-sm font-semibold',
            'text-warn' => $notice['level'] === 'critical',
        ])>
            {{ $notice['title'] }}
        </p>

        <p @class([
            'mt-2 text-sm leading-relaxed',
            'text-warn'      => $notice['level'] === 'critical',
            'text-ink-muted' => $notice['level'] !== 'critical',
        ])>
            {{ $notice['body'] }}
        </p>
    </div>
@endif
