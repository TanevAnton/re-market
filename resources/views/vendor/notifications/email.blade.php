{{--
    The body every notification email is rendered into.

    THE CHROME WAS ENGLISH — „Hello!", „Regards,", and the „If you're having
    trouble clicking" paragraph — on a site whose every message is written in
    Bulgarian. Those three strings come from the framework's own translation
    keys, and the usual fix is to set APP_LOCALE=bg and ship a lang file. That
    was rejected deliberately: the locale also drives Carbon's
    translatedFormat(), which prints month names in several screens, and
    changing those as a side effect of fixing an email is a bigger blast radius
    than the bug. Editing the published view changes exactly what it says it
    changes.

    THE SUBCOPY IS BILINGUAL, the rest is not. That paragraph is read by
    somebody whose button did not work — a person already in trouble, possibly
    on a client that mangles the layout — and it is the only instruction in the
    message that has to be followed rather than merely understood. The greeting
    and the sign-off carry no instructions, so Bulgarian alone costs nothing.
--}}
<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# Внимание
@else
# Здравей!
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Поздрави,<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Ако бутонът „{{ $actionText }}" не работи, копирай адреса по-долу и го отвори в браузъра си.

If the "{{ $actionText }}" button does not work, copy the address below and open it in your browser.

<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
