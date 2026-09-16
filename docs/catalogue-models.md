# Каталог — точните имена за колоната `model`

Справка за `remarket:import-listings`. **Съвпадението е точно или никакво** — няма
разпознаване „по подобие", защото нищо не чете резултата от един импорт и една
сгрешена връзка закача триста обяви за грешен модел, с грешен ценови диапазон и
грешни характеристики, без нито една страница да изглежда счупена.

Затова: копирай низа оттук, не го пиши на ръка.

Ако низът не съвпадне с нищо, редът **не се проваля** — текстът отива в
`custom_part` и излиза в опашката на `/katalog`, точно както ако продавач го беше
написал в съветника. Това е приемлив резултат, но е втори избор: обявата няма да
се появи на страницата на модела и няма да участва във филтрите по
характеристики, докато не я промотираш.

Освен самото име, съвпадат и **производител + модел** и всеки от псевдонимите,
които сийдърите вече знаят (включително кирилските).

---

## `gpu` — Видеокарти (56)
Колони за бройката: `spec:backplate`  (да/не) · `spec:repadded`  (да/не)
```
GeForce RTX 5090
GeForce RTX 5080
GeForce RTX 5070 Ti
GeForce RTX 5070
GeForce RTX 5060 Ti
GeForce RTX 5060
GeForce RTX 4090
GeForce RTX 4080 SUPER
GeForce RTX 4080
GeForce RTX 4070 Ti SUPER
GeForce RTX 4070 Ti
GeForce RTX 4070 SUPER
GeForce RTX 4070
GeForce RTX 4060 Ti 16GB
GeForce RTX 4060 Ti
GeForce RTX 4060
GeForce RTX 3090 Ti
GeForce RTX 3090
GeForce RTX 3080 Ti
GeForce RTX 3080 12GB
GeForce RTX 3080
GeForce RTX 3070 Ti
GeForce RTX 3070
GeForce RTX 3060 Ti
GeForce RTX 3060 12GB
GeForce RTX 3050
GeForce RTX 2080 Ti
GeForce RTX 2070 SUPER
GeForce RTX 2060
GeForce GTX 1660 SUPER
GeForce GTX 1650
GeForce GTX 1080 Ti
GeForce GTX 1080
GeForce GTX 1070
GeForce GTX 1060 6GB
GeForce GTX 1050 Ti
Radeon RX 9070 XT
Radeon RX 9070
Radeon RX 9060 XT 16GB
Radeon RX 7900 XTX
Radeon RX 7900 XT
Radeon RX 7900 GRE
Radeon RX 7800 XT
Radeon RX 7700 XT
Radeon RX 7600
Radeon RX 6950 XT
Radeon RX 6800 XT
Radeon RX 6800
Radeon RX 6700 XT
Radeon RX 6650 XT
Radeon RX 6600
Radeon RX 5700 XT
Radeon RX 580 8GB
Arc B580
Arc A770 16GB
Arc A750
```

## `cpu` — Процесори (46)
Колони за бройката: `spec:has_cooler`  (да/не) · `spec:delidded`  (да/не)
```
Ryzen 9 9950X3D
Ryzen 7 9800X3D
Ryzen 7 9700X
Ryzen 5 9600X
Ryzen 9 7950X3D
Ryzen 7 7800X3D
Ryzen 9 7950X
Ryzen 9 7900X
Ryzen 7 7700X
Ryzen 5 7600X
Ryzen 5 7600
Ryzen 7 8700G
Ryzen 7 5800X3D
Ryzen 9 5950X
Ryzen 9 5900X
Ryzen 7 5800X
Ryzen 7 5700X3D
Ryzen 7 5700X
Ryzen 5 5600X
Ryzen 5 5600
Ryzen 5 5500
Ryzen 7 3700X
Ryzen 5 3600
Ryzen 5 2600
Core Ultra 9 285K
Core Ultra 7 265K
Core Ultra 5 245K
Core i9-14900K
Core i7-14700K
Core i5-14600K
Core i9-13900K
Core i7-13700K
Core i5-13600K
Core i5-13400F
Core i9-12900K
Core i7-12700K
Core i5-12600K
Core i5-12400F
Core i7-11700K
Core i7-10700K
Core i5-10400F
Core i9-9900K
Core i7-9700K
Core i7-8700K
Core i5-8400
Core i7-7700K
```

## `motherboard` — Дънни платки (20)
Колони за бройката: `spec:has_io_shield`  (да/не)
```
MSI B450 TOMAHAWK MAX
MSI MAG B550 TOMAHAWK
ASUS ROG STRIX B550-F GAMING
ASUS TUF GAMING X570-PLUS
Gigabyte B450M DS3H
ASRock B450M PRO4
MSI MAG B650 TOMAHAWK WIFI
ASUS ROG STRIX B650E-F GAMING WIFI
ASRock B650M PG Riptide
ASUS ROG STRIX X670E-E GAMING WIFI
Gigabyte B850 AORUS ELITE WIFI7
MSI MAG B760 TOMAHAWK WIFI
MSI PRO B760M-A WIFI
ASUS PRIME Z790-P
Gigabyte B660M DS3H DDR4
ASUS ROG STRIX Z690-A GAMING WIFI D4
MSI MAG B560 TOMAHAWK WIFI
ASUS PRIME B460M-A
Gigabyte Z490 AORUS ELITE AC
ASUS ROG STRIX Z890-A GAMING WIFI
```

