<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Отбор по теме и подтеме.
 *
 * Справочник присылает тему строкой — «Star Wars / Ultimate Collector Series /
 * Star Wars Episode 4/5/6», — и импорт режет её на уровни. Верхний список
 * показывает корни, иначе в каждом ответе ехало бы шесть тысяч вариантов;
 * второй появляется под ним и сужает выбор, потому что «Star Wars» — это
 * тысячи наборов.
 *
 * Параметр при этом остаётся один: отбор и так захватывает всё поддерево
 * выбранного узла, а ссылка с глубокой темой открывается как была.
 */
class ThemeFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> путь => id темы */
    private array $themes = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_categories')->insert([['id' => 1, 'name' => 'Star Wars']]);

        foreach ([
            'Star Wars',
            'Star Wars / Mini',
            'Star Wars / Ultimate Collector Series',
            'Star Wars / Ultimate Collector Series / Star Wars Episode 4/5/6',
            'Castle',
        ] as $path) {
            $this->theme($path);
        }

        // В справочнике всё дерево; во владении — только два набора.
        $this->item('ucs-falcon', 'Star Wars / Ultimate Collector Series / Star Wars Episode 4/5/6');
        $this->item('mini-fighter', 'Star Wars / Mini');
        $this->item('ucs-other', 'Star Wars / Ultimate Collector Series');
        $this->item('castle-keep', 'Castle');

        $this->own('ucs-falcon');
        $this->own('castle-keep');
    }

    private function theme(string $path): int
    {
        $segments = explode(' / ', $path);
        $name = array_pop($segments);
        $parent = $segments === [] ? null : $this->themes[implode(' / ', $segments)];

        $id = count($this->themes) + 1;

        DB::table('bl_themes')->insert([
            'id' => $id,
            'parent_id' => $parent,
            'root_category_id' => 1,
            'name' => $name,
            'path' => $path,
            'depth' => substr_count($path, ' / '),
        ]);

        return $this->themes[$path] = $id;
    }

    private function item(string $id, string $path): void
    {
        DB::table('bl_items')->insert([
            'type' => 'S', 'id' => $id, 'name' => ucfirst($id), 'theme_id' => $this->themes[$path],
            'image_color_id' => 0, 'has_inventory' => 0,
        ]);

        DB::table('bl_items_fts')->insert([
            'name' => ucfirst($id), 'ident' => $id, 'type' => 'S', 'item_id' => $id,
        ]);
    }

    private function own(string $id): void
    {
        Entry::create(['item_type' => 'S', 'item_id' => $id]);
    }

    /** @return array<string, mixed> */
    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_the_catalogue_offers_every_subtheme_of_the_chosen_root(): void
    {
        $props = $this->props('/catalog?theme_id='.$this->themes['Star Wars']);

        $this->assertSame([
            'Mini',
            'Ultimate Collector Series',
            'Ultimate Collector Series / Star Wars Episode 4/5/6',
        ], collect($props['subthemes'])->pluck('path')->all(), 'путь от корня, а не короткое имя');

        $this->assertSame($this->themes['Star Wars'], $props['themeRoot']);
    }

    /** Без выбранной темы второго списка нет вовсе. */
    public function test_without_a_theme_there_are_no_subthemes(): void
    {
        $this->assertSame([], $this->props('/catalog')['subthemes']);
    }

    /**
     * Ссылку с глубокой темой присылают со стороны: страница должна открыться
     * в том же виде, то есть знать, какой корень показать сверху.
     */
    public function test_a_deep_theme_still_knows_its_root(): void
    {
        $deep = $this->themes['Star Wars / Ultimate Collector Series'];
        $props = $this->props('/catalog?theme_id='.$deep);

        $this->assertSame($this->themes['Star Wars'], $props['themeRoot']);
        $this->assertCount(3, $props['subthemes'], 'список тот же, что и у корня');
    }

    public function test_a_deep_theme_narrows_the_catalogue(): void
    {
        $props = $this->props('/catalog?theme_id='.$this->themes['Star Wars / Ultimate Collector Series']);

        $this->assertSame(
            ['ucs-falcon', 'ucs-other'],
            collect($props['results']['data'])->pluck('id')->sort()->values()->all(),
            'и сам узел, и то, что под ним',
        );
    }

    /**
     * В разделе коллекции предлагается только то, за чем что-то стоит, — и
     * промежуточные уровни тоже: ровно на «Ultimate Collector Series» не стоит
     * ни одного экземпляра, но выбрать всё, что под ним, хочется.
     */
    public function test_a_section_offers_only_subthemes_it_holds(): void
    {
        $props = $this->props('/sets?theme_id='.$this->themes['Star Wars']);

        $this->assertSame([
            'Ultimate Collector Series',
            'Ultimate Collector Series / Star Wars Episode 4/5/6',
        ], collect($props['subthemes'])->pluck('path')->all(), 'Mini не предлагается: такого не держим');
    }

    public function test_a_section_narrows_to_the_chosen_subtheme(): void
    {
        $props = $this->props('/sets?theme_id='.$this->themes['Star Wars / Ultimate Collector Series']);

        $this->assertSame(['ucs-falcon'], collect($props['entries']['data'])->pluck('item_id')->all());
    }

    /** У корня, под которым ничего не вложено, второго списка не появляется. */
    public function test_a_root_without_subthemes_offers_none(): void
    {
        $this->assertSame([], $this->props('/sets?theme_id='.$this->themes['Castle'])['subthemes']);
    }
}
