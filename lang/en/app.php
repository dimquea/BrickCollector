<?php

return [
    'name' => 'BrickCollector',

    'nav' => [
        'collection' => 'Collection',
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
        'lost' => 'Missing',
        'extra' => 'Spare',
        'extra_hint' => 'A spare included on top; not counted.',
        'alternate' => 'Alternate',
        'alternate_hint' => 'An alternative to another lot; only one of them is present.',
        'counterpart' => 'Counterpart',
        'counterpart_hint' => 'Paired with another lot; not counted separately.',
    ],

    'collection' => [
        'title' => 'Collection',
        'add' => 'Add to collection',
        'added' => ':name added to the collection.',
        'removed' => 'Removed from the collection.',
        'remove' => 'Remove from the collection',
        'remove_confirm' => 'Remove :name from the collection? Its contents go with it.',
        'entries' => 'Items owned',
        'sets' => 'Sets',
        'lost' => 'Missing',
        'incomplete' => 'Incomplete',
        'incomplete_hint' => 'Something that counts is marked missing.',
        'missing_figs' => 'Missing figures',
        'missing_figs_hint' => 'A minifigure of this set is marked missing.',
        'open_in_catalog' => 'Open in catalog',
        'danger_zone' => 'Danger zone',
        'empty' => 'Nothing here yet.',
        'empty_hint' => 'Find something in the catalog.',
        'parts_badge' => '{0} no parts|{1} :count part|[2,*] :count parts',
        'figures_badge' => '{0} no minifigures|{1} :count minifigure|[2,*] :count minifigures',
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
