<?php

namespace App\Notifications;

use App\Models\User;

/**
 * „We checked your company" — or could not.
 *
 * A refusal carries the moderator's sentence, because the applicant can usually
 * fix it: an ЕИК with a digit wrong, an address that no longer matches the
 * register, a company name entered as the trade name rather than the registered
 * one. A bare „не мина" turns every refusal into a support ticket.
 */
class TraderDecision extends RemarketNotification
{
    public function __construct(
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {}

    public function subject(User $user): string
    {
        return $this->approved
            ? 'Фирмата ти е проверена'
            : 'Проверката на фирмата не мина';
    }

    public function lines(User $user): array
    {
        if ($this->approved) {
            return [
                'Проверихме фирмата в Търговския регистър. Обявите ти вече носят '
                    .'етикет „проверена фирма" с името на дружеството.',

                // Said plainly, because the badge is not what gives the buyer
                // their rights — the declaration is, and it was already there.
                'Това не променя правата на купувачите: те се прилагат, откакто '
                    .'продаваш като търговец, независимо от проверката.',
            ];
        }

        return array_filter([
            'Не успяхме да потвърдим данните за фирмата.',
            $this->reason,
            'Провери ги в настройките и заяви проверка отново. Ако смяташ, че '
                .'има грешка от наша страна, пиши ни от Поддръжка.',
        ]);
    }

    public function url(User $user): string
    {
        return route('profile.edit');
    }

    public function action(User $user): string
    {
        return 'Отвори настройките';
    }
}
