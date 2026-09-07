<div>

@if (! $listing->offers_enabled)
    <p class="mt-1 text-sm text-ink-muted">Фиксирана цена, без оферти</p>

@elseif ($mine && $mine->status === \App\Enums\OfferStatus::Accepted)
    <div class="mt-3 rounded-md border border-line bg-good-soft p-3">
        <p class="text-sm font-medium text-good">Офертата ти е приета</p>
        <p class="mt-1 font-mono text-lg font-semibold text-good">{{ $mine->formattedAmount() }}</p>
        <p class="mt-1 text-xs leading-relaxed text-good">
            Продавачът те очаква. Уговорете доставката помежду си.
        </p>
    </div>

@elseif ($mine && $mine->is_counter)
    {{-- The seller's counter. It carries the buyer's own buyer_id, which is why
         this branch has to come first - otherwise it renders as "your offer"
         with a withdraw button, and the buyer has no way to accept it. --}}
    <div class="mt-3 rounded-md border border-accent bg-accent-soft p-3">
        <p class="label text-accent">Насрещна оферта от продавача</p>
        <p class="mt-1 font-mono text-lg font-semibold tabular text-accent">
            {{ $mine->formattedAmount() }}
        </p>
        <p class="mt-1 text-xs text-accent">
            Изтича {{ $mine->expires_at->diffForHumans() }}
        </p>
        @if ($mine->note)
            <p class="mt-2 border-t border-line pt-2 text-xs italic text-accent">„{{ $mine->note }}"</p>
        @endif

        @error('amount') <p class="error">{{ $message }}</p> @enderror

        <div class="mt-3 flex gap-2">
            <button type="button" wire:click="acceptCounter" class="btn-primary btn-sm flex-1">
                Приеми
            </button>
            <button type="button" wire:click="declineCounter" class="btn-ghost btn-sm">
                Откажи
            </button>
        </div>
    </div>

@elseif ($mine)
    <div class="mt-3 rounded-md border border-line bg-surface-alt p-3">
        <p class="label">Твоята оферта</p>
        <p class="mt-1 font-mono text-lg font-semibold tabular">{{ $mine->formattedAmount() }}</p>
        <p class="mt-1 text-xs text-ink-muted">
            Чака отговор · изтича {{ $mine->expires_at->diffForHumans() }}
        </p>
        @if ($mine->note)
            <p class="mt-2 border-t border-line pt-2 text-xs italic text-ink-muted">„{{ $mine->note }}"</p>
        @endif

        @error('amount') <p class="error">{{ $message }}</p> @enderror

        <button type="button" wire:click="withdraw"
                wire:confirm="Да оттеглим ли офертата?"
                class="btn-ghost btn-sm mt-3 w-full">
            Оттегли офертата
        </button>
    </div>

@elseif (! auth()->check())
    <p class="mt-1 text-sm text-ink-muted">Продавачът приема оферти</p>
    <a href="{{ route('login') }}" wire:navigate class="btn-primary mt-4 block w-full text-center">
        Влез, за да направиш оферта
    </a>

@elseif (! $this->canOffer())
    <p class="mt-1 text-sm text-ink-muted">
        @if (auth()->id() === $listing->user_id)
            Това е твоята обява.
        @else
            Тази обява не приема оферти в момента.
        @endif
    </p>

@elseif (! $open)
    <p class="mt-1 text-sm text-ink-muted">Продавачът приема оферти</p>
    <button type="button" wire:click="$set('open', true)" class="btn-primary mt-4 w-full">
        Направи оферта
    </button>
    <p class="mt-2 text-center text-xs text-ink-faint">
        Офертата не е обвързваща и не сключва договор.
    </p>

@else
    <form wire:submit="submit" class="mt-4 space-y-3">
        <div>
            <label class="label" for="offer-amount">Твоята цена (€)</label>
            <input id="offer-amount" type="text" inputmode="decimal" wire:model="amount"
                   placeholder="{{ number_format($listing->priceEur() * 0.9, 0, ',', '') }}"
                   class="mt-1 font-mono" autofocus>
            @error('amount') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="offer-note">Съобщение <span class="normal-case text-ink-faint">(по избор)</span></label>
            <textarea id="offer-note" wire:model="note" rows="2"
                      maxlength="{{ config('remarket.offers.note_max_length', 200) }}"
                      placeholder="Мога да взема лично в петък."
                      class="mt-1 min-h-20"></textarea>
            @error('note') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-2">
            <x-turnstile />
            @error('turnstile') <p class="error">{{ $message }}</p> @enderror

            <button type="submit" class="btn-primary flex-1">
                <span wire:loading.remove wire:target="submit">Изпрати</span>
                <span wire:loading wire:target="submit">Изпращам…</span>
            </button>
            <button type="button" wire:click="$set('open', false)" class="btn-ghost">Откажи</button>
        </div>

        <p class="text-xs leading-relaxed text-ink-faint">
            Една оферта наведнъж, до {{ config('remarket.offers.max_per_listing', 3) }} за обява.
            Офертата не е обвързваща и не сключва договор.
        </p>
    </form>
@endif

</div>
