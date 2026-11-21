<?php

namespace App\Support;

use App\Models\Part;
use App\Models\User;

/**
 * „Моят компютър" - what a buyer already owns, and whether this part fits it.
 *
 * The single most expensive mistake in used hardware is the part that does not
 * fit: a card longer than the case, a processor for a different socket, memory
 * of a generation the board does not take. Every one of them is a return, a
 * dispute and a buyer who does not come back - and every one of them is
 * answerable from numbers the catalogue already holds on one side and the buyer
 * can type in thirty seconds on the other.
 *
 * WHAT IT CLAIMS. Only what two typed facts settle: a socket is the same or it
 * is not, a wattage is above a threshold or it is not. It says nothing about
 * performance, bottlenecks or whether the upgrade is worth it, because those
 * are opinions and this is arithmetic. Where either side is missing it says
 * nothing about that point, and it says which fact would let it.
 *
 * THE THRESHOLDS ARE NOT DUPLICATED. „Колко ват иска тази карта" is the rule in
 * config/compatibility.php, read through Compatibility::psuNeededFor(), so the
 * sentence on this panel can never disagree with the „Захранвания от 600 W
 * нагоре" link a few lines away on the same page.
 *
 * WHERE IT LIVES. On the account when there is one, in the session when there
 * is not. A visitor can try it before registering, and a registered one does
 * not have to retype it on every phone.
 */
class MyPc
{
    public const OK    = 'ok';
    public const NO    = 'no';

    /** Largest to smallest is the order a case „приема" them; bigger rank = bigger board. */
    private const FORM_RANK = ['Mini-ITX' => 1, 'Mini-DTX' => 2, 'Micro-ATX' => 3, 'ATX' => 4, 'E-ATX' => 5];

