@props(['value', 'env'])

{{--
    A company detail, or a placeholder nobody can fail to notice.

    These pages are legal statements about a real registered company. A missing
    ЕИК has to look missing - an invented one would be a false statement, and a
    quietly blank one would let the page go live looking finished.
--}}
@if (filled($value))
    {{ $value }}
@else
    <span class="rounded bg-bad-soft px-1 font-mono text-[11px] text-bad">попълни {{ $env }} в .env</span>
@endif
