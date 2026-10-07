<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{--
    Footer.

    REPLACING „© 2026 Laravel. All rights reserved.", which was going out on
    every message the site sent — including the Bulgarian ones.

    The trader identification is not decoration: ЗЗП / Omnibus Art. 6a and the
    e-commerce rules want the operator identifiable in commercial
    correspondence, and „somewhere on the website" is a weaker answer than „at
    the bottom of the email they are holding".

    The last line is the one that keeps mail out of spam folders: a recipient
    who cannot find how to stop a message marks it as junk, and enough of those
    cost the sending domain the reputation the next thousand messages depend on.
--}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }} · {{ config('legal.entity.name') }}, ЕИК {{ config('legal.entity.eik') }}

[Условия]({{ route('legal.terms') }}) · [Поверителност]({{ route('legal.privacy') }}) · [Контакти]({{ route('legal.contacts') }})

Получаваш това писмо, защото имаш профил в {{ config('app.name') }}. Кои известия стигат до теб се избира в [настройките на профила]({{ route('profile.edit') }}).

You are receiving this because you have a {{ config('app.name') }} account. Choose which notifications reach you in your [profile settings]({{ route('profile.edit') }}).
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
