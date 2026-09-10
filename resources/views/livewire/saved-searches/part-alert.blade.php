<div class="card-pad">
    @if ($searchId)
        <div class="flex items-start gap-2">
            <span class="mt-0.5 text-accent">✓</span>
            <div class="min-w-0">
                <p class="text-sm font-medium">Ще те известим</p>
                <p class="hint mt-1">
                    При нова обява за {{ $part->model }} получаваш съобщение —
                    в Telegram, ако си потвърдил телефона си, иначе на имейл.
                </p>
            </div>
        </div>

        <button type="button" wire:click="toggle" class="btn-ghost mt-3 w-full text-sm">
            Спри известията
        </button>
    @else
        <h2 class="label">Няма подходяща обява?</h2>
        <p class="hint mt-2">
            Запази търсене за {{ $part->model }} и ще ти пишем в момента,
            в който някой публикува такъв.
        </p>

        <button type="button" wire:click="toggle" class="btn-primary mt-3 w-full">
            Извести ме при нова обява
        </button>

        @if ($error)
            <p class="error mt-2">{{ $error }}</p>
        @endif
    @endif
</div>
