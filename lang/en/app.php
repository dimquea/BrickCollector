<?php

return [
    'name' => 'BrickCollector',

    'nav' => [
        'catalog' => 'Catalog',
        'sets' => 'Sets',
        'parts' => 'Parts',
        'minifigures' => 'Minifigures',
        'analytics' => 'Analytics',
        'settings' => 'Settings',
    ],

    'home' => [
        'title' => 'Welcome',
        'tagline' => 'Self-hosted LEGO collection manager.',
        'catalog_empty' => 'The catalog has not been imported yet.',
    ],

    'catalog' => [
        'title' => 'Catalog',
        'query' => 'Search',
        'query_hint' => 'Name or item number',
        'type' => 'Type',
        'theme' => 'Theme',
        'year' => 'Year',
        'any' => 'Any',
        'reset' => 'Reset',
        'only_with_inventory' => 'Only items with a known inventory',
        'has_inventory' => 'Inventory available',
        'nothing_found' => 'Nothing matched those filters.',
        'found' => '{0} nothing found|{1} :count item|[2,*] :count items',
    ],

    'settings' => [
        'title' => 'Settings',
        'appearance' => 'Appearance',
        'appearance_hint' => 'Interface language and display options.',
        'saved' => 'Saved.',
    ],

    'locale' => [
        'label' => 'Language',
        'en' => 'English',
        'ru' => 'Русский',
    ],

    'parts_counted' => '{0} No parts|{1} :count part|[2,*] :count parts',
];
