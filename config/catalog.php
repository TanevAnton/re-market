<?php

/**
 * The catalogue schema.
 *
 * Every filter on the site is generated from this file: the listing form,
 * the facet sidebar, the comparison table and the part landing pages all
 * read it. Adding a filter means adding a key here, not writing a query.
 *
 * Field types:  select | multiselect | int | decimal | bool | text
 * Facets:       terms (checkbox list) | range (min/max slider) | bool
 *
 * 'scope' says where the value lives:
 *   part    - a property of the model itself (an RTX 4070 always has 12 GB)
 *   listing - a property of this individual unit (its warranty, its box)
 */

return [

    'conditions' => [
        'new'       => ['bg' => 'Нова, неотваряна',        'en' => 'New, sealed'],
        'like_new'  => ['bg' => 'Като нова',                'en' => 'Like new'],
        'used'      => ['bg' => 'Употребявана',             'en' => 'Used'],
        'for_parts' => ['bg' => 'За части / не работи',     'en' => 'For parts / not working'],
    ],

    'categories' => [

        // ---------------------------------------------------------------
        'gpu' => [
            'label'  => ['bg' => 'Видеокарти', 'en' => 'Graphics cards'],
            'slug'   => ['bg' => 'videokarti', 'en' => 'graphics-cards'],
            'specs'  => [
                'chipset' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Чипсет', 'en' => 'Chipset'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'brand' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Производител', 'en' => 'Board partner'],
                    'options' => ['ASUS','MSI','Gigabyte','Zotac','Palit','Gainward','Sapphire',
                                  'PowerColor','XFX','Inno3D','EVGA','PNY','Founders Edition','Other'],
                    'facet' => 'terms', 'priority' => 6,
                ],
                'vram_gb' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'GB', 'required' => true,
                    'label' => ['bg' => 'Видео памет', 'en' => 'VRAM'],
                    'facet' => 'terms', 'options' => [2,3,4,6,8,10,12,16,20,24,32],
                    'priority' => 2,
                ],
                'vram_type' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Тип памет', 'en' => 'Memory type'],
                    'options' => ['GDDR5','GDDR5X','GDDR6','GDDR6X','GDDR7','HBM2'],
                    'facet' => 'terms', 'priority' => 9,
                ],
                'tdp_w' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'W',
                    'label' => ['bg' => 'Консумация', 'en' => 'TDP'],
                    'facet' => 'range', 'range' => [30, 600], 'priority' => 4,
                ],
                // The filter nobody else offers and everybody needs.
                'length_mm' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Дължина', 'en' => 'Length'],
                    'help'  => ['bg' => 'Провери колко място има в кутията си',
                                'en' => 'Check the clearance in your case'],
                    'facet' => 'range', 'range' => [120, 400], 'priority' => 3,
                ],
                'slot_width' => [
                    'type' => 'select', 'scope' => 'part', 'unit' => 'слота',
                    'label' => ['bg' => 'Широчина', 'en' => 'Slot width'],
                    'options' => ['1','1.5','2','2.5','3','3.5','4'],
                    'facet' => 'terms', 'priority' => 8,
                ],
                'power_connectors' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Захранващи конектори', 'en' => 'Power connectors'],
                    'options' => ['Няма','1x 6-pin','1x 8-pin','6+8-pin','2x 8-pin',
                                  '3x 8-pin','1x 12VHPWR','1x 12V-2x6'],
                    'facet' => 'terms', 'priority' => 5,
                ],
                'outputs' => [
                    'type' => 'multiselect', 'scope' => 'part',
                    'label' => ['bg' => 'Изходи', 'en' => 'Outputs'],
                    'options' => ['HDMI 2.1','HDMI 2.0','DisplayPort 2.1','DisplayPort 1.4',
                                  'DVI-D','VGA','USB-C'],
                    'facet' => 'terms', 'priority' => 10,
                ],
                'backplate' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'Има бекплейт', 'en' => 'Has backplate'],
                    'priority' => 20,
                ],
                'repadded' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'Сменена паста/подложки', 'en' => 'Repasted / repadded'],
                    'priority' => 21,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'cpu' => [
            'label' => ['bg' => 'Процесори', 'en' => 'Processors'],
            'slug'  => ['bg' => 'procesori', 'en' => 'processors'],
            'specs' => [
                'socket' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Сокет', 'en' => 'Socket'],
                    'options' => ['AM4','AM5','LGA1151','LGA1200','LGA1700','LGA1851',
                                  'LGA2011','LGA2066','sTRX4','sWRX8'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'cores' => [
                    'type' => 'int', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Ядра', 'en' => 'Cores'],
                    'facet' => 'terms', 'options' => [2,4,6,8,10,12,14,16,20,24,32,64],
                    'priority' => 2,
                ],
                'threads' => [
                    'type' => 'int', 'scope' => 'part',
                    'label' => ['bg' => 'Нишки', 'en' => 'Threads'], 'priority' => 7,
                ],
                'base_clock_ghz' => [
                    'type' => 'decimal', 'scope' => 'part', 'unit' => 'GHz',
                    'label' => ['bg' => 'Базова честота', 'en' => 'Base clock'], 'priority' => 8,
                ],
                'boost_clock_ghz' => [
                    'type' => 'decimal', 'scope' => 'part', 'unit' => 'GHz',
                    'label' => ['bg' => 'Турбо честота', 'en' => 'Boost clock'],
                    'facet' => 'range', 'range' => [2.0, 6.5], 'priority' => 3,
                ],
                'tdp_w' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'W',
                    'label' => ['bg' => 'TDP', 'en' => 'TDP'],
                    'facet' => 'range', 'range' => [15, 350], 'priority' => 5,
                ],
                'igpu' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'Вградена графика', 'en' => 'Integrated graphics'],
                    'facet' => 'bool', 'priority' => 4,
                ],
                'has_cooler' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С охладител', 'en' => 'Cooler included'],
                    'facet' => 'bool', 'priority' => 6,
                ],
                'delidded' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'Делидван', 'en' => 'Delidded'], 'priority' => 22,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'motherboard' => [
            'label' => ['bg' => 'Дънни платки', 'en' => 'Motherboards'],
            'slug'  => ['bg' => 'dunni-platki', 'en' => 'motherboards'],
            'specs' => [
                'socket' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Сокет', 'en' => 'Socket'],
                    'options' => ['AM4','AM5','LGA1151','LGA1200','LGA1700','LGA1851','sTRX4'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'chipset' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Чипсет', 'en' => 'Chipset'],
                    'facet' => 'terms', 'priority' => 2,
                ],
                'form_factor' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Формат', 'en' => 'Form factor'],
                    'options' => ['E-ATX','ATX','Micro-ATX','Mini-ITX','Mini-DTX'],
                    'facet' => 'terms', 'priority' => 3,
                ],
                'ram_type' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Тип RAM', 'en' => 'Memory type'],
                    'options' => ['DDR3','DDR4','DDR5'],
                    'facet' => 'terms', 'priority' => 4,
                ],
                'ram_slots' => [
                    'type' => 'int', 'scope' => 'part',
                    'label' => ['bg' => 'RAM слотове', 'en' => 'DIMM slots'],
                    'facet' => 'terms', 'options' => [2,4,8], 'priority' => 5,
                ],
                'm2_slots' => [
                    'type' => 'int', 'scope' => 'part',
                    'label' => ['bg' => 'M.2 слотове', 'en' => 'M.2 slots'],
                    'facet' => 'terms', 'options' => [0,1,2,3,4,5], 'priority' => 6,
                ],
                'pcie_gen' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'PCIe версия', 'en' => 'PCIe generation'],
                    'options' => ['3.0','4.0','5.0'], 'facet' => 'terms', 'priority' => 7,
                ],
                'wifi' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'Вграден WiFi', 'en' => 'Onboard WiFi'],
                    'facet' => 'bool', 'priority' => 8,
                ],
                'has_io_shield' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С I/O шийлд', 'en' => 'I/O shield included'],
                    'priority' => 20,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'ram' => [
            'label' => ['bg' => 'Рам памет', 'en' => 'Memory'],
            'slug'  => ['bg' => 'ram-pamet', 'en' => 'memory'],
            'specs' => [
                'type' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Тип', 'en' => 'Type'],
                    'options' => ['DDR3','DDR4','DDR5'], 'facet' => 'terms', 'priority' => 1,
                ],
                'total_gb' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'GB', 'required' => true,
                    'label' => ['bg' => 'Общ капацитет', 'en' => 'Total capacity'],
                    'facet' => 'terms', 'options' => [4,8,16,32,48,64,96,128], 'priority' => 2,
                ],
                'kit_layout' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Конфигурация', 'en' => 'Kit layout'],
                    'options' => ['1x','2x','4x'], 'facet' => 'terms', 'priority' => 4,
                ],
                'speed_mts' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'MT/s',
                    'label' => ['bg' => 'Честота', 'en' => 'Speed'],
                    'facet' => 'range', 'range' => [1333, 8400], 'priority' => 3,
                ],
                'cas_latency' => [
                    'type' => 'int', 'scope' => 'part',
                    'label' => ['bg' => 'CAS латентност', 'en' => 'CAS latency'], 'priority' => 5,
                ],
                'ecc' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'ECC', 'en' => 'ECC'], 'facet' => 'bool', 'priority' => 7,
                ],
                'rgb' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'RGB подсветка', 'en' => 'RGB'], 'priority' => 9,
                ],
                'height_mm' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Височина', 'en' => 'Height'],
                    'help' => ['bg' => 'Важно при големи въздушни охладители',
                               'en' => 'Matters under large air coolers'],
                    'priority' => 10,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'psu' => [
            'label' => ['bg' => 'Захранвания', 'en' => 'Power supplies'],
            'slug'  => ['bg' => 'zahranvaniya', 'en' => 'power-supplies'],
            'specs' => [
                'wattage' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'W', 'required' => true,
                    'label' => ['bg' => 'Мощност', 'en' => 'Wattage'],
                    'facet' => 'range', 'range' => [200, 2000], 'priority' => 1,
                ],
                'efficiency' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Сертификат', 'en' => 'Efficiency rating'],
                    'options' => ['Без','80+ White','80+ Bronze','80+ Silver',
                                  '80+ Gold','80+ Platinum','80+ Titanium'],
                    'facet' => 'terms', 'priority' => 2,
                ],
                'modularity' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Модулност', 'en' => 'Modularity'],
                    'options' => ['Немодулно','Полумодулно','Напълно модулно'],
                    'facet' => 'terms', 'priority' => 3,
                ],
                'atx_version' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'ATX стандарт', 'en' => 'ATX version'],
                    'options' => ['ATX 2.x','ATX 3.0','ATX 3.1'],
                    'facet' => 'terms', 'priority' => 4,
                ],
                'has_12vhpwr' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'Има 12VHPWR / 12V-2x6', 'en' => 'Native 12VHPWR'],
                    'facet' => 'bool', 'priority' => 5,
                ],
                'form_factor' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Формат', 'en' => 'Form factor'],
                    'options' => ['ATX','SFX','SFX-L','TFX','Flex ATX'],
                    'facet' => 'terms', 'priority' => 6,
                ],
                'has_all_cables' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С всички кабели', 'en' => 'All cables included'],
                    'facet' => 'bool', 'priority' => 7,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'storage' => [
            'label' => ['bg' => 'Дискове', 'en' => 'Storage'],
            'slug'  => ['bg' => 'diskove', 'en' => 'storage'],
            'specs' => [
                'drive_type' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Тип', 'en' => 'Type'],
                    'options' => ['NVMe SSD','SATA SSD','HDD 3.5"','HDD 2.5"','External'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'capacity_gb' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'GB', 'required' => true,
                    'label' => ['bg' => 'Капацитет', 'en' => 'Capacity'],
                    'facet' => 'terms',
                    'options' => [120,240,256,480,500,512,1000,1024,2000,4000,8000,12000,16000,20000],
                    'priority' => 2,
                ],
                'interface' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Интерфейс', 'en' => 'Interface'],
                    'options' => ['PCIe 3.0 x4','PCIe 4.0 x4','PCIe 5.0 x4','SATA III','USB 3.2'],
                    'facet' => 'terms', 'priority' => 3,
                ],
                'form_factor' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Формат', 'en' => 'Form factor'],
                    'options' => ['M.2 2280','M.2 2242','M.2 22110','2.5"','3.5"'],
                    'facet' => 'terms', 'priority' => 4,
                ],
                'tbw' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'TBW',
                    'label' => ['bg' => 'Издръжливост', 'en' => 'Endurance'], 'priority' => 6,
                ],
                // Sparse by nature - most sellers will not open CrystalDiskInfo.
                // Collected because it is decisive when present, but NOT a facet:
                // filtering on it would hide every honest listing that left it blank.
                'power_on_hours' => [
                    'type' => 'int', 'scope' => 'listing', 'sparse' => true,
                    'label' => ['bg' => 'Часове работа (SMART)', 'en' => 'Power-on hours (SMART)'],
                    'help'  => ['bg' => 'По желание, от CrystalDiskInfo',
                                'en' => 'Optional, from CrystalDiskInfo'],
                    'priority' => 30,
                ],
                'health_percent' => [
                    'type' => 'int', 'scope' => 'listing', 'unit' => '%', 'sparse' => true,
                    'label' => ['bg' => 'Здраве (SMART)', 'en' => 'Health (SMART)'],
                    'priority' => 31,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'monitor' => [
            'label' => ['bg' => 'Монитори', 'en' => 'Monitors'],
            'slug'  => ['bg' => 'monitori', 'en' => 'monitors'],
            'specs' => [
                'size_inch' => [
                    'type' => 'decimal', 'scope' => 'part', 'unit' => '"', 'required' => true,
                    'label' => ['bg' => 'Размер', 'en' => 'Size'],
                    'facet' => 'range', 'range' => [15, 57], 'priority' => 1,
                ],
                'resolution' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Резолюция', 'en' => 'Resolution'],
                    'options' => ['1280x1024','1920x1080','1920x1200','2560x1080','2560x1440',
                                  '3440x1440','3840x2160','5120x1440'],
                    'facet' => 'terms', 'priority' => 2,
                ],
                'refresh_hz' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'Hz',
                    'label' => ['bg' => 'Опресняване', 'en' => 'Refresh rate'],
                    'facet' => 'terms', 'options' => [60,75,100,120,144,165,180,240,360,480],
                    'priority' => 3,
                ],
                'panel_type' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Панел', 'en' => 'Panel'],
                    'options' => ['IPS','VA','TN','OLED','QD-OLED','Mini-LED'],
                    'facet' => 'terms', 'priority' => 4,
                ],
                'response_ms' => [
                    'type' => 'decimal', 'scope' => 'part', 'unit' => 'ms',
                    'label' => ['bg' => 'Време за реакция', 'en' => 'Response time'], 'priority' => 6,
                ],
                'curved' => [
                    'type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'Извит', 'en' => 'Curved'], 'facet' => 'bool', 'priority' => 7,
                ],
                'adaptive_sync' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Adaptive Sync', 'en' => 'Adaptive Sync'],
                    'options' => ['Няма','FreeSync','FreeSync Premium','G-Sync','G-Sync Compatible'],
                    'facet' => 'terms', 'priority' => 5,
                ],
                'dead_pixels' => [
                    'type' => 'select', 'scope' => 'listing', 'required' => true,
                    'label' => ['bg' => 'Дефектни пиксели', 'en' => 'Dead pixels'],
                    'options' => ['Няма','1-2','3 или повече','Не е проверено'],
                    'facet' => 'terms', 'priority' => 8,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'cooler' => [
            'label' => ['bg' => 'Охлаждане', 'en' => 'Cooling'],
            'slug'  => ['bg' => 'ohlazhdane', 'en' => 'cooling'],
            'specs' => [
                'cooler_type' => [
                    'type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Тип', 'en' => 'Type'],
                    'options' => ['Въздушно','AIO водно','Custom loop','Вентилатор','Термопаста'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'sockets' => [
                    'type' => 'multiselect', 'scope' => 'part',
                    'label' => ['bg' => 'Съвместими сокети', 'en' => 'Supported sockets'],
                    'options' => ['AM4','AM5','LGA1151','LGA1200','LGA1700','LGA1851'],
                    'facet' => 'terms', 'priority' => 2,
                ],
                'radiator_mm' => [
                    'type' => 'select', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Радиатор', 'en' => 'Radiator'],
                    'options' => ['120','140','240','280','360','420'],
                    'facet' => 'terms', 'priority' => 3,
                ],
                'height_mm' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Височина', 'en' => 'Height'],
                    'facet' => 'range', 'range' => [20, 200], 'priority' => 4,
                ],
                'has_mounting' => [
                    'type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С монтажен комплект', 'en' => 'Mounting kit included'],
                    'facet' => 'bool', 'priority' => 5,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'case' => [
            'label' => ['bg' => 'Кутии', 'en' => 'Cases'],
            'slug'  => ['bg' => 'kutii', 'en' => 'cases'],
            'specs' => [
                'form_factor' => [
                    'type' => 'multiselect', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Поддържа платки', 'en' => 'Supported boards'],
                    'options' => ['E-ATX','ATX','Micro-ATX','Mini-ITX'],
                    'facet' => 'terms', 'priority' => 1,
                ],
                'max_gpu_mm' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Макс. дължина видеокарта', 'en' => 'Max GPU length'],
                    'facet' => 'range', 'range' => [150, 500], 'priority' => 2,
                ],
                'max_cooler_mm' => [
                    'type' => 'int', 'scope' => 'part', 'unit' => 'мм',
                    'label' => ['bg' => 'Макс. височина охладител', 'en' => 'Max cooler height'],
                    'facet' => 'range', 'range' => [40, 200], 'priority' => 3,
                ],
                'side_panel' => [
                    'type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Страничен панел', 'en' => 'Side panel'],
                    'options' => ['Закалено стъкло','Плексиглас','Метал','Мрежа'],
                    'facet' => 'terms', 'priority' => 4,
                ],
                'included_fans' => [
                    'type' => 'int', 'scope' => 'listing',
                    'label' => ['bg' => 'Включени вентилатори', 'en' => 'Fans included'],
                    'priority' => 5,
                ],
            ],
        ],

        // ---------------------------------------------------------------
        'laptop' => [
            'label' => ['bg' => 'Лаптопи', 'en' => 'Laptops'],
            'slug'  => ['bg' => 'laptopi', 'en' => 'laptops'],
            'specs' => [
                'cpu_model'   => ['type' => 'text',   'scope' => 'part', 'required' => true,
                                  'label' => ['bg' => 'Процесор', 'en' => 'CPU'], 'priority' => 1],
                'gpu_model'   => ['type' => 'text',   'scope' => 'part',
                                  'label' => ['bg' => 'Видеокарта', 'en' => 'GPU'],
                                  'facet' => 'terms', 'priority' => 2],
                'ram_gb'      => ['type' => 'int',    'scope' => 'listing', 'unit' => 'GB',
                                  'label' => ['bg' => 'RAM', 'en' => 'RAM'],
                                  'facet' => 'terms', 'options' => [4,8,16,24,32,64,96],
                                  'priority' => 3],
                'storage_gb'  => ['type' => 'int',    'scope' => 'listing', 'unit' => 'GB',
                                  'label' => ['bg' => 'Диск', 'en' => 'Storage'],
                                  'facet' => 'terms', 'priority' => 4],
                'screen_inch' => ['type' => 'decimal','scope' => 'part', 'unit' => '"',
                                  'label' => ['bg' => 'Екран', 'en' => 'Screen'],
                                  'facet' => 'range', 'range' => [10, 20], 'priority' => 5],
                'refresh_hz'  => ['type' => 'int',    'scope' => 'part', 'unit' => 'Hz',
                                  'label' => ['bg' => 'Опресняване', 'en' => 'Refresh rate'],
                                  'facet' => 'terms', 'priority' => 6],
                'battery_health' => ['type' => 'int', 'scope' => 'listing', 'unit' => '%',
                                  'sparse' => true,
                                  'label' => ['bg' => 'Здраве на батерията', 'en' => 'Battery health'],
                                  'help' => ['bg' => 'По желание, от powercfg /batteryreport',
                                             'en' => 'Optional, from powercfg /batteryreport'],
                                  'priority' => 30],
                'has_charger' => ['type' => 'bool',   'scope' => 'listing',
                                  'label' => ['bg' => 'С оригинално зарядно', 'en' => 'Original charger'],
                                  'facet' => 'bool', 'priority' => 8],
            ],
        ],

        // --- peripherals: lighter schemas, same machinery -----------------
        'keyboard' => [
            'label' => ['bg' => 'Клавиатури', 'en' => 'Keyboards'],
            'slug'  => ['bg' => 'klaviaturi', 'en' => 'keyboards'],
            'specs' => [
                'switch_type' => ['type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Суичове', 'en' => 'Switches'],
                    'options' => ['Механични','Мембранни','Оптични','Hall effect','Ножичен'],
                    'facet' => 'terms', 'priority' => 1],
                'layout' => ['type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Формат', 'en' => 'Layout'],
                    'options' => ['Full-size','96%','TKL','75%','65%','60%','40%'],
                    'facet' => 'terms', 'priority' => 2],
                'connection' => ['type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Свързване', 'en' => 'Connection'],
                    'options' => ['Кабел','Безжична 2.4GHz','Bluetooth','Хибридна'],
                    'facet' => 'terms', 'priority' => 3],
                'cyrillic' => ['type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С кирилица', 'en' => 'Cyrillic legends'],
                    'facet' => 'bool', 'priority' => 4],
            ],
        ],

        'mouse' => [
            'label' => ['bg' => 'Мишки', 'en' => 'Mice'],
            'slug'  => ['bg' => 'mishki', 'en' => 'mice'],
            'specs' => [
                'connection' => ['type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Свързване', 'en' => 'Connection'],
                    'options' => ['Кабел','Безжична 2.4GHz','Bluetooth','Хибридна'],
                    'facet' => 'terms', 'priority' => 1],
                'dpi' => ['type' => 'int', 'scope' => 'part',
                    'label' => ['bg' => 'Максимум DPI', 'en' => 'Max DPI'], 'priority' => 3],
                'weight_g' => ['type' => 'int', 'scope' => 'part', 'unit' => 'г',
                    'label' => ['bg' => 'Тегло', 'en' => 'Weight'],
                    'facet' => 'range', 'range' => [30, 150], 'priority' => 2],
            ],
        ],

        'headset' => [
            'label' => ['bg' => 'Слушалки', 'en' => 'Headsets'],
            'slug'  => ['bg' => 'slushalki', 'en' => 'headsets'],
            'specs' => [
                'connection' => ['type' => 'select', 'scope' => 'part',
                    'label' => ['bg' => 'Свързване', 'en' => 'Connection'],
                    'options' => ['3.5mm','USB','Безжични 2.4GHz','Bluetooth'],
                    'facet' => 'terms', 'priority' => 1],
                'has_mic' => ['type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'С микрофон', 'en' => 'Microphone'],
                    'facet' => 'bool', 'priority' => 2],
                'surround' => ['type' => 'bool', 'scope' => 'part',
                    'label' => ['bg' => 'Съраунд звук', 'en' => 'Surround'], 'priority' => 3],
            ],
        ],

        'console' => [
            'label' => ['bg' => 'Конзоли', 'en' => 'Consoles'],
            'slug'  => ['bg' => 'konzoli', 'en' => 'consoles'],
            'specs' => [
                'platform' => ['type' => 'select', 'scope' => 'part', 'required' => true,
                    'label' => ['bg' => 'Платформа', 'en' => 'Platform'],
                    'options' => ['PlayStation 5','PlayStation 4','Xbox Series X','Xbox Series S',
                                  'Xbox One','Nintendo Switch','Nintendo Switch 2','Steam Deck','Друга'],
                    'facet' => 'terms', 'priority' => 1],
                'storage_gb' => ['type' => 'int', 'scope' => 'part', 'unit' => 'GB',
                    'label' => ['bg' => 'Памет', 'en' => 'Storage'],
                    'facet' => 'terms', 'priority' => 2],
                'controllers' => ['type' => 'int', 'scope' => 'listing',
                    'label' => ['bg' => 'Брой контролери', 'en' => 'Controllers included'],
                    'priority' => 3],
            ],
        ],

        'prebuilt' => [
            'label' => ['bg' => 'Комплектни компютри', 'en' => 'Complete PCs'],
            'slug'  => ['bg' => 'kompyutri', 'en' => 'desktops'],
            'specs' => [
                'cpu_model' => ['type' => 'text', 'scope' => 'listing', 'required' => true,
                    'label' => ['bg' => 'Процесор', 'en' => 'CPU'], 'priority' => 1],
                'gpu_model' => ['type' => 'text', 'scope' => 'listing', 'required' => true,
                    'label' => ['bg' => 'Видеокарта', 'en' => 'GPU'],
                    'facet' => 'terms', 'priority' => 2],
                'ram_gb' => ['type' => 'int', 'scope' => 'listing', 'unit' => 'GB',
                    'label' => ['bg' => 'RAM', 'en' => 'RAM'],
                    'facet' => 'terms', 'options' => [8,16,32,64,128], 'priority' => 3],
                'storage_gb' => ['type' => 'int', 'scope' => 'listing', 'unit' => 'GB',
                    'label' => ['bg' => 'Диск', 'en' => 'Storage'], 'priority' => 4],
                'os_included' => ['type' => 'bool', 'scope' => 'listing',
                    'label' => ['bg' => 'С лицензиран Windows', 'en' => 'Licensed Windows'],
                    'facet' => 'bool', 'priority' => 5],
            ],
        ],

        'other' => [
            'label' => ['bg' => 'Други', 'en' => 'Other'],
            'slug'  => ['bg' => 'drugi', 'en' => 'other'],
            'specs' => [],
        ],
    ],
];
