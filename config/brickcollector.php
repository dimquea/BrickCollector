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
