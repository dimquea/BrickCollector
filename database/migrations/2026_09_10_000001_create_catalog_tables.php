<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BrickLink reference data (bl_*), filled by the importer and never written to
 * by the application.
 *
 * Written as raw SQL rather than through the Schema builder: it cannot express
 * WITHOUT ROWID or FTS5, and keeping the whole set in one dialect makes it
 * comparable to the schema in the design document line by line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE bl_meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        ) WITHOUT ROWID');

        DB::statement('CREATE TABLE bl_item_types (
            code TEXT PRIMARY KEY,
            name TEXT NOT NULL
        ) WITHOUT ROWID');

        DB::statement('CREATE TABLE bl_colors (
            id        INTEGER PRIMARY KEY,
            name      TEXT NOT NULL,
            rgb       TEXT,
            type      TEXT,
            ldraw_rgb TEXT,
            year_from INTEGER,
            year_to   INTEGER
        )');

        DB::statement('CREATE TABLE bl_categories (
            id   INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )');

        // Theme tree, rebuilt from the "Town / City / Airport" paths in
        // items/*.csv. categories.xml only carries the root name.
        DB::statement('CREATE TABLE bl_themes (
            id               INTEGER PRIMARY KEY,
            parent_id        INTEGER REFERENCES bl_themes(id),
            root_category_id INTEGER REFERENCES bl_categories(id),
            name             TEXT NOT NULL,
            path             TEXT NOT NULL UNIQUE,
            depth            INTEGER NOT NULL
        )');
        DB::statement('CREATE INDEX ix_themes_parent ON bl_themes(parent_id)');

        DB::statement('CREATE TABLE bl_items (
            type           TEXT NOT NULL REFERENCES bl_item_types(code),
            id             TEXT NOT NULL,
            name           TEXT NOT NULL,
            category_id    INTEGER REFERENCES bl_categories(id),
            theme_id       INTEGER REFERENCES bl_themes(id),
            year           INTEGER,
            weight         REAL,
            image_color_id INTEGER,
            has_inventory  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (type, id)
        ) WITHOUT ROWID');
        DB::statement('CREATE INDEX ix_items_theme ON bl_items(type, theme_id)');
        DB::statement('CREATE INDEX ix_items_year  ON bl_items(type, year)');

        // Standalone, not content='bl_items': external-content FTS needs a
        // rowid and bl_items has none. Rebuilding such an index fails with
        // "SQL logic error". Carrying type and item_id unindexed lets a search
        // return the key without joining back.
        //
        // ident holds the item number, indexed. People search the catalog by
        // number at least as often as by name, and having both in one index
        // means one MATCH, one ranking, and no union of two result sets.
        DB::statement("CREATE VIRTUAL TABLE bl_items_fts USING fts5(
            name,
            ident,
            type    UNINDEXED,
            item_id UNINDEXED,
            tokenize='unicode61'
        )");

        DB::statement('CREATE TABLE bl_item_alt_ids (
            type   TEXT NOT NULL,
            id     TEXT NOT NULL,
            alt_id TEXT NOT NULL
        )');
        DB::statement('CREATE INDEX ix_alt_ids ON bl_item_alt_ids(alt_id)');

        // ~1.5M rows.
        DB::statement('CREATE TABLE bl_inventory (
            parent_type    TEXT NOT NULL,
            parent_id      TEXT NOT NULL,
            child_type     TEXT NOT NULL,
            child_id       TEXT NOT NULL,
            color_id       INTEGER NOT NULL,
            qty            INTEGER NOT NULL,
            is_extra       INTEGER NOT NULL DEFAULT 0,
            is_alternate   INTEGER NOT NULL DEFAULT 0,
            match_id       INTEGER NOT NULL DEFAULT 0,
            is_counterpart INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement('CREATE INDEX ix_inv_parent ON bl_inventory(parent_type, parent_id)');
        DB::statement('CREATE INDEX ix_inv_child  ON bl_inventory(child_type, child_id, color_id)');

        // LEGO element ids. part_color_codes.xml names the colour as a string;
        // the importer resolves it to an id so nothing joins on text later.
        DB::statement('CREATE TABLE bl_element_codes (
            item_type TEXT NOT NULL,
            item_id   TEXT NOT NULL,
            color_id  INTEGER,
            code      TEXT NOT NULL
        )');
        DB::statement('CREATE INDEX ix_codes_code ON bl_element_codes(code)');
        DB::statement('CREATE INDEX ix_codes_item ON bl_element_codes(item_type, item_id, color_id)');

        // btchglog.csv. Needed to migrate user references when the catalog
        // renames or merges an item.
        DB::statement('CREATE TABLE bl_changelog (
            id         INTEGER PRIMARY KEY,
            changed_at TEXT,
            kind       TEXT,
            from_type  TEXT,
            from_id    TEXT,
            to_type    TEXT,
            to_id      TEXT
        )');
        DB::statement('CREATE INDEX ix_chg_from ON bl_changelog(from_type, from_id)');

        // Snapshot of the zip central directory: name -> CRC32. Lets an update
        // re-import only the entries that actually changed.
        DB::statement('CREATE TABLE bl_zip_index (
            name  TEXT PRIMARY KEY,
            crc32 INTEGER NOT NULL
        ) WITHOUT ROWID');
    }

    public function down(): void
    {
        foreach ([
            'bl_zip_index', 'bl_changelog', 'bl_element_codes', 'bl_inventory',
            'bl_item_alt_ids', 'bl_items_fts', 'bl_items', 'bl_themes',
            'bl_categories', 'bl_colors', 'bl_item_types', 'bl_meta',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
