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

    'item' => [
        'type' => 'Type',
        'weight' => 'Weight',
        'grams' => 'g',
        'parts' => 'Parts',
        'lots' => 'Lots',
        'minifigures' => 'Minifigures',
        'subsets' => 'Subsets',
        'element_codes' => 'LEGO element numbers',
        'no_inventory' => 'The catalog has no contents listed for this item.',
        'open' => 'Open',
    ],

    'lot' => [
        'item' => 'Item',
        'color' => 'Colour',
        'qty' => 'Qty',
        'extra' => 'Spare',
        'extra_hint' => 'A spare included on top; not counted.',
        'alternate' => 'Alternate',
        'alternate_hint' => 'An alternative to another lot; only one of them is present.',
        'counterpart' => 'Counterpart',
        'counterpart_hint' => 'Paired with another lot; not counted separately.',
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