## `ram` — Памет (12)
```
Corsair Vengeance LPX 16GB (2x8) 3200 C16
Corsair Vengeance LPX 32GB (2x16) 3600 C18
G.Skill Ripjaws V 16GB (2x8) 3600 C16
G.Skill Trident Z RGB 32GB (2x16) 3600 C16
Kingston FURY Beast 16GB (2x8) 3200 C16
Crucial Ballistix 32GB (2x16) 3200 C16
Corsair Vengeance 32GB (2x16) DDR5 6000 C30
G.Skill Trident Z5 Neo RGB 32GB (2x16) 6000 C30
Kingston FURY Beast 32GB (2x16) DDR5 6000 C36
G.Skill Flare X5 32GB (2x16) DDR5 6000 C30
Corsair Vengeance 64GB (2x32) DDR5 6000 C30
Kingston FURY Renegade 64GB (4x16) DDR5 6000 C32
```

## `psu` — Захранвания (13)
Колони за бройката: `spec:has_all_cables`  (да/не)
```
Seasonic FOCUS GX-650
Seasonic FOCUS GX-750
Seasonic VERTEX GX-850
Corsair RM750x
Corsair RM850x SHIFT
Corsair CX650M
be quiet! Pure Power 12 M 750W
be quiet! Straight Power 11 650W
MSI MAG A650BN
MSI MPG A850G PCIE5
Cooler Master MWE Gold 750 V2
Corsair SF750
Seasonic PRIME TX-1000
```

## `storage` — Дискове (16)
Колони за бройката: `spec:power_on_hours`  (цяло число) · `spec:health_percent`  (цяло число)
```
Samsung 990 PRO 1TB
Samsung 990 PRO 2TB
Samsung 980 PRO 1TB
Samsung 970 EVO Plus 1TB
Samsung 870 EVO 1TB
WD Black SN850X 1TB
WD Black SN850X 2TB
WD Blue SN570 1TB
Crucial P3 Plus 1TB
Crucial MX500 1TB
Kingston NV2 1TB
Kingston A400 480GB
Seagate BarraCuda 2TB
Seagate IronWolf 4TB
WD Red Plus 4TB
Toshiba P300 2TB
```

## `monitor` — Монитори (15)
Колони за бройката: `spec:dead_pixels`  (Няма | 1-2 | 3 или повече | Не е проверено)
```
LG 27GP850-B
LG 27GL850-B
LG 24GN650-B
Samsung Odyssey G5 C27G55T
Samsung Odyssey G7 C32G75T
Samsung Odyssey OLED G8 G85SB
AOC 24G2U
AOC CQ27G2U
Dell S2721DGF
Dell U2720Q
ASUS TUF Gaming VG27AQ
ASUS ROG Swift PG27AQDM
MSI Optix MAG274QRF-QD
BenQ ZOWIE XL2411K
Philips 242E1GAJ
```

## `cooler` — Охлаждане (14)
Колони за бройката: `spec:has_mounting`  (да/не)
```
Noctua NH-D15
Noctua NH-U12S redux
Noctua NH-L9i
be quiet! Dark Rock Pro 4
be quiet! Pure Rock 2
Thermalright Peerless Assassin 120 SE
DeepCool AK620
Cooler Master Hyper 212 EVO
Arctic Liquid Freezer II 240
Arctic Liquid Freezer II 360
Corsair iCUE H100i ELITE CAPELLIX
Corsair iCUE H150i ELITE CAPELLIX
NZXT Kraken X63
DeepCool LT720
```

## `case` — Кутии (11)
Колони за бройката: `spec:included_fans`  (цяло число)
```
Fractal Design North
Fractal Design Meshify 2 Compact
Fractal Design Define R5
NZXT H510
NZXT H7 Flow
Lian Li O11 Dynamic
Lian Li Lancool 216
Corsair 4000D Airflow
be quiet! Pure Base 500DX
Cooler Master MasterBox NR200P
Phanteks Eclipse P400A
```

## `keyboard` — Клавиатури (11)
Колони за бройката: `spec:cyrillic`  (да/не)
```
Logitech G Pro X TKL
Logitech G413
Keychron K2 V2
Keychron Q1
Razer BlackWidow V3
Razer Huntsman Mini
SteelSeries Apex Pro TKL
Corsair K70 RGB MK.2
HyperX Alloy Origins Core
Ducky One 2 Mini
Logitech K120
```

## `mouse` — Мишки (10)
```
Logitech G Pro X Superlight
Logitech G Pro X Superlight 2
Logitech G502 HERO
Logitech G305
Razer DeathAdder V3 Pro
Razer Viper V2 Pro
Razer DeathAdder Essential
SteelSeries Rival 3
Glorious Model O
Corsair M65 RGB Elite
```

