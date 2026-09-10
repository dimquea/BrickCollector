# BrickCollector

Selfhosted LEGO collection manager. The catalog comes from
[rgriebl/brickstore-database](https://github.com/rgriebl/brickstore-database) releases, which
republish the BrickLink catalog hourly.

Designed to run on a closed network or behind an access-controlling facade, and to be deployed as a
Home Assistant add-on.

## Design documents

The design document and the data-format research live **outside this repository**, on the
maintainer's machine:

- `disign_doc.md` — requirements, database
  schema, conventions (written in Russian)
- `bricklink_catalog_research.md` — data
  formats, measurements, gotchas
- `db/downloads.zip` — catalog archive, used as
  an import fixture

Read them before touching the database schema or the importer: every decision and every measured
number is recorded there. Change the schema in the design document first, then in code.

## Local environment

| | |
|-|-|
| URL | http://127.0.0.1:82 (vhost `brickcollector.local`) |
| DocumentRoot | `<project>/public` |
| PHP | 8.2.4, Apache 2.4.56 (XAMPP), `php.ini` |
| CLI | `php`, `composer` 2.5.8, `node` 22.16, `npm` 10.9.2 |

Xdebug slows the CLI down and floods the output with warnings — run scripts and benchmarks with
`php -d xdebug.mode=off`.

**Composer needs `COMPOSER_IPRESOLVE=4` on this network.** `repo.packagist.org` publishes an AAAA
record, IPv6 does not route from here, and Composer hangs through the TLS handshake until it times
out with a confusing SSL error. Prefix every Composer command:
`COMPOSER_IPRESOLVE=4 composer require ...`.

Editing `php.ini` or the vhost requires an Apache restart. **The user restarts Apache**, never
automate it: other local sites share the same instance.

**Do not cache the configuration on this machine.** `php artisan config:cache` makes `RefreshDatabase`
point at the real database file instead of the `:memory:` one from `phpunit.xml`, and the test suite
then wipes the imported catalog and the collection. That has happened once already; `tests/TestCase`
now refuses to run unless the connection is in-memory. Caching is right for the add-on image, where
it is built once and nothing runs tests.

Two things follow from that, and they matter wherever the cache does exist:

- **`env()` returns null outside config files** once the configuration is cached. Application code
  reads `config('brickcollector.*')`; `App\Support\DataPath` is a config-time helper and nothing
  else may call it.
- **Reading `.env` on every request does not survive concurrency on Windows.** Two dozen simultaneous
  requests and some of them fail to open the file, fall back to framework defaults, and die with "No
  application encryption key has been specified". The fix was not to cache the config but to stop
  making the requests: a listing renders 48 cards and each was fetching its own placeholder. Pages
  now ask once, in bulk, and draw the placeholder client-side.

## Stack and its boundaries

Laravel · Inertia.js · Vue 3 · Bootstrap 5 · Material Design Icons · SQLite · Vite.

- **There is no authentication.** The service targets a closed network or an external facade. Do not
  add Breeze, Jetstream, Sanctum, guards, or `auth` middleware.
- **No jQuery.**
- **No separate REST API.** Inertia hands data to the pages. Do not create `routes/api.php` without a
  real external consumer.
- **Minimal dependencies.** A new entry in `composer.json` or `package.json` needs an explicit
  justification for why the job cannot be done without it. This is a product requirement (Home
  Assistant add-on), not a matter of taste.

## Code layout

Two domain modules. The boundary between them is the single most important architectural decision in
this project:

```
app/
  Catalog/            <- BrickLink reference data, read-only to the application
    Models/           Item, Color, Category, Theme, Inventory, ElementCode, Changelog
    Queries/          non-trivial reads (search, inventory expansion)
    Import/           zip parsing, populates bl_*
  Collection/         <- user data, the only thing ever written
    Models/           Entry, Item, Tag, Status, Source, Storage
    Actions/          operations: AddSetToCollection, MarkPartLost, ...
    Queries/          aggregates for listings
  Http/
    Controllers/      thin: validate -> Action/Query -> Inertia::render
    Requests/
resources/js/
  Pages/              Inertia pages, mirroring routes: Sets/Index.vue, Sets/Show.vue
  Components/         reusable: ItemCard.vue, PartsTable.vue, ColorDot.vue, SearchSelect.vue
  Layouts/
```

Rules:

- **One operation, one Action**, with a single public `handle()` method. No `CollectionService` with
  twenty methods on it.
- **Controllers hold no business logic.** Validate, call an Action or a Query, render.
- **`Catalog` models are never written to.** A shared base class blocks `save`/`delete`/`update`;
  only the importer fills those tables, and it does so through `DB` directly.
- Put a non-trivial read in a `Queries/` class instead of growing model scopes.

## Database

A single SQLite database. Table prefixes carry meaning — follow them strictly:

| Prefix | Contents | Written by |
|--------|----------|------------|
| `bl_*` | BrickLink reference data | the importer only |
| `ref_*` | internal dictionaries (tags, statuses, sources, storage) | the user, through the UI |
| `collection_*`, `entry_*` | the collection | the user, through the UI |

- **Migrations.** Ordinary tables go through the Schema builder. It cannot express `WITHOUT ROWID`,
  `GENERATED ALWAYS AS`, FTS5 or compound `CHECK` constraints — create those tables with
  `DB::statement()`, using the SQL from the design document verbatim.
- **`PRAGMA foreign_keys = ON` is mandatory.** SQLite disables foreign keys by default, and removing
  an entry from the collection relies on `ON DELETE CASCADE`.
- **WAL and a busy timeout are not optional here.** One page pulls dozens of images and each cache
  miss writes a row, so writers collide. Configured in `config/database.php`.
- **Never write with `updateOrInsert` on a path that can run concurrently.** It is a SELECT followed
  by an INSERT: two requests for the same missing image both saw no row, both inserted, and the loser
  got a UNIQUE violation. Use `upsert`, which SQLite executes as a single `INSERT ... ON CONFLICT`.
- **Wrap imports in transactions**, otherwise 1.5M inserts take minutes instead of seconds.
- **Do not bulk-load through the query builder.** It prepares a fresh statement per call, and a chunk
  of 2,000 rows is a statement with 20,000 placeholders for SQLite to parse every time. Use
  `App\Catalog\Import\BulkInsert`, which prepares one fixed-width statement and reuses it. Measured
  on the real archive: inventories fell from 123 s to 10 s.
- **Build indexes after a bulk load, not during.** For the 1.5M inventory rows: 4.8 s + 6.0 s to
  create the indexes afterwards, against 13.4 s maintaining them row by row.
- **Money** is an integer in minor units, column `price`, cast in the model. Never float, never
  decimal.
- **Dates** are `Y-m-d` strings; SQLite has no date type.

## Mutable data lives outside the container

The service targets deployment as a Home Assistant add-on, and an add-on container is rebuilt on every
update. Anything that must survive a restart therefore lives outside the image, under one configurable
data directory: the SQLite database, the image cache, the catalog archive, and `APP_KEY`.

- **Never hardcode a path** to any of those. No `storage_path()`, no path literals in business logic —
  read them from config, which reads them from the environment. One root variable, with per-item
  overrides derived from it.
- Defaults: `storage/app/brickcollector` for a plain install, `/addon_config` under Home Assistant.
- `image_cache.path` stores a path **relative to the cache root**, never an absolute one; the root
  moves when the deployment changes.
- `APP_KEY` is generated once and kept with the data, not regenerated on boot: a new key invalidates
  every session cookie and everything encrypted.
- Only derived artefacts stay inside the container — compiled views, config and route caches, built
  assets. They are rebuilt on start.
- Under the add-on, log to stdout so Home Assistant can display it; a plain install uses the normal
  file log.

The full reasoning, including why `addon_config` was chosen over `/data`, is in the design document
under "Размещение изменяемых данных".

## Catalog import

- Runs only as an artisan command (`catalog:import`), **never** inside an HTTP request.
- Current cost on the reference archive: 46 s, 116 MB peak, 192 MB database. The command prints
  per-step timings — check them before optimising anything.
- Read straight out of the zip without extracting: one `ZipArchive::open()` for the whole import (it
  costs ~590 ms and is paid once). Never use the `zip://` stream wrapper in a loop — it reopens the
  archive on every read, 1.2 s each.
- **Item names are double-escaped.** After XML parsing they still need another `html_entity_decode`,
  otherwise the database ends up holding `Playhouse &#40;Play House&#41;`. This affects 76,331 names.
- Import item types `S`, `P`, `M`, `G`, `B`, `C`. Do not import `I`, `O`, `U` — they never appear
  inside inventories.
- Updates are incremental, driven by comparing CRC32 values from the zip central directory. The dates
  in `btinvlist.csv` are unusable for this: two thirds of them are empty.
- Idempotent: re-running against the same release must not change any data.

## Never call out to the network from a web request

Fetching a missing item image inside the request that renders the page made a
single card take 3.9 s to answer, and a page of 48 cards exhausted Apache's
workers. Outbound work belongs in an artisan command (`catalog:images`); the
request path reads the cache and records what is missing.

## Searching the catalog

- Text search goes through `bl_items_fts`, never `LIKE '%...%'`.
- **The FTS table cannot be aliased.** SQLite resolves `x MATCH ?` against real
  table names only; an alias fails with `no such column: x`.
- Names and item numbers sit in one index as separate columns, so a single
  MATCH covers both and keeps bm25 ranking. Two queries joined by UNION was
  the first attempt: 1.8 s on a common word, and no ranking left, so
  "millennium falcon" led with books and keyrings.
- Everything a person types is quoted before it reaches FTS5, which has its own
  query syntax. An unquoted `OR`, `*`, `-` or stray quote either changes the
  query or raises a syntax error.
- A theme filter matches the subtree by path prefix, not the exact node:
  picking "Star Wars" finds 1,059 sets rather than the 47 sitting exactly at
  that node.

## Accounting rules (the costliest place to get wrong)

- Positions flagged `is_extra`, `is_alternate` or `is_counterpart` **do not count toward quantity**,
  and **neither does anything inside them**. `counts` is written by the code that builds the tree
  rather than generated from the row's own flags, because only that code knows the ancestry. A
  "random packet" set lists all twelve possibilities as alternates of one another; counting the
  minifigure inside each one turned a box of 36 packets into 432 minifigures.
- **Quantities multiply down the tree.** A box of 36 packets holding 3 parts each is 108 parts, so
  part counting stays a plain `SUM(qty)` and never walks back up for ancestors.
- Part accounting always filters by `item_type = 'P'`.
- **Assembled parts (`P` inside `P`) are never expanded** into the collection. A torso is one part,
  not a pair of arms. Show its composition on the part's own page instead.
- Subsets and minifigures, by contrast, are expanded recursively and the intermediate node is kept.
  Depth limit 8, with cycle protection along the traversal path.
- "Incomplete" and "Missing figures" are derived, cached in `collection_entries.flag_*`, and
  recomputed whenever the contents change.

## Frontend

- A page is a file under `resources/js/Pages`; its path mirrors the route.
- Components are `PascalCase.vue`, `<script setup>`, Composition API.
- **Markup uses Bootstrap classes.** Write custom CSS only where Bootstrap has no answer (the masonry
  grid). The components and their classes are listed in the design document.
- Icons: MDI only.
- Wrap tables in `table-responsive` — the service must be usable on a phone.
- **Selects longer than 10 options** use `SearchSelect.vue`, a wrapper around Tom Select with the
  `tom-select.bootstrap5.css` theme. Create the instance in `onMounted`, destroy it in `onUnmounted`
  — without that, Inertia navigation leaves orphaned instances behind. Never reach for Tom Select
  from a page; go through the wrapper. Short lists stay a plain `<select class="form-select">`.

## Internationalisation

The UI ships bilingual: **English and Russian**. English is the default; the selected language lives
in settings and is switched under Settings.

- **Laravel translation files are the single source of truth**: `lang/en/*.php`, `lang/ru/*.php`.
  There is no second dictionary for the frontend — translations reach Vue through Inertia shared
  props, and a small `t()` helper resolves them client-side. Do not add `vue-i18n`; it would
  duplicate a mechanism that already exists.
- **Plural forms.** Russian has three. Do not hand-write the rules: use `trans_choice` on the server
  and the browser's built-in `Intl.PluralRules` on the client.
- **Reference data is not translated.** Set, part, colour and category names stay English — that is
  BrickLink's data, and search runs against it.
- **Internal dictionaries are not translated** either; the user names those entries. The exception is
  the system statuses seeded by a migration ("box", "manual"): their labels come from translation
  files keyed by `code`, not from the `name` column, or they would stay stuck in the install-time
  language.
- Format dates and numbers according to the active locale.

## Language conventions

- Identifiers, file names, table and column names, comments, and commit messages: **English**.
- User-facing strings never appear literally in templates or PHP — they go through translation files,
  both languages kept in sync in the same change.

## Verifying your work

- Tests use plain PHPUnit, not Pest. The importer and the accounting rules must be covered: that is
  where a mistake costs the most and is least visible by eye.
- The import fixture is the `downloads.zip` referenced above; do not copy it into the repository.
- Before claiming something works, open http://127.0.0.1:82 and check. Code that looks right is not
  evidence.
- **Check the number of requests a page makes, not only that it renders.** The image flood was
  invisible in the markup and only showed up in the network panel, where it was both slow and a
  source of intermittent 500s.
