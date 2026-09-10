<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal dictionaries (ref_*) and application settings. Unlike bl_*, the user
 * edits these through the interface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('ref_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('ref_storages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('ref_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Bootstrap contextual class: primary, secondary, success, ...
            $table->string('color')->default('secondary');
            $table->boolean('show_in_list')->default(false);
            $table->integer('sort')->default(0);
        });

        Schema::create('ref_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Set for seeded entries ("box", "manual"). Their label is read
            // from translation files by this code, not from the name column,
            // or it would stay in the language used at install time.
            $table->string('code')->nullable()->unique();
            $table->boolean('is_system')->default(false);
            $table->integer('sort')->default(0);
        });

        // Buttons to external catalogs shown on item detail pages. Six fixed
        // rows, seeded by RefLinksSeeder; the user edits but cannot add or
        // remove them.
        Schema::create('ref_links', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('label')->nullable();
            // An empty pattern is how "not applicable to this item type" is
            // expressed: the button simply is not rendered. That is why the
            // instructions row needs no separate flag.
            $table->string('url_set')->nullable();
            $table->string('url_minifig')->nullable();
            $table->string('url_part')->nullable();
            $table->integer('sort')->default(0);
        });
    }

    public function down(): void
    {
        foreach ([
            'ref_links', 'ref_statuses', 'ref_tags',
            'ref_storages', 'ref_sources', 'settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
