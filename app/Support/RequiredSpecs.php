<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The listing-scoped specs a category insists on, as validation rules.
 *
 * `required` WAS DECORATIVE ON THIS SIDE OF THE SCHEMA. The wizard validated
 * its scalar fields and left the spec blob alone, so `monitor.dead_pixels`,
 * `prebuilt.cpu_model` and `prebuilt.gpu_model` were marked required and
 * nothing enforced them anywhere. A flag that reads as a guarantee and is not
 * one is worse than no flag: it tells the next person the question is already
 * handled.
 *
 * ONE HOME, because there are two screens that write specs. Enforcing this in
 * the wizard alone would mean a seller could publish with the field filled and
 * then blank it on the edit screen, which is the same hole with an extra step —
 * the shape the codebase already knows from the offer floor, which lives in the
 * service precisely so no caller can route around it.
 *
 * It is only enforceable at all because every one of these can be answered
 * honestly: `dead_pixels` offers „Не е проверено", and a seller listing a whole
 * machine knows what is inside it — for `prebuilt` those two fields ARE the
 * specification, since that category has no catalogue row behind it. A required
 * field that makes people guess produces worse data than a blank one.
 */
class RequiredSpecs
{
    /** @return array<string, array<string, mixed>> */
    public static function forCategory(?string $category): array
    {
        if (! $category) {
            return [];
        }

        return array_filter(
            config("catalog.categories.{$category}.specs", []),
            fn (array $spec) => ($spec['required'] ?? false)
                && ($spec['scope'] ?? 'part') === 'listing',
        );
    }

    /**
     * Rules keyed `specs.<key>`, ready to merge into a validate() call.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?string $category): array
    {
        $rules = [];

        foreach (self::forCategory($category) as $key => $spec) {
            $rule = ['required'];

            $rule[] = match ($spec['type'] ?? 'string') {
                'int'     => 'integer',
                'decimal' => 'numeric',
                'bool'    => 'boolean',
                default   => 'string',
            };

            // multiselect holds an array, which `in` would reject wholesale.
            if (isset($spec['options']) && ($spec['type'] ?? '') !== 'multiselect') {
                $rule[] = Rule::in($spec['options']);
            }

            $rules["specs.{$key}"] = $rule;
        }

        return $rules;
    }

    /**
     * Messages that name the field on the screen rather than the database key.
     *
     * „Полето specs.dead_pixels е задължително" is addressed to a developer.
     * Generated from the same labels the inputs are rendered with, so the two
     * cannot drift.
     *
     * @return array<string, string>
     */
    public static function messages(?string $category): array
    {
        $messages = [];

        foreach (self::forCategory($category) as $key => $spec) {
            $label = $spec['label'][app()->getLocale()] ?? $spec['label']['en'] ?? $key;

            $messages["specs.{$key}.required"] = 'Попълни „'.$label.'".';
            $messages["specs.{$key}.in"]       = 'Избери стойност за „'.$label.'".';
            $messages["specs.{$key}.integer"]  = '„'.$label.'" трябва да е число.';
        }

        return $messages;
    }
}
