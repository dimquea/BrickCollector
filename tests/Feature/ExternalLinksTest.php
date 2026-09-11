<?php

namespace Tests\Feature;

use App\Support\ExternalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Кнопки на чужие каталоги.
 *
 * Шесть блоков засеяны миграцией, пользователь их только настраивает. Пустой
 * паттерн — это и есть признак «сайт про такое не знает»: отдельного флага
 * применимости к типу нет.
 */
class ExternalLinksTest extends TestCase
{
    use RefreshDatabase;

    private function links(string $code): ?object
    {
        return DB::table('ref_links')->where('code', $code)->first();
    }

    /** Адреса выверены на живых сайтах — миграция кладёт их как есть. */
    public function test_the_seeded_patterns_produce_the_addresses_those_sites_use(): void
    {
        $this->assertSame(
            [
                'BrickLink' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=8038-1',
                'Rebrickable' => 'https://rebrickable.com/sets/8038-1',
                // Brickset адресует набор номером без варианта.
                'Brickset' => 'https://brickset.com/sets/8038',
            ],
            collect(ExternalLinks::for('S', '8038-1'))->pluck('url', 'label')->all(),
        );
    }

    public function test_a_part_carries_its_colour_where_the_site_wants_one(): void
    {
        $urls = collect(ExternalLinks::for('P', '30377', 11))->pluck('url', 'label');

        $this->assertSame(
            'https://www.bricklink.com/v2/catalog/catalogitem.page?P=30377&idColor=11',
            $urls['BrickLink'],
        );
        $this->assertSame('https://rebrickable.com/parts/30377', $urls['Rebrickable']);
    }

    /** У Rebrickable своя нумерация фигурок: паттерн пуст, кнопки нет. */
    public function test_a_block_with_no_pattern_for_the_type_shows_no_button(): void
    {
        $labels = collect(ExternalLinks::for('M', 'sw0179a'))->pluck('label')->all();

        $this->assertSame(['BrickLink', 'Brickset'], $labels);
    }

    public function test_a_disabled_block_shows_nothing(): void
    {
        DB::table('ref_links')->update(['enabled' => false]);

        $this->assertSame([], ExternalLinks::for('S', '8038-1'));
    }

    /** Gear и книги живут на чужих сайтах там же, где наборы. */
    public function test_other_types_follow_the_set_pattern(): void
    {
        $urls = collect(ExternalLinks::for('G', '5005358'))->pluck('url')->all();

        $this->assertContains('https://rebrickable.com/sets/5005358', $urls);
    }

    /** Подпись задаёт пользователь; пустую заменяет код блока. */
    public function test_an_unnamed_block_is_labelled_by_its_code(): void
    {
        DB::table('ref_links')->where('code', 'custom1')
            ->update(['enabled' => true, 'url_set' => 'https://example.test/{id}', 'label' => null]);

        $this->assertContains(
            ['label' => 'custom1', 'url' => 'https://example.test/8038-1'],
            ExternalLinks::for('S', '8038-1'),
        );
    }

    public function test_a_pattern_the_user_edited_survives_a_rerun_of_the_migration(): void
    {
        DB::table('ref_links')->where('code', 'brickset')
            ->update(['url_set' => 'https://brickset.com/sets/{id}']);

        $this->artisan('migrate', ['--force' => true]);

        $this->assertSame('https://brickset.com/sets/{id}', $this->links('brickset')->url_set);
    }

    public function test_the_pages_carry_their_links(): void
    {
        DB::table('bl_item_types')->insert([['code' => 'P', 'name' => 'Part']]);
        DB::table('bl_colors')->insert([['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E']]);
        DB::table('bl_items')->insert([
            'type' => 'P', 'id' => 'brick', 'name' => 'Brick',
            'image_color_id' => 11, 'has_inventory' => 0,
        ]);

        $this->get('/catalog/P/brick')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'links.0.url',
                'https://www.bricklink.com/v2/catalog/catalogitem.page?P=brick&idColor=11',
            ));
    }
}
