<?php

namespace App\Support;

use App\Models\Listing;

/**
 * „Заключен ли е за чужд Apple ID?" — the one question on an Apple listing
 * that decides whether the thing in the photographs is a device or a paperweight.
 *
 * A phone, tablet or Mac still signed in to somebody else's Apple ID is not
 * repairable, unlockable or resettable: not by a service shop, not by the
 * carrier, not by Apple without the original proof of purchase. It is also
 * completely invisible until the buyer gets home and wipes it. That is the
 * whole reason `icloud_signed_out` is now required and now a select — see the
 * iphone block in config/catalog.php.
 *
 * WHY THIS CLASS EXISTS RATHER THAN A COMPARISON IN A VIEW: the answer is a
 * string, and three screens want to know what it means. A view comparing
 * `$listing->specs['icloud_signed_out'] === 'Не мога да изляза'` is a copy of
 * the config in Bulgarian punctuation, and the copy that does not get updated
 * is the one that silently stops badging locked devices.
 *
 * The strings are read from the category's own `states` map, so the wizard's
 * options and this reading can never drift apart. `AppleLockTest` asserts every
 * value in `states` is one of `options`.
 */
class AppleLock
{
    /** Categories whose schema carries the question at all. */
    public const CATEGORIES = ['iphone', 'ipad', 'macbook'];

    public const SPEC = 'icloud_signed_out';

    /**
     * The state of one listing, or null when the question does not apply.
     *
     * Null covers three different situations on purpose — a graphics card, an
     * Apple listing posted before the field was required, and a value that is
     * no longer one of the options. None of them is a locked device, and none
     * of them should be badged as one.
     *
     * @return 'clear'|'pending'|'locked'|null
     */
    public static function state(Listing $listing): ?string
    {
        if (! in_array($listing->category, self::CATEGORIES, true)) {
            return null;
        }

        $answer = $listing->specs[self::SPEC] ?? null;

        if (! is_string($answer) || $answer === '') {
            return null;
        }

        $states = config("catalog.categories.{$listing->category}.specs."
            .self::SPEC.'.states', []);

        return array_search($answer, $states, true) ?: null;
    }

    /** Everything a buyer needs told to them, or null when there is nothing to say. */
    public static function notice(Listing $listing): ?array
    {
        return match (self::state($listing)) {
            /*
             * The one that matters. Said plainly, in the seller's own answer,
             * rather than softened: a buyer who reads „внимание" and nothing
             * else will assume it is boilerplate.
             */
            'locked' => [
                'level' => 'critical',
                'badge' => 'заключен за Apple ID',
                'title' => 'Продавачът казва, че не може да излезе от акаунта.',
                'body'  => 'Устройството остава заключено за чужд Apple ID и не се отключва '
                    .'от сервиз, от оператор или от Apple. Купувай го само ако ти трябва '
                    .'за части, и на цена за части.',
            ],

            /*
             * Not a warning — the normal way a careful sale goes. It is here
             * because it tells the buyer what to do AT the meeting, which is
             * the moment the thing is still fixable.
             */
            'pending' => [
                'level' => 'info',
                'badge' => null,
                'title' => 'Излизането от акаунта става пред теб.',
                'body'  => 'Гледай продавачът да излезе и устройството да се рестартира, '
                    .'преди парите да сменят ръцете си. След това е късно.',
            ],

            default => null,
        };
    }

    /** True when this listing is one a buyer should be warned about. */
    public static function isLocked(Listing $listing): bool
    {
        return self::state($listing) === 'locked';
    }
}
