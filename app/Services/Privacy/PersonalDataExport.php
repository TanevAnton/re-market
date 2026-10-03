<?php

namespace App\Services\Privacy;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything the site holds about one person, in a file they can open.
 *
 * ART. 15 AND ART. 20 ARE DIFFERENT REQUESTS and this answers both: `data.json`
 * is the machine-readable copy portability asks for, and the photographs are in
 * there because they are the person's own work rather than ours.
 *
 * WHAT IT DELIBERATELY DOES NOT INCLUDE:
 *
 *   - The other party's half of anything. A deal names the counterparty's
 *     username because the deal is theirs too, but it does not carry that
 *     person's address, phone or email. An export is a copy of YOUR data, and a
 *     subject access request is not a way to pull somebody else's details out of
 *     a platform.
 *   - `phone_hash`. It is an HMAC keyed with a server secret; handing it over
 *     tells the person nothing about themselves and hands an attacker a known
 *     plaintext/hash pair for the pepper.
 *   - Serial-number hashes, for the same reason.
 *
 * It is built by a QUEUED JOB, never in a web request: a seller with two hundred
 * listings is a zip of several hundred photographs, and a PHP-FPM worker holding
 * that open is a timeout at best.
 */
class PersonalDataExport
{
    /** How long a finished archive stays downloadable. */
    public function lifetimeHours(): int
    {
        return max(1, (int) config('remarket.privacy.export_hours', 72));
    }