    /**
     * The fields, in the order they are asked.
     *
     * @return array<string, array{label: string, type: string, options?: list<string>, min?: int, max?: int, unit?: string, help?: string, group: string}>
     */
    public static function fields(): array
    {
        return [
            'socket' => [
                'group'   => 'platform',
                'label'   => 'Сокет на процесора и платката',
                'type'    => 'select',
                'options' => self::sockets(),
                'help'    => 'Пише на кутията на процесора, в CPU-Z или в спецификацията на платката.',
            ],
            'ram_type' => [
                'group'   => 'platform',
                'label'   => 'Тип памет',
                'type'    => 'select',
                'options' => ['DDR3', 'DDR4', 'DDR5'],
            ],
            'board_form_factor' => [
                'group'   => 'platform',
                'label'   => 'Формат на дънната платка',
                'type'    => 'select',
                'options' => self::options('motherboard', 'form_factor', array_keys(self::FORM_RANK)),
            ],
            'case_form_factor' => [
                'group'   => 'case',
                'label'   => 'Най-голямата платка, която кутията приема',
                'type'    => 'select',
                'options' => self::options('case', 'form_factor', ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX']),
            ],
            'case_max_gpu_mm' => [
                'group' => 'case',
                'label' => 'Най-дълга видеокарта, която влиза в кутията',
                'type'  => 'int', 'min' => 100, 'max' => 600, 'unit' => 'мм',
                'help'  => 'Измери от задния панел до първия вентилатор или предната решетка.',
            ],
            'psu_w' => [
                'group' => 'power',
                'label' => 'Захранване',
                'type'  => 'int', 'min' => 150, 'max' => 2500, 'unit' => 'W',
            ],
            'gpu_tdp_w' => [
                'group' => 'power',
                'label' => 'Мощност на видеокартата ти',
                'type'  => 'int', 'min' => 10, 'max' => 1000, 'unit' => 'W',
                'help'  => 'TDP или „Total board power" от спецификацията.',
            ],
            'gpu_length_mm' => [
                'group' => 'power',
                'label' => 'Дължина на видеокартата ти',
                'type'  => 'int', 'min' => 60, 'max' => 500, 'unit' => 'мм',
            ],
        ];
    }

    public static function groups(): array
    {
        return [
            'platform' => 'Платформа',
            'case'     => 'Кутия',
            'power'    => 'Захранване и видеокарта',
        ];
    }

    /** Every socket the catalogue knows for a processor or a board, once each. */
    private static function sockets(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('catalog.categories.cpu.specs.socket.options', []),
            (array) config('catalog.categories.motherboard.specs.socket.options', []),
        )));
    }

    /** @param  list<string>  $fallback */
    private static function options(string $category, string $spec, array $fallback): array
    {
        return array_values((array) config("catalog.categories.{$category}.specs.{$spec}.options", $fallback));
    }

    // --- storage ----------------------------------------------------------

    /**
     * The visitor's own computer, cleaned. Empty when they have not said.
     *
     * @return array<string, string|int>
     */
    public static function current(?User $user = null): array
    {
        $user ??= auth()->user();

        $stored = $user?->my_pc;

        if (! is_array($stored) || $stored === []) {
            $stored = session('my_pc');
        }

        return self::clean(is_array($stored) ? $stored : []);
    }

    /**
     * Keep it. Returns what was kept, which is not necessarily what was sent:
     * a field with a value the catalogue does not know, or a number outside
     * the plausible range, is dropped rather than stored and trusted.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>
     */
    public static function save(array $input, ?User $user = null): array
    {
        $user ??= auth()->user();
        $clean = self::clean($input);

        if ($user) {
            $user->forceFill(['my_pc' => $clean === [] ? null : $clean])->save();
        }

        session(['my_pc' => $clean]);

        return $clean;
    }

    public static function forget(?User $user = null): void
    {
        self::save([], $user);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>
     */
    public static function clean(array $input): array
    {
        $out = [];

        foreach (self::fields() as $key => $field) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'select') {
                if (is_string($value) && in_array($value, $field['options'], true)) {
                    $out[$key] = $value;
                }

                continue;
            }

            if (is_numeric($value)) {
                $n = (int) round((float) $value);

                if ($n >= $field['min'] && $n <= $field['max']) {
                    $out[$key] = $n;
                }
            }
        }

        return $out;
    }

    // --- the check --------------------------------------------------------

    /**
     * What the facts say about this model and this computer.
     *
     * `applies` is false for a category with nothing to check - a monitor, a
     * keyboard - so the caller draws no panel at all rather than an empty one.
     * `missing` names the fields that would let more of it be answered.
     *
     * @return array{applies: bool, rows: list<array{status: string, text: string}>, missing: list<string>, has_profile: bool}
     */
    public static function check(?Part $part, ?array $pc = null): array
    {
        $none = ['applies' => false, 'rows' => [], 'missing' => [], 'has_profile' => false];

        if (! $part || ! isset(self::NEEDS[$part->category])) {
            return $none;
        }

        $pc ??= self::current();

        $rows = match ($part->category) {
            'gpu'         => self::gpu($part, $pc),
            'cpu'         => self::cpu($part, $pc),
            'motherboard' => self::motherboard($part, $pc),
            'ram'         => self::ram($part, $pc),
            'psu'         => self::psu($part, $pc),
            'case'        => self::pcCase($part, $pc),
            'cooler'      => self::cooler($part, $pc),
        };

        // Only the missing facts that are worth asking for: the model has to
        // carry the other half, or there is nothing to compare against.
        $missing = [];

        foreach (self::NEEDS[$part->category] as $partSpec => $field) {
            $partHasIt = $part->spec($partSpec) !== null && $part->spec($partSpec) !== '' && $part->spec($partSpec) !== [];

            if ($partHasIt && ! isset($pc[$field])) {
                $missing[] = self::fields()[$field]['label'];
            }
        }

        return [
            'applies'     => true,
            'rows'        => $rows,
            'missing'     => array_values(array_unique($missing)),
            'has_profile' => $pc !== [],
        ];
    }

    /**
     * Per category: the spec on the MODEL => the field on the computer that it
     * is checked against. Drives both what is compared and what is asked for.
     */
    private const NEEDS = [
        'gpu'         => ['tdp_w' => 'psu_w', 'length_mm' => 'case_max_gpu_mm'],
        'cpu'         => ['socket' => 'socket'],
        'motherboard' => ['socket' => 'socket', 'ram_type' => 'ram_type', 'form_factor' => 'case_form_factor'],
        'ram'         => ['type' => 'ram_type'],
        'psu'         => ['wattage' => 'gpu_tdp_w'],
        'case'        => ['form_factor' => 'board_form_factor', 'max_gpu_mm' => 'gpu_length_mm'],
        'cooler'      => ['sockets' => 'socket'],
    ];

    private static function row(bool $fits, string $yes, string $no): array
    {
        return ['status' => $fits ? self::OK : self::NO, 'text' => $fits ? $yes : $no];
    }

    private static function gpu(Part $part, array $pc): array
    {
        $rows = [];

        $tdp = (int) $part->spec('tdp_w');
        $need = $tdp > 0 ? Compatibility::psuNeededFor($tdp) : null;

        if ($need && isset($pc['psu_w'])) {
            $rows[] = self::row(
                $pc['psu_w'] >= $need,
                "Захранването ти ({$pc['psu_w']} W) стига — картата е {$tdp} W и иска поне {$need} W.",
                "Захранването ти ({$pc['psu_w']} W) е малко — картата е {$tdp} W и иска поне {$need} W.",
            );
        }

        $length = (int) $part->spec('length_mm');

        if ($length > 0 && isset($pc['case_max_gpu_mm'])) {
            $rows[] = self::row(
                $length <= $pc['case_max_gpu_mm'],
                "Влиза в кутията ти — картата е {$length} мм, а кутията приема до {$pc['case_max_gpu_mm']} мм.",
                "Не влиза в кутията ти — картата е {$length} мм, а кутията приема до {$pc['case_max_gpu_mm']} мм.",
            );
        }

        return $rows;
    }

    private static function cpu(Part $part, array $pc): array
    {
        $socket = (string) $part->spec('socket');

        if ($socket === '' || ! isset($pc['socket'])) {
            return [];
        }

        return [self::row(
            strcasecmp($socket, $pc['socket']) === 0,
            "Сокет {$socket} — същият като твоя.",
            "Процесорът е за сокет {$socket}, а твоят е {$pc['socket']} — без нова дънна платка не става.",
        )];
    }

    private static function motherboard(Part $part, array $pc): array
    {
        $rows = [];

        $socket = (string) $part->spec('socket');

        if ($socket !== '' && isset($pc['socket'])) {
            $rows[] = self::row(
                strcasecmp($socket, $pc['socket']) === 0,
                "Сокет {$socket} — процесорът ти влиза.",
                "Платката е за сокет {$socket}, а твоят процесор е за {$pc['socket']} — няма да влезе.",
            );
        }

        $ram = (string) $part->spec('ram_type');

        if ($ram !== '' && isset($pc['ram_type'])) {
            $rows[] = self::row(
                strcasecmp($ram, $pc['ram_type']) === 0,
                "Приема {$ram} — паметта ти е същата.",
                "Платката приема само {$ram}, а паметта ти е {$pc['ram_type']} — ще ти трябва нова.",
            );
        }

        $form = (string) $part->spec('form_factor');

        if (isset(self::FORM_RANK[$form], $pc['case_form_factor'], self::FORM_RANK[$pc['case_form_factor']])) {
            $rows[] = self::row(
                self::FORM_RANK[$form] <= self::FORM_RANK[$pc['case_form_factor']],
                "Формат {$form} — влиза в кутията ти.",
                "Формат {$form} е по-голям от най-голямото, което приема кутията ти ({$pc['case_form_factor']}).",
            );
        }

        return $rows;
    }

    private static function ram(Part $part, array $pc): array
    {
        $type = (string) $part->spec('type');

        if ($type === '' || ! isset($pc['ram_type'])) {
            return [];
        }

        return [self::row(
            strcasecmp($type, $pc['ram_type']) === 0,
            "{$type} — същият тип като в компютъра ти.",
            "Това е {$type}, а компютърът ти е с {$pc['ram_type']} — физически не се слагат един в друг.",
        )];
    }

    private static function psu(Part $part, array $pc): array
    {
        $watts = (int) $part->spec('wattage');

        if ($watts <= 0 || ! isset($pc['gpu_tdp_w'])) {
            return [];
        }

        $need = Compatibility::psuNeededFor($pc['gpu_tdp_w']);

        if (! $need) {
            return [];
        }

        return [self::row(
            $watts >= $need,
            "{$watts} W стига за видеокарта от {$pc['gpu_tdp_w']} W — иска се поне {$need} W.",
            "{$watts} W е малко за видеокарта от {$pc['gpu_tdp_w']} W — иска се поне {$need} W.",
        )];
    }

    private static function pcCase(Part $part, array $pc): array
    {
        $rows = [];

        $supported = array_map('strval', (array) $part->spec('form_factor'));

        if ($supported !== [] && isset($pc['board_form_factor'])) {
            $rows[] = self::row(
                in_array($pc['board_form_factor'], $supported, true),
                "Приема платка {$pc['board_form_factor']}.",
                "Не приема платка {$pc['board_form_factor']} — приема: ".implode(', ', $supported).'.',
            );
        }

        $max = (int) $part->spec('max_gpu_mm');

        if ($max > 0 && isset($pc['gpu_length_mm'])) {
            $rows[] = self::row(
                $pc['gpu_length_mm'] <= $max,
                "Видеокартата ти ({$pc['gpu_length_mm']} мм) влиза — кутията приема до {$max} мм.",
                "Видеокартата ти ({$pc['gpu_length_mm']} мм) не влиза — кутията приема до {$max} мм.",
            );
        }

        return $rows;
    }

    private static function cooler(Part $part, array $pc): array
    {
        $sockets = array_map('strval', (array) $part->spec('sockets'));

        if ($sockets === [] || ! isset($pc['socket'])) {
            return [];
        }

        return [self::row(
            in_array($pc['socket'], $sockets, true),
            "Има комплект за твоя сокет ({$pc['socket']}).",
            "Не е за сокет {$pc['socket']} — поддържа: ".implode(', ', $sockets).'.',
        )];
    }
}