## `headset` — Слушалки (9)
```
HyperX Cloud II
HyperX Cloud Alpha
SteelSeries Arctis 7
SteelSeries Arctis Nova Pro Wireless
Logitech G Pro X
Logitech G435
Razer BlackShark V2
Corsair HS60
Sennheiser HD 560S
```

## `console` — Конзоли (14)
Колони за бройката: `spec:controllers`  (цяло число)
```
PlayStation 5
PlayStation 5 Digital Edition
PlayStation 5 Slim
PlayStation 5 Pro
PlayStation 4 Slim
PlayStation 4 Pro
Xbox Series X
Xbox Series S
Xbox One S
Switch OLED
Switch Lite
Switch 2
Steam Deck OLED 512GB
Steam Deck 256GB
```

## `iphone` — iPhone (25)
Колони за бройката: `spec:battery_health`  (цяло число) · `spec:icloud_signed_out`  (да/не) · `spec:network_locked`  (да/не) · `spec:parts_status`  (Всички оригинални | Сменен екран | Сменена батерия | Сменени няколко части | Не знам) · `spec:biometrics_work`  (да/не) · `spec:imei_provided`  (да/не) · `spec:box_included`  (да/не)
```
iPhone 11
iPhone 11 Pro
iPhone 11 Pro Max
iPhone SE (2020)
iPhone 12 mini
iPhone 12
iPhone 12 Pro
iPhone 12 Pro Max
iPhone 13 mini
iPhone 13
iPhone 13 Pro
iPhone 13 Pro Max
iPhone SE (2022)
iPhone 14
iPhone 14 Plus
iPhone 14 Pro
iPhone 14 Pro Max
iPhone 15
iPhone 15 Plus
iPhone 15 Pro
iPhone 15 Pro Max
iPhone 16
iPhone 16 Plus
iPhone 16 Pro
iPhone 16 Pro Max
```

## `ipad` — iPad (13)
Колони за бройката: `spec:icloud_signed_out`  (да/не) · `spec:battery_health`  (цяло число) · `spec:parts_status`  (както при iPhone) · `spec:pencil_included`  (да/не) · `spec:box_included`  (да/не)
```
iPad (9-то поколение)
iPad (10-то поколение)
iPad Air 4
iPad Air 5
iPad Air 11" (M2)
iPad Air 13" (M2)
iPad mini 6
iPad Pro 11" (M1)
iPad Pro 12.9" (M1)
iPad Pro 11" (M2)
iPad Pro 12.9" (M2)
iPad Pro 11" (M4)
iPad Pro 13" (M4)
```

## `macbook` — MacBook (22)
Колони за бройката: `spec:battery_cycles`  (цяло число) · `spec:battery_health`  (цяло число) · `spec:icloud_signed_out`  (да/не) · `spec:mdm_free`  (да/не) · `spec:parts_status`  (… | Сменена клавиатура | …) · `spec:charger_included`  (да/не)
```
MacBook Air 13" (Intel)
MacBook Pro 13" (Intel)
MacBook Pro 16" (Intel)
MacBook Air 13" (M1)
MacBook Pro 13" (M1)
MacBook Pro 14" (M1 Pro)
MacBook Pro 16" (M1 Pro)
MacBook Pro 14" (M1 Max)
MacBook Pro 16" (M1 Max)
MacBook Air 13" (M2)
MacBook Pro 13" (M2)
MacBook Air 15" (M2)
MacBook Pro 14" (M2 Pro)
MacBook Pro 16" (M2 Pro)
MacBook Pro 14" (M3)
MacBook Pro 14" (M3 Pro)
MacBook Pro 16" (M3 Pro)
MacBook Air 13" (M3)
MacBook Air 15" (M3)
MacBook Pro 14" (M4)
MacBook Pro 14" (M4 Pro)
MacBook Pro 16" (M4 Pro)
```

## Категории без каталог

`laptop`, `prebuilt` и `other` нямат каталожни редове и **това е нарочно** —
всяка характеристика на сглобен компютър е на бройката, при лаптопите е почти
същото, а ред на SKU би бил огромен и пак нямаше да покрие повечето обяви.

Остави `model` празно за тях и попълни колоните за бройката:

- `laptop` — `spec:ram_gb` · `spec:storage_gb` · `spec:battery_health` · `spec:has_charger`
- `prebuilt` — `spec:cpu_model` · `spec:gpu_model` · `spec:ram_gb` · `spec:storage_gb` · `spec:os_included`

## Apple — конфигурацията е моделът

`iPhone 13 Pro` е **четири** каталожни реда, по един на капацитет, защото паметта
е запоена и е по-голямата част от цената. Низът в `model` е само името
(„iPhone 13 Pro"); капацитетът се подава през `spec:`-колоните и през варианта,
който импортът избира. Ако продаваш конкретен капацитет и искаш да се закачи за
точния ред, добави го в заглавието и провери резултата от `--dry-run`.

**`spec:icloud_signed_out` го попълвай винаги** за трите Apple категории. Не е
задължително технически, но телефон, заключен за чужд Apple ID, е тухла — и
празната стойност става „без отговор" в списъка за проверка на купувача.

---

Общо 307 каталожни имена. Генерирано от сийдърите на 16.09.2026.
