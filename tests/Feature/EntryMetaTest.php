<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\UpdateEntryMeta;
use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntryMetaTest extends TestCase
{
    use RefreshDatabase;

    private Entry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([['code' => 'S', 'name' => 'Set']]);
        DB::table('bl_items')->insert([
            'type' => 'S', 'id' => 'set-1', 'name' => 'A set',
            'image_color_id' => 0, 'has_inventory' => 0,
        ]);

        $this->seed(\Database\Seeders\ReferenceSeeder::class);

        $this->entry = app(AddToCollection::class)->handle(
            CatalogItem::where('type', 'S')->where('id', 'set-1')->first(),
        );
    }

    /** Amounts are integers in minor units; nothing downstream sees a fraction. */
    public function test_it_stores_the_price_in_minor_units(): void
    {
        app(UpdateEntryMeta::class)->handle($this->entry, ['price' => 4999]);

        $this->assertSame(4999, $this->entry->fresh()->price);
    }

    public function test_it_stores_the_rest_of_the_metadata(): void
    {
        $source = Source::create(['name' => 'BrickLink', 'sort' => 1]);
        $storage = Storage::create(['name' => 'Shelf', 'sort' => 1]);

        app(UpdateEntryMeta::class)->handle($this->entry, [
            'acquired_at' => '2024-05-17',
            'source_id' => $source->id,
            'storage_id' => $storage->id,
            'note' => 'Bought at a fair.',
        ]);

        $entry = $this->entry->fresh();

        $this->assertSame('2024-05-17', $entry->acquired_at->format('Y-m-d'));
        $this->assertSame($source->id, $entry->source_id);
        $this->assertSame($storage->id, $entry->storage_id);
        $this->assertSame('Bought at a fair.', $entry->note);
    }

    public function test_it_syncs_statuses_and_tags(): void
    {
        $box = Status::where('code', 'box')->firstOrFail();
        $manual = Status::where('code', 'manual')->firstOrFail();
        $tag = Tag::create(['name' => 'Built', 'color' => 'success', 'sort' => 1]);

        app(UpdateEntryMeta::class)->handle($this->entry, [
            'status_ids' => [$box->id, $manual->id],
            'tag_ids' => [$tag->id],
        ]);

        $this->assertEqualsCanonicalizing(
            [$box->id, $manual->id],
            $this->entry->statuses()->pluck('ref_statuses.id')->all(),
        );

        // Unticking removes it rather than leaving it behind.
        app(UpdateEntryMeta::class)->handle($this->entry, ['status_ids' => [$box->id]]);

        $this->assertSame([$box->id], $this->entry->statuses()->pluck('ref_statuses.id')->all());
        $this->assertSame([$tag->id], $this->entry->tags()->pluck('ref_tags.id')->all());
    }

    public function test_the_route_saves_and_validates(): void
    {
        $this->patchJson("/collection/{$this->entry->id}", [
            'price' => 1250,
            'note' => 'ok',
        ])->assertOk()->assertJsonStructure(['message']);

        $this->assertSame(1250, $this->entry->fresh()->price);

        // A fraction is a bug somewhere upstream: the interface converts.
        $this->patchJson("/collection/{$this->entry->id}", ['price' => 12.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->patchJson("/collection/{$this->entry->id}", ['source_id' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_id');
    }

    /**
     * The seeded statuses must show a translated label. Reading the name
     * column would freeze whatever language the service was installed in.
     */
    public function test_seeded_statuses_are_labelled_from_translations(): void
    {
        app()->setLocale('ru');

        // Asserted against the Inertia props, not the markup: the page ships
        // its data JSON-encoded, so a Cyrillic string never appears literally
        // in the HTML.
        $this->get("/collection/{$this->entry->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dictionaries.statuses.0.name', 'Коробка')
                ->where('dictionaries.statuses.1.name', 'Инструкция'));
    }
}
