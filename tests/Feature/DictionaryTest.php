<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DictionaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ReferenceSeeder::class);
    }

    public function test_it_creates_an_entry_and_puts_it_last(): void
    {
        $this->postJson('/settings/dictionary/sources', ['name' => 'A shop'])
            ->assertOk()
            ->assertJsonPath('row.name', 'A shop');

        $this->postJson('/settings/dictionary/sources', ['name' => 'A fair'])->assertOk();

        $this->assertSame(
            ['A shop', 'A fair'],
            Source::orderBy('sort')->pluck('name')->all(),
        );
    }

    public function test_it_edits_an_entry(): void
    {
        $tag = Tag::create(['name' => 'Built', 'color' => 'secondary', 'sort' => 10]);

        $this->patchJson("/settings/dictionary/tags/{$tag->id}", [
            'name' => 'Assembled',
            'color' => 'success',
            'show_in_list' => true,
        ])->assertOk()->assertJsonPath('row.color', 'success');

        $tag->refresh();

        $this->assertSame('Assembled', $tag->name);
        $this->assertTrue($tag->show_in_list);
    }

    /** A badge colour is a Bootstrap class, not free text. */
    public function test_it_rejects_a_colour_outside_the_bootstrap_set(): void
    {
        $tag = Tag::create(['name' => 'Built', 'color' => 'secondary', 'sort' => 10]);

        $this->patchJson("/settings/dictionary/tags/{$tag->id}", [
            'name' => 'Built',
            'color' => 'rebeccapurple',
        ])->assertStatus(422)->assertJsonValidationErrors('color');

        $this->assertSame('secondary', $tag->refresh()->color);
    }

    public function test_it_requires_a_name(): void
    {
        $this->postJson('/settings/dictionary/sources', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * Box and Instructions describe how a set is kept and are referred to by
     * the interface; removing one would leave existing copies pointing at
     * nothing.
     */
    public function test_a_seeded_status_cannot_be_deleted(): void
    {
        $status = Status::where('code', 'box')->firstOrFail();

        $this->deleteJson("/settings/dictionary/statuses/{$status->id}")
            ->assertStatus(422);

        $this->assertModelExists($status);
    }

    public function test_a_status_the_user_added_can_be_deleted(): void
    {
        $status = Status::create(['name' => 'Sealed', 'sort' => 30]);

        $this->deleteJson("/settings/dictionary/statuses/{$status->id}")->assertOk();

        $this->assertModelMissing($status);
    }

    /**
     * Entries point at a source with no cascade, so the database would refuse
     * anyway. Refusing here explains why, and says how many are affected.
     */
    public function test_a_source_in_use_cannot_be_deleted(): void
    {
        $source = Source::create(['name' => 'A shop', 'sort' => 10]);

        DB::table('bl_item_types')->insert([['code' => 'S', 'name' => 'Set']]);
        Entry::create(['item_type' => 'S', 'item_id' => 'set-1', 'source_id' => $source->id]);

        $this->deleteJson("/settings/dictionary/sources/{$source->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');

        $this->assertModelExists($source);
    }

    public function test_an_unused_source_can_be_deleted(): void
    {
        $source = Source::create(['name' => 'A shop', 'sort' => 10]);

        $this->deleteJson("/settings/dictionary/sources/{$source->id}")->assertOk();

        $this->assertModelMissing($source);
    }

    public function test_an_unknown_dictionary_is_not_found(): void
    {
        $this->postJson('/settings/dictionary/nonsense', ['name' => 'x'])->assertNotFound();
    }

    public function test_the_currency_is_stored_in_settings(): void
    {
        $this->patchJson('/settings', ['currency' => 'eur'])->assertOk();

        $this->assertSame('EUR', DB::table('settings')->where('key', 'currency')->value('value'));
    }
}
