<?php

namespace App\Livewire\Privacy;

use App\Jobs\BuildDataExport;
use App\Models\DataExport;
use App\Services\Privacy\AccountDeletion;
use App\Services\Privacy\PersonalDataExport;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * „Моите данни" — one page for the two rights people actually exercise.
 *
 * A SEPARATE SCREEN RATHER THAN A CARD IN SETTINGS, for two reasons. The privacy
 * policy has to point somewhere, and „Settings, scroll to the bottom" is not a
 * place. And the irreversible button does not belong on the same form as
 * „change my city" — a destructive action sitting among routine ones is how
 * somebody clicks it while meaning something else.
 */
class MyData extends Component
{
    /** Typing the username is the confirmation. A checkbox is not a decision. */
    public string $confirmUsername = '';

    public bool $confirming = false;

    public function requestExport(PersonalDataExport $exports): void
    {
        $export = $exports->request(auth()->user());

        if ($export->wasRecentlyCreated) {
            BuildDataExport::dispatch($export->id);
        }

        session()->flash('status', 'Подготвяме архива. Ще те уведомим, когато е готов.');
    }

    /**
     * The download, streamed from a PRIVATE disk.
     *
     * Scoped to the signed-in user's own export by the query, not by trusting
     * the uuid in the request — the uuid is unguessable, which is a reason not
     * to panic rather than a reason to skip the check.
     */
    public function download(string $uuid, PersonalDataExport $exports): StreamedResponse
    {
        $export = DataExport::where('uuid', $uuid)
            ->where('user_id', auth()->id())
            ->first();

        abort_unless($export?->isReady(), 404);

        $disk = $exports->disk();

        abort_unless(Storage::disk($disk)->exists($export->path), 404);

        $export->forceFill(['downloaded_at' => now()])->save();

        return Storage::disk($disk)->download(
            $export->path,
            'rigo-danni-'.now()->format('Y-m-d').'.zip',
        );
    }

    public function startDelete(): void
    {
        $this->confirming     = true;
        $this->confirmUsername = '';
        $this->resetErrorBag();
    }

    public function cancelDelete(): void
    {
        $this->confirming = false;
        $this->resetErrorBag();
    }

    public function confirmDelete(AccountDeletion $deletion): void
    {
        $user = auth()->user();

        if (trim($this->confirmUsername) !== $user->username) {
            $this->addError('confirmUsername', 'Напиши точно потребителското си име, за да потвърдиш.');

            return;
        }

        try {
            $deletion->request($user);
        } catch (RuntimeException $e) {
            $this->addError('confirmUsername', $e->getMessage());

            return;
        }

        $this->confirming = false;

        session()->flash('status',
            'Профилът ти е скрит и обявите ти са свалени. Данните се изтриват след '
            .$deletion->graceDays().' дни. Ако влезеш пак дотогава, можеш да го спреш.');
    }

    public function undoDelete(AccountDeletion $deletion): void
    {
        try {
            $deletion->cancel(auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('confirmUsername', $e->getMessage());

            return;
        }

        session()->flash('status', 'Изтриването е спряно. Обявите ти остават свалени — публикувай ги отново, когато решиш.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $exports = app(PersonalDataExport::class);
        $deletion = app(AccountDeletion::class);

        return view('livewire.privacy.my-data', [
            'export'    => $exports->existing(auth()->user())
                ?? DataExport::where('user_id', auth()->id())->latest('id')->first(),
            'blockers'  => $deletion->blockers(auth()->user()),
            'graceDays' => $deletion->graceDays(),
            'hours'     => $exports->lifetimeHours(),
        ]);
    }
}
