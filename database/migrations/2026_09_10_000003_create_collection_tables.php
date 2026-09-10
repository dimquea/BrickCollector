<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The collection: what the user owns.
 *
 * Split in two on purpose. collection_entries is "what was acquired" — one row
 * per physical instance, carrying the metadata. collection_items is "what it
 * consists of" — a tree, because a set contains minifigures and subsets which
 * contain parts of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL: the CHECK spans several columns, which the Schema builder
        // cannot express.
        DB::statement('CREATE TABLE collection_entries (
            id                INTEGER PRIMARY KEY,
            item_type         TEXT REFERENCES bl_item_types(code),
            item_id           TEXT,
            color_id          INTEGER,
            name              TEXT,
            acquired_at       TEXT,
            price             INTEGER,
            source_id         INTEGER REFERENCES ref_sources(id),
            storage_id        INTEGER REFERENCES ref_storages(id),
            note              TEXT,
            flag_incomplete   INTEGER NOT NULL DEFAULT 0,
            flag_missing_figs INTEGER NOT NULL DEFAULT 0,
            created_at        TEXT,
            updated_at        TEXT,

            -- A catalog item needs an id; a user-made assembly has no catalog
            -- counterpart and carries a name of its own instead.
            CHECK (
                (item_type IS NULL     AND item_id IS NULL     AND name IS NOT NULL)
                OR
                (item_type IS NOT NULL AND item_id IS NOT NULL)
            )
        )');
        DB::statement('CREATE INDEX ix_entries_item ON collection_entries(item_type, item_id)');

        DB::statement('CREATE TABLE collection_items (
            id             INTEGER PRIMARY KEY,
            entry_id       INTEGER NOT NULL REFERENCES collection_entries(id) ON DELETE CASCADE,
            parent_id      INTEGER REFERENCES collection_items(id) ON DELETE CASCADE,
            item_type      TEXT NOT NULL REFERENCES bl_item_types(code),
            item_id        TEXT NOT NULL,
            color_id       INTEGER NOT NULL,
            qty            INTEGER NOT NULL,
            lost_qty       INTEGER NOT NULL DEFAULT 0,

            is_extra       INTEGER NOT NULL DEFAULT 0,
            is_alternate   INTEGER NOT NULL DEFAULT 0,
            match_id       INTEGER NOT NULL DEFAULT 0,
            is_counterpart INTEGER NOT NULL DEFAULT 0,

            -- Spares, alternates and counterparts are attached to the instance
            -- but must not be counted. One generated column instead of
            -- repeating three conditions in every query.
            counts INTEGER GENERATED ALWAYS AS (
                CASE WHEN is_extra OR is_alternate OR is_counterpart THEN 0 ELSE 1 END
            ) STORED,

            -- Type of the immediate parent, NULL at the root of an instance.
            -- Denormalised so listings can tell "in sets" from "in minifigures"
            -- without walking the tree.
            parent_item_type TEXT REFERENCES bl_item_types(code)
        )');
        DB::statement('CREATE INDEX ix_ci_entry  ON collection_items(entry_id)');
        DB::statement('CREATE INDEX ix_ci_parent ON collection_items(parent_id)');
        DB::statement('CREATE INDEX ix_ci_item   ON collection_items(item_type, item_id, color_id, counts)');

        Schema::create('entry_tags', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('collection_entries')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('ref_tags')->cascadeOnDelete();
            $table->primary(['entry_id', 'tag_id']);
        });

        Schema::create('entry_statuses', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('collection_entries')->cascadeOnDelete();
            $table->foreignId('status_id')->constrained('ref_statuses')->cascadeOnDelete();
            $table->primary(['entry_id', 'status_id']);
        });

        // Cached item images. path is relative to the cache root: the root is
        // configuration and moves when the deployment changes.
        DB::statement('CREATE TABLE image_cache (
            item_type  TEXT NOT NULL,
            item_id    TEXT NOT NULL,
            color_id   INTEGER NOT NULL,
            path       TEXT,
            status     TEXT,
            fetched_at TEXT,
            PRIMARY KEY (item_type, item_id, color_id)
        ) WITHOUT ROWID');
    }

    public function down(): void
    {
        foreach ([
            'image_cache', 'entry_statuses', 'entry_tags',
            'collection_items', 'collection_entries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
