@props(['title', 'lead' => null])

<x-layouts.app :title="$title">
    <article class="legal mx-auto max-w-3xl">
        <h1>{{ $title }}</h1>

        @if ($lead)
            <p class="lead">{{ $lead }}</p>
        @endif

        {{ $slot }}

        <p class="mt-10 border-t border-line pt-4 text-xs text-ink-faint">
            Последна редакция: {{ config('legal.updated_at') }} ·
            <a href="{{ route('legal.contacts') }}" wire:navigate class="link">Контакти</a> ·
            <a href="{{ route('legal.terms') }}" wire:navigate class="link">Общи условия</a> ·
            <a href="{{ route('legal.privacy') }}" wire:navigate class="link">Поверителност</a> ·
            <a href="{{ route('legal.cookies') }}" wire:navigate class="link">Бисквитки</a> ·
            <a href="{{ route('legal.notice') }}" wire:navigate class="link">Сигнали</a>
        </p>
    </article>
</x-layouts.app>
