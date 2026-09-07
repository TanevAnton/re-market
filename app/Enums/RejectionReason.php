<?php

namespace App\Enums;

/**
 * Why a listing was refused.
 *
 * A fixed list rather than free text, for three reasons: the seller gets the
 * same answer for the same problem whoever reviewed it, the decisions can be
 * counted (DSA Art. 15 transparency reporting asks exactly this), and a
 * moderator working through a queue at speed picks rather than composes.
 *
 * The free text is still required - see ModerationService::reject. The category
 * says which rule; only a human can say what actually happened in this listing.
 */
enum RejectionReason: string
{
    case Prohibited      = 'prohibited';
    case Counterfeit     = 'counterfeit';
    case Stolen          = 'stolen';
    case StockPhotos     = 'stock_photos';
    case MissingTimestamp = 'missing_timestamp';
    case ContactInfo     = 'contact_info';
    case WrongCategory   = 'wrong_category';
    case Duplicate       = 'duplicate';
    case Misleading      = 'misleading';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Prohibited       => 'Забранен артикул',
            self::Counterfeit      => 'Фалшив или преправен продукт',
            self::Stolen           => 'Съмнение за краден хардуер',
            self::StockPhotos      => 'Чужди или рекламни снимки',
            self::MissingTimestamp => 'Липсва снимка с ръкописна бележка',
            self::ContactInfo      => 'Контакти в обявата',
            self::WrongCategory    => 'Грешна категория',
            self::Duplicate        => 'Дублирана обява',
            self::Misleading       => 'Подвеждащо описание или цена',
            self::Other            => 'Друго',
        };
    }

    /**
     * The contractual ground, in the user's language.
     *
     * DSA Art. 17(3)(d) wants the ground relied on, not merely a label - "your
     * listing broke the rules" is not a statement of reasons. Each line points
     * at the specific rule so the seller can tell whether we applied it fairly.
     */
    public function ground(): string
    {
        return match ($this) {
            self::Prohibited       => 'Условията за ползване забраняват предлагането на този вид артикули.',
            self::Counterfeit      => 'Условията за ползване забраняват фалшиви, преправени или неоригинални продукти.',
            self::Stolen           => 'Условията за ползване забраняват предлагането на хардуер със съмнителен произход.',
            self::StockPhotos      => 'Правилата за обяви изискват снимки на самия артикул, а не рекламни или чужди изображения.',
            self::MissingTimestamp => 'Правилата за частни продавачи изискват снимка на артикула до ръкописна бележка с потребителското име и датата.',
            self::ContactInfo      => 'Правилата за обяви забраняват телефони, имейли и връзки към други платформи в текста на обявата.',
            self::WrongCategory    => 'Правилата за обяви изискват артикулът да е в съответстващата му категория.',
            self::Duplicate        => 'Правилата за обяви позволяват една активна обява за един и същ артикул.',
            self::Misleading       => 'Правилата за обяви изискват описанието и цената да отговарят на предлагания артикул.',
            self::Other            => 'Обявата не отговаря на условията за ползване на платформата.',
        };
    }

    /** Whether a seller can fix this and repost, or should not try. */
    public function isFixable(): bool
    {
        return match ($this) {
            self::Prohibited, self::Counterfeit, self::Stolen => false,
            default => true,
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }
}
