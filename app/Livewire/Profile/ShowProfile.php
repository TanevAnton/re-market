<?php

namespace App\Livewire\Profile;

use App\Models\Rating;
use App\Models\User;
use App\Services\Ratings\RatingException;
use App\Services\Ratings\RatingService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class ShowProfile extends Component
{
    use WithPagination;

    public User $user;

    public function mount(string $username): void
    {
        $this->user = User::whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->firstOrFail();

        abort_if($this->user->banned_at !== null && ! auth()->user()?->is_admin, 404);
    }

    /** Which rating this user is replying to, by id. */
    public ?int $replyingId = null;

    public string $replyBody = '';

    public function startReply(int $ratingId): void
    {
        $this->replyingId = $ratingId;
        $this->replyBody  = '';
    }

    /**
     * The rated person gets one public reply. Not a thread - a comment section
     * under a rating turns every disagreement into an argument the whole site
     * can read, and the second reply is never the last one.
     */
    public function submitReply(RatingService $ratings): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'min:2', 'max:1000'],
        ], [
            'replyBody.required' => 'Напиши отговор.',
        ]);

        $rating = Rating::whereKey($this->replyingId)
            ->where('ratee_id', auth()->id())
            ->first();

        if (! $rating) {
            abort(404);
        }

        try {
            $ratings->reply($rating, auth()->user(), $this->replyBody);
        } catch (RatingException $e) {
            $this->addError('replyBody', $e->getMessage());

            return;
        }

        $this->reset('replyingId', 'replyBody');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.profile.show-profile', [
            'listings' => $this->user->listings()
                ->visible()
                ->with(['part', 'city', 'images'])
                ->latest('bumped_at')
                ->paginate(12),

            // Hidden ratings are excluded everywhere, not just from the
            // average - moderating an unfair one has to actually remove it
            // from view or it is not moderation.
            'ratings' => Rating::where('ratee_id', $this->user->id)
                ->where('is_hidden', false)
                ->with('rater:id,username')
                ->latest('id')
                ->limit(20)
                ->get(),
        ])->layoutData([
            'title'       => 'Профил на '.$this->user->username,

            /*
             * The completion rate rather than the listing count, because that
             * is the figure a cautious buyer is actually looking for and the
             * only one a spammer cannot inflate.
             */
            'description' => sprintf(
                'Обяви и оценки на %s в RE-MARKET — %d завършени сделки.',
                $this->user->username,
                $this->user->deals_completed,
            ),
            'canonical'   => route('profile', $this->user->username),

            /*
             * Profiles carry a person's trading history and are not the pages
             * we want ranking for hardware searches. They stay reachable and
             * shareable; they just do not go in the index, and nothing here
             * belongs in a search result someone else typed.
             */
            'noindex'     => true,
        ]);
    }
}
