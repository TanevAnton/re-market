<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bulgarian towns, by область. Populations are approximate and exist only to
 * order the dropdown so that Sofia is not sitting below Aytos - do not treat
 * them as a statistical source.
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        // [name_bg, name_en, slug, region_bg, region_en, population]
        $cities = [
            ['София', 'Sofia', 'sofia', 'София-град', 'Sofia City', 1230000],
            ['Пловдив', 'Plovdiv', 'plovdiv', 'Пловдив', 'Plovdiv', 340000],
            ['Варна', 'Varna', 'varna', 'Варна', 'Varna', 330000],
            ['Бургас', 'Burgas', 'burgas', 'Бургас', 'Burgas', 200000],
            ['Русе', 'Ruse', 'ruse', 'Русе', 'Ruse', 140000],
            ['Стара Загора', 'Stara Zagora', 'stara-zagora', 'Стара Загора', 'Stara Zagora', 135000],
            ['Плевен', 'Pleven', 'pleven', 'Плевен', 'Pleven', 90000],
            ['Сливен', 'Sliven', 'sliven', 'Сливен', 'Sliven', 85000],
            ['Добрич', 'Dobrich', 'dobrich', 'Добрич', 'Dobrich', 80000],
            ['Шумен', 'Shumen', 'shumen', 'Шумен', 'Shumen', 75000],
            ['Перник', 'Pernik', 'pernik', 'Перник', 'Pernik', 70000],
            ['Хасково', 'Haskovo', 'haskovo', 'Хасково', 'Haskovo', 70000],
            ['Ямбол', 'Yambol', 'yambol', 'Ямбол', 'Yambol', 65000],
            ['Пазарджик', 'Pazardzhik', 'pazardzhik', 'Пазарджик', 'Pazardzhik', 65000],
            ['Благоевград', 'Blagoevgrad', 'blagoevgrad', 'Благоевград', 'Blagoevgrad', 65000],
            ['Велико Търново', 'Veliko Tarnovo', 'veliko-tarnovo', 'Велико Търново', 'Veliko Tarnovo', 65000],
            ['Враца', 'Vratsa', 'vratsa', 'Враца', 'Vratsa', 55000],
            ['Габрово', 'Gabrovo', 'gabrovo', 'Габрово', 'Gabrovo', 50000],
            ['Асеновград', 'Asenovgrad', 'asenovgrad', 'Пловдив', 'Plovdiv', 50000],
            ['Казанлък', 'Kazanlak', 'kazanlak', 'Стара Загора', 'Stara Zagora', 45000],
            ['Видин', 'Vidin', 'vidin', 'Видин', 'Vidin', 40000],
            ['Кюстендил', 'Kyustendil', 'kyustendil', 'Кюстендил', 'Kyustendil', 40000],
            ['Кърджали', 'Kardzhali', 'kardzhali', 'Кърджали', 'Kardzhali', 40000],
            ['Монтана', 'Montana', 'montana', 'Монтана', 'Montana', 40000],
            ['Димитровград', 'Dimitrovgrad', 'dimitrovgrad', 'Хасково', 'Haskovo', 35000],
            ['Търговище', 'Targovishte', 'targovishte', 'Търговище', 'Targovishte', 35000],
            ['Силистра', 'Silistra', 'silistra', 'Силистра', 'Silistra', 32000],
            ['Ловеч', 'Lovech', 'lovech', 'Ловеч', 'Lovech', 32000],
            ['Разград', 'Razgrad', 'razgrad', 'Разград', 'Razgrad', 32000],
            ['Дупница', 'Dupnitsa', 'dupnitsa', 'Кюстендил', 'Kyustendil', 30000],
            ['Горна Оряховица', 'Gorna Oryahovitsa', 'gorna-oryahovitsa', 'Велико Търново', 'Veliko Tarnovo', 28000],
            ['Петрич', 'Petrich', 'petrich', 'Благоевград', 'Blagoevgrad', 28000],
            ['Свищов', 'Svishtov', 'svishtov', 'Велико Търново', 'Veliko Tarnovo', 27000],
            ['Смолян', 'Smolyan', 'smolyan', 'Смолян', 'Smolyan', 27000],
            ['Сандански', 'Sandanski', 'sandanski', 'Благоевград', 'Blagoevgrad', 25000],
            ['Самоков', 'Samokov', 'samokov', 'София област', 'Sofia Province', 25000],
            ['Нова Загора', 'Nova Zagora', 'nova-zagora', 'Сливен', 'Sliven', 22000],
            ['Велинград', 'Velingrad', 'velingrad', 'Пазарджик', 'Pazardzhik', 22000],
            ['Севлиево', 'Sevlievo', 'sevlievo', 'Габрово', 'Gabrovo', 21000],
            ['Лом', 'Lom', 'lom', 'Монтана', 'Montana', 21000],
            ['Троян', 'Troyan', 'troyan', 'Ловеч', 'Lovech', 20000],
            ['Айтос', 'Aytos', 'aytos', 'Бургас', 'Burgas', 20000],
            ['Ботевград', 'Botevgrad', 'botevgrad', 'София област', 'Sofia Province', 20000],
            ['Гоце Делчев', 'Gotse Delchev', 'gotse-delchev', 'Благоевград', 'Blagoevgrad', 19000],
            ['Пещера', 'Peshtera', 'peshtera', 'Пазарджик', 'Pazardzhik', 18000],
            ['Харманли', 'Harmanli', 'harmanli', 'Хасково', 'Haskovo', 18000],
            ['Карлово', 'Karlovo', 'karlovo', 'Пловдив', 'Plovdiv', 18000],
            ['Свиленград', 'Svilengrad', 'svilengrad', 'Хасково', 'Haskovo', 17000],
            ['Попово', 'Popovo', 'popovo', 'Търговище', 'Targovishte', 16000],
            ['Дряново', 'Dryanovo', 'dryanovo', 'Габрово', 'Gabrovo', 8000],
            ['Провадия', 'Provadia', 'provadia', 'Варна', 'Varna', 12000],
            ['Нови Пазар', 'Novi Pazar', 'novi-pazar', 'Шумен', 'Shumen', 12000],
            ['Червен бряг', 'Cherven Bryag', 'cherven-bryag', 'Плевен', 'Pleven', 12000],
            ['Раднево', 'Radnevo', 'radnevo', 'Стара Загора', 'Stara Zagora', 12000],
            ['Берковица', 'Berkovitsa', 'berkovitsa', 'Монтана', 'Montana', 12000],
            ['Разлог', 'Razlog', 'razlog', 'Благоевград', 'Blagoevgrad', 12000],
            ['Пирдоп', 'Pirdop', 'pirdop', 'София област', 'Sofia Province', 7000],
            ['Балчик', 'Balchik', 'balchik', 'Добрич', 'Dobrich', 11000],
            ['Каварна', 'Kavarna', 'kavarna', 'Добрич', 'Dobrich', 11000],
            ['Несебър', 'Nesebar', 'nesebar', 'Бургас', 'Burgas', 11000],
            ['Поморие', 'Pomorie', 'pomorie', 'Бургас', 'Burgas', 13000],
            ['Царево', 'Tsarevo', 'tsarevo', 'Бургас', 'Burgas', 6000],
            ['Девня', 'Devnya', 'devnya', 'Варна', 'Varna', 8000],
            ['Бяла', 'Byala', 'byala', 'Русе', 'Ruse', 8000],
            ['Тутракан', 'Tutrakan', 'tutrakan', 'Силистра', 'Silistra', 8000],
            ['Исперих', 'Isperih', 'isperih', 'Разград', 'Razgrad', 8000],
            ['Омуртаг', 'Omurtag', 'omurtag', 'Търговище', 'Targovishte', 8000],
            ['Левски', 'Levski', 'levski', 'Плевен', 'Pleven', 9000],
            ['Кнежа', 'Knezha', 'knezha', 'Плевен', 'Pleven', 9000],
            ['Мездра', 'Mezdra', 'mezdra', 'Враца', 'Vratsa', 9000],
            ['Козлодуй', 'Kozloduy', 'kozloduy', 'Враца', 'Vratsa', 11000],
            ['Ихтиман', 'Ihtiman', 'ihtiman', 'София област', 'Sofia Province', 11000],
            ['Костинброд', 'Kostinbrod', 'kostinbrod', 'София област', 'Sofia Province', 10000],
            ['Своге', 'Svoge', 'svoge', 'София област', 'Sofia Province', 7000],
            ['Радомир', 'Radomir', 'radomir', 'Перник', 'Pernik', 12000],
            ['Панагюрище', 'Panagyurishte', 'panagyurishte', 'Пазарджик', 'Pazardzhik', 15000],
            ['Септември', 'Septemvri', 'septemvri', 'Пазарджик', 'Pazardzhik', 8000],
            ['Първомай', 'Parvomay', 'parvomay', 'Пловдив', 'Plovdiv', 12000],
            ['Раковски', 'Rakovski', 'rakovski', 'Пловдив', 'Plovdiv', 15000],
            ['Стамболийски', 'Stamboliyski', 'stamboliyski', 'Пловдив', 'Plovdiv', 11000],
            ['Хисаря', 'Hisarya', 'hisarya', 'Пловдив', 'Plovdiv', 7000],
            ['Чирпан', 'Chirpan', 'chirpan', 'Стара Загора', 'Stara Zagora', 13000],
            ['Гълъбово', 'Galabovo', 'galabovo', 'Стара Загора', 'Stara Zagora', 8000],
            ['Елхово', 'Elhovo', 'elhovo', 'Ямбол', 'Yambol', 9000],
            ['Момчилград', 'Momchilgrad', 'momchilgrad', 'Кърджали', 'Kardzhali', 8000],
            ['Девин', 'Devin', 'devin', 'Смолян', 'Smolyan', 6000],
            ['Златоград', 'Zlatograd', 'zlatograd', 'Смолян', 'Smolyan', 6000],
            ['Тетевен', 'Teteven', 'teteven', 'Ловеч', 'Lovech', 8000],
            ['Ябланица', 'Yablanitsa', 'yablanitsa', 'Ловеч', 'Lovech', 3000],
            ['Павликени', 'Pavlikeni', 'pavlikeni', 'Велико Търново', 'Veliko Tarnovo', 9000],
            ['Елена', 'Elena', 'elena', 'Велико Търново', 'Veliko Tarnovo', 5000],
            ['Лясковец', 'Lyaskovets', 'lyaskovets', 'Велико Търново', 'Veliko Tarnovo', 8000],
            ['Трявна', 'Tryavna', 'tryavna', 'Габрово', 'Gabrovo', 8000],
            ['Белоградчик', 'Belogradchik', 'belogradchik', 'Видин', 'Vidin', 5000],
            ['Кула', 'Kula', 'kula', 'Видин', 'Vidin', 2500],
            ['Бобов дол', 'Bobov Dol', 'bobov-dol', 'Кюстендил', 'Kyustendil', 5000],
            ['Симитли', 'Simitli', 'simitli', 'Благоевград', 'Blagoevgrad', 6000],
            ['Банско', 'Bansko', 'bansko', 'Благоевград', 'Blagoevgrad', 8000],
            ['Карнобат', 'Karnobat', 'karnobat', 'Бургас', 'Burgas', 16000],
            ['Средец', 'Sredets', 'sredets', 'Бургас', 'Burgas', 8000],
            ['Долни Чифлик', 'Dolni Chiflik', 'dolni-chiflik', 'Варна', 'Varna', 6000],
            ['Генерал Тошево', 'General Toshevo', 'general-toshevo', 'Добрич', 'Dobrich', 6000],
            ['Тервел', 'Tervel', 'tervel', 'Добрич', 'Dobrich', 6000],
            ['Велики Преслав', 'Veliki Preslav', 'veliki-preslav', 'Шумен', 'Shumen', 8000],
            ['Мадан', 'Madan', 'madan', 'Смолян', 'Smolyan', 5000],
            ['Друг град', 'Other town', 'drug-grad', 'Друга област', 'Other province', 0],
        ];

        $rows = [];
        foreach ($cities as [$bg, $en, $slug, $regionBg, $regionEn, $pop]) {
            $rows[] = [
                'name_bg'    => $bg,
                'name_en'    => $en,
                'slug'       => $slug,
                'region_bg'  => $regionBg,
                'region_en'  => $regionEn,
                'population' => $pop,
            ];
        }

        DB::table('cities')->upsert($rows, ['slug'], ['name_bg', 'name_en', 'region_bg', 'region_en', 'population']);

        $this->command->info('Seeded '.count($rows).' cities.');
    }
}
