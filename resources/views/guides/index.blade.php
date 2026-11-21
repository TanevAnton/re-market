{{-- The guides' front door. Plain links with a sentence each: this page exists
     to be crawled and to be found by a person who does not know which guide
     they need. --}}
@php($guides = \App\Support\Guides::all())

<x-layouts.app
    title="Ръководства за купувача"
    description="Как да проверим употребявана видеокарта, как да разпознаем майнинг карта, какво да тестваме при получаване и как работи наложеният платеж с Еконт и Спиди."
    :canonical="route('guides')">

    <div class="legal mx-auto max-w-3xl">
        <h1>Ръководства за купувача</h1>
        <p class="lead">
            Четирите въпроса, които купувачите на употребена техника задават най-често, преди да платят.
            Ръководствата намаляват риска, но не го премахват — затова казват и кога да откажеш.
        </p>

        <ul class="!mt-8 !space-y-0 divide-y divide-line">
            @foreach ($guides as $slug => $g)
                <li class="!pl-0 py-5 before:hidden">
                    <a href="{{ route('guide', $slug) }}" wire:navigate
                       class="text-base font-semibold !no-underline hover:!underline">{{ $g['title'] }}</a>
                    <p class="!mt-1">{{ $g['short'] }}</p>
                    <p class="hint !mt-1">{{ $g['minutes'] }} мин четене</p>
                </li>
            @endforeach
        </ul>

        <div class="callout">
            Нещо липсва? Напиши ни от страницата
            <a href="{{ route('support') }}" wire:navigate>Поддръжка</a> — ръководствата растат от
            въпросите на купувачите.
        </div>
    </div>
</x-layouts.app>