    /** One pending or ready export at a time — the rest is a disk-filling game. */
    public function existing(User $user): ?DataExport
    {
        return DataExport::where('user_id', $user->id)
            ->whereIn('status', [DataExport::PENDING, DataExport::READY])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function request(User $user): DataExport
    {
        if ($open = $this->existing($user)) {
            return $open;
        }

        /*
         * forceCreate, because DataExport is `$guarded = ['*']` — totally
         * guarded, so create() throws rather than silently dropping. That is the
         * property worth keeping: a `status` or a `path` arriving from request
         * data is how one person reads another's archive. The service owns the
         * table and says so explicitly.
         */
        return DataExport::forceCreate([
            'user_id'    => $user->id,
            'status'     => DataExport::PENDING,
            'expires_at' => now()->addHours($this->lifetimeHours()),
        ]);
    }

    /**
     * Build the archive. Called from the job, not from a controller.
     */
    public function build(DataExport $export): DataExport
    {
        $user = $export->user;

        if (! $user) {
            throw new RuntimeException('The account this export belongs to is gone.');
        }

        $disk = $this->disk();
        $path = 'exports/'.$export->uuid.'.zip';

        // ZipArchive needs a real filesystem path, so the archive is assembled
        // in the system temp directory and moved onto the disk afterwards. That
        // also means a half-written zip never appears at the final path.
        $tmp = tempnam(sys_get_temp_dir(), 'rigo-export-').'.zip';

        $zip = new ZipArchive();

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the archive for writing.');
        }

        $zip->addFromString(
            'data.json',
            json_encode($this->collect($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $zip->addFromString('ПРОЧЕТИ.txt', $this->readme($user));

        $this->addPhotos($zip, $user);

        $zip->close();

        Storage::disk($disk)->put($path, file_get_contents($tmp));
        @unlink($tmp);

        $export->forceFill([
            'status'       => DataExport::READY,
            'path'         => $path,
            'bytes'        => Storage::disk($disk)->size($path),
            'completed_at' => now(),
            // The clock starts when it is READY, not when it was asked for: an
            // export that spent two days in a stuck queue must not arrive
            // already expired.
            'expires_at'   => now()->addHours($this->lifetimeHours()),
        ])->save();

        return $export;
    }

    /**
     * PRIVATE disk, asserted rather than assumed.
     *
     * `public` is served straight off the filesystem by nginx. An archive of
     * somebody's whole account at a guessable URL is the worst single file this
     * application could produce, so the disk is named in config and this refuses
     * to run if it has been pointed at a public one.
     */
    public function disk(): string
    {
        $disk = (string) config('remarket.privacy.export_disk', 'local');

        if (config("filesystems.disks.{$disk}.visibility") === 'public'
            || ! empty(config("filesystems.disks.{$disk}.url"))) {
            throw new RuntimeException(
                "Export disk [{$disk}] is publicly served. An export is somebody's entire "
                .'account in one file and must never sit behind a guessable URL.'
            );
        }

        return $disk;
    }

    /** @return array<string, mixed> */
    private function collect(User $user): array
    {
        return [
            'за_профила' => [
                'потребителско_име'   => $user->username,
                'име'                 => $user->name,
                'имейл'               => $user->email,
                'град'                => $user->city?->name(),
                'език'                => $user->locale,
                'телефон_последни_4'  => $user->phone_last4,
                'телефон_потвърден'   => $this->when($user->phone_verified_at),
                'продава_като'        => $user->seller_type->label(),
                'данни_за_фирма'      => $user->trader_details,
                'статус_на_проверка'  => $user->traderStatusLabel(),
                'регистриран_на'      => $this->when($user->created_at),
                'завършени_сделки'    => $user->deals_completed,
                'изоставени_сделки'   => $user->deals_abandoned,
                'оценка'              => $user->rating_avg,
            ],

            'обяви' => DB::table('listings')->where('user_id', $user->id)->get()
                ->map(fn ($l) => [
                    'заглавие'    => $l->title,
                    'категория'   => $l->category,
                    'цена_в_евро' => $l->price_cents / 100,
                    'състояние'   => $l->condition,
                    'описание'    => $l->description,
                    'статус'      => $l->status,
                    'публикувана' => $l->published_at,
                    'прегледи'    => $l->view_count,
                ])->all(),

            'оферти' => DB::table('offers')
                ->where('buyer_id', $user->id)->orWhere('seller_id', $user->id)->get()
                ->map(fn ($o) => [
                    'роля'        => $o->buyer_id === $user->id ? 'купувач' : 'продавач',
                    'сума_в_евро' => $o->amount_cents / 100,
                    'статус'      => $o->status,
                    'подадена'    => $o->created_at,
                ])->all(),

            /*
             * The counterparty is named by USERNAME only. The deal is theirs as
             * much as it is this user's, so it appears — but a subject access
             * request must not become a way to extract another person's address
             * and phone number from the platform.
             */
            'сделки' => DB::table('deals')
                ->where('buyer_id', $user->id)->orWhere('seller_id', $user->id)->get()
                ->map(function ($d) use ($user) {
                    $otherId = $d->buyer_id === $user->id ? $d->seller_id : $d->buyer_id;

                    return [
                        'роля'            => $d->buyer_id === $user->id ? 'купувач' : 'продавач',
                        'отсрещна_страна' => DB::table('users')->where('id', $otherId)->value('username'),
                        'сума_в_евро'     => $d->agreed_price_cents ? $d->agreed_price_cents / 100 : null,
                        'куриер'          => $d->courier,
                        'товарителница'   => $d->tracking_number,
                        'статус'          => $d->status,
                        'създадена'       => $d->created_at,
                        'завършена'       => $d->completed_at,
                    ];
                })->all(),

            'съобщения' => DB::table('messages')->where('sender_id', $user->id)->get()
                ->map(fn ($m) => ['текст' => $m->body, 'изпратено' => $m->created_at])->all(),

            'оценки_които_си_дал' => $this->ratings($user, 'rater_id'),
            'оценки_за_теб'       => $this->ratings($user, 'ratee_id'),

            'запазени_обяви'   => DB::table('favorites')->where('user_id', $user->id)->count(),
            'запазени_търсения' => DB::table('saved_searches')->where('user_id', $user->id)->get()
                ->map(fn ($s) => ['име' => $s->name, 'критерии' => json_decode((string) $s->criteria, true)])
                ->all(),

            'кредит' => DB::table('credit_transactions')->where('user_id', $user->id)->get()
                ->map(fn ($t) => [
                    'сума_в_евро' => $t->amount_cents / 100,
                    'вид'         => $t->kind,
                    'бележка'     => $t->note,
                    'дата'        => $t->created_at,
                ])->all(),

            'фактури' => DB::table('invoices')->where('user_id', $user->id)->get()
                ->map(fn ($i) => [
                    'номер'       => $i->number,
                    'издадена_на' => $i->issued_on,
                    'сума_в_евро' => $i->total_cents / 100,
                    'ддс_в_евро'  => $i->vat_cents / 100,
                ])->all(),

            'запитвания_до_поддръжка' => DB::table('tickets')->where('user_id', $user->id)
                ->pluck('subject')->all(),

            'изнесено_на' => now()->toDateTimeString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ratings(User $user, string $column): array
    {
        return DB::table('ratings')->where($column, $user->id)->get()
            ->map(fn ($r) => [
                'оценка'   => $r->score,
                'коментар' => $r->comment,
                'дата'     => $r->created_at,
            ])->all();
    }

    private function when(mixed $value): ?string
    {
        return $value ? (string) $value : null;
    }

    /**
     * Their photographs, under the listing they belong to.
     *
     * Missing files are skipped rather than fatal: a photograph that vanished in
     * some earlier incident must not stop somebody exercising a legal right.
     */
    private function addPhotos(ZipArchive $zip, User $user): void
    {
        $rows = DB::table('listing_images')
            ->join('listings', 'listings.id', '=', 'listing_images.listing_id')
            ->where('listings.user_id', $user->id)
            ->select('listing_images.path', 'listings.id as listing_id', 'listings.title')
            ->get();

        $public = Storage::disk('public');

        foreach ($rows as $i => $row) {
            if (! $public->exists($row->path)) {
                continue;
            }

            $folder = 'снимки/'.$row->listing_id.'-'.$this->slug($row->title);

            $zip->addFromString(
                $folder.'/'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).'.jpg',
                (string) $public->get($row->path),
            );
        }
    }

    /** Zip entry names travel badly; Cyrillic titles become something openable. */
    private function slug(string $title): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($title));

        return trim(mb_substr((string) $slug, 0, 40), '-');
    }

    private function readme(User $user): string
    {
        return implode("\n", [
            'Твоите данни от RIGO',
            '',
            'Профил: '.$user->username,
            'Изнесено на: '.now()->format('d.m.Y H:i'),
            '',
            'data.json съдържа всичко, което пазим за теб: профил, обяви, оферти,',
            'сделки, съобщения, оценки, кредит и фактури.',
            '',
            'Папка „снимки" съдържа снимките, които си качил, подредени по обява.',
            '',
            'Какво НЕ е тук, и защо: данните на отсрещната страна по сделка (адрес,',
            'телефон, имейл) не са твои данни и не се изнасят. Хешовете на телефона',
            'и на серийните номера също не са включени — те не ти казват нищо за теб',
            'и не бива да напускат сървъра.',
            '',
            'Файлът се пази '.$this->lifetimeHours().' часа и после се изтрива автоматично.',
            'Пази го като лична карта — съдържа всичко наведнъж.',
        ]);
    }
}
