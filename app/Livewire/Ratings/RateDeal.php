<?php

namespace App\Livewire\Ratings;

use App\Models\Deal;
use App\Models\Rating;
use App\Services\Ratings\RatingException;
use App\Services\Ratings\RatingService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The rating box, shown on a completed deal in /sdelki.
 */
class RateDeal extends Component
{
    #[Locked]
    public Deal $deal;

    public int $score = 0;

    public string $comment = '';

    public function mount(Deal $deal): void
    {
        $this->deal = $deal;
    }

    /** What this user already left, if anything. */
    public function mine(): ?Rating
    {
        return Rating::where('deal_id', $this->deal->id)
            ->where('rater_id', auth()->id())
            ->first();
    }

    public function submit(RatingService $ratings): void
    {
        $data = $this->validate([
            'score'   => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'score.required' => 'Избери оценка.',
            'score.between'  => 'Оценката е от 1 до 5.',
        ]);

        try {
            $ratings->rate($this->deal, auth()->user(), $data['score'], $data['comment'] ?: null);
        } catch (RatingException $e) {
            $this->addError('score', $e->getMessage());

            return;
        }

        $this->reset('score', 'comment');

        session()->flash('status', 'Благодарим за оценката.');
    }

    public function render()
    {
        return view('livewire.ratings.rate-deal', [
            'mine'  => $this->mine(),
            'other' => $this->deal->counterparty(auth()->user()),
        ]);
    }
}
