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
        'manage' => 'Manage',
        'acquired_at' => 'Acquired on',
        'price' => 'Price paid',
        'source' => 'Source',
        'storage' => 'Storage',
        'statuses' => 'Status',
        'tags' => 'Tags',
        'note' => 'Note',
        'save' => 'Save',
        'saved' => 'Saved.',
        'dictionary_empty' => 'Nothing to choose from yet — add entries in',
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
        'currency' => 'Currency',
        'currency_hint' => 'Three-letter code, e.g. EUR. Amounts are stored as whole minor units.',
        'dictionaries' => 'Dictionaries',
        'catalog' => 'Catalog',
        'catalog_items' => 'Items in the catalog',
        'catalog_hint' => 'Imported with the catalog:import command.',
    ],

    // Labels of the seeded statuses, looked up by code so they follow the
    // interface language instead of the language used at install time.
    'dictionaries' => [
        'sources' => 'Sources',
        'sources_hint' => 'Where a copy came from: a shop, a fair, a friend.',
        'storages' => 'Storage',
        'storages_hint' => 'Where a copy physically lives: a shelf, a box, a drawer.',
        'tags' => 'Tags',
        'tags_hint' => 'Free labels. A tag marked "show in list" appears on the card.',
        'statuses' => 'Statuses',
        'statuses_hint' => 'Condition and completeness. Box and Instructions ship with the service and cannot be removed.',
        'statuses_note' => '"Incomplete" and "Missing figures" are not in this list and cannot be added: they are worked out from the copy itself. A copy counts as incomplete once anything that counts toward its contents is marked missing — a spare does not, since it was never required. "Missing figures" appears when one of its minifigures is. Both are recalculated on every change and can be filtered on like any other status.',
        'new' => 'New entry',
        'remove' => 'Delete',
        'remove_confirm' => 'Delete :name?',
        'show_in_list' => 'Show on card',
        'active' => 'Active',
        'system' => 'Part of the service; cannot be deleted.',
        'system_undeletable' => 'This entry ships with the service and cannot be deleted.',
        'in_use' => 'Still used by :count item(s) in the collection.',
    ],

    'status' => [
        'box' => 'Box',
        'manual' => 'Instructions',
    ],

    'errors' => [
        'save_failed' => 'Could not save. The server did not answer.',
    ],

    'locale' => [
        'label' => 'Language',
        'en' => 'English',
        'ru' => 'Русский',
    ],

    'parts_counted' => '{0} No parts|{1} :count part|[2,*] :count parts',
];
