<?php

use App\Support\DataPath;

/*
 * Every piece of mutable state lives under one configurable root so the whole
 * lot can be moved out of the container when running as a Home Assistant
 * add-on. Nothing in the application may hardcode these paths: read them here.
 */

return [

    'data_path' => DataPath::root(),

    'archive_path' => DataPath::archive(),

    'image_cache_path' => DataPath::imageCache(),

    /*
     * Currency prices are shown in. Amounts are always stored as integer minor
     * units; this only decides how they are rendered. The user can change it
     * in settings, which is where the value actually lives — this is only the
     * default for a fresh installation.
     */
    'currency' => env('BRICKCOLLECTOR_CURRENCY', 'RUB'),

    /*
     * Верить ли заголовку X-Ingress-Path. Включает его аддон Home Assistant,
     * где наружу не выставлено ни одного порта и подделать заголовок некому.
     * В обычной установке приложение доступно напрямую, и доверять ему нельзя:
     * им переписывается корень всех ссылок на странице.
     */
    'trust_ingress' => env('BRICKCOLLECTOR_TRUST_INGRESS', false),

    /*
     * Списки: сколько строк на странице и какого размера карточки. Это
     * значения по умолчанию — выбор пользователя живёт в таблице настроек,
     * потому что он должен переживать пересборку аддона.
     *
     * «Маленькая» карточка — вдвое больше колонок на той же ширине. Размер
     * задаётся отдельно для мобильного и настольного: на телефоне в две колонки
     * влезает то, что на мониторе в восемь.
     */
    'lists' => [
        'catalog' => ['per_page' => 48, 'cards' => true],
        'sets' => ['per_page' => 24, 'cards' => true],
        'minifigures' => ['per_page' => 24, 'cards' => true],
        'assemblies' => ['per_page' => 24, 'cards' => true],
        // Детали показываются таблицей, размер карточки к ним не применим.
        'parts' => ['per_page' => 50, 'cards' => false],
    ],

    /*
     * Чем запускать artisan для фоновой работы.
     *
     * Угадать нельзя: под mod_php константа PHP_BINARY указывает на сам Apache,
     * а PHP_BINDIR — на каталог времени сборки, которого на машине может и не
     * быть. По умолчанию полагаемся на PATH; там, где php лежит в стороне, путь
     * задаётся переменной окружения.
     */
    'php_binary' => env('BRICKCOLLECTOR_PHP_BINARY', 'php'),

    /* Where catalog releases are fetched from. */
    'release_url' => env(
        'BRICKCOLLECTOR_RELEASE_URL',
        'https://github.com/rgriebl/brickstore-database/releases/latest/download/downloads.zip'
    ),

    /*
     * Pattern for BrickLink item images. Confirmed against BrickStore's
     * src/bricklink/picture.cpp.
     */
    'image_url' => env(
        'BRICKCOLLECTOR_IMAGE_URL',
        'https://img.bricklink.com/ItemImage/{type}N/{color}/{id}.png'
    ),

];
