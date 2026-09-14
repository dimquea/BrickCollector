<?php

namespace Tests\Feature;

use App\Collection\Export\BrickLinkXml;
use App\Collection\Models\Entry;
use App\Collection\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Выгрузка в BrickLink XML.
 *
 * Два документа с одним корнем: опись считает штуки в QTY, список желаемого
 * просит не меньше MINQTY. Что именно получится, решает не формат, а раздел и
 * его фильтр, поэтому проверяется здесь в первую очередь это.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([
            ['S', 'set-a', 2019],
            ['S', 'set-b', 2020],
            ['M', 'fig-a', 2019],
            ['P', 'brick', null],
            ['P', 'plate', null],
        ] as [$type, $id, $year]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'year' => $year,
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    private function entry(?string $type, ?string $itemId, array $attributes = []): Entry
    {
        return Entry::create(array_merge([
            'item_type' => $type,
            'item_id' => $itemId,
            'flag_incomplete' => false,
            'flag_missing_figs' => false,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function row(Entry $entry, array $attributes): void
    {
        DB::table('collection_items')->insert(array_merge([
            'entry_id' => $entry->id, 'color_id' => 11, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ], $attributes));
    }

    private function xml(string $url): string
    {
        return $this->get($url)->assertOk()->getContent();
    }

    /** Одна строка документа целиком: порядок полей — часть формата. */
    private function item(string ...$lines): string
    {
        return "  <ITEM>\n    ".implode("\n    ", $lines)."\n  </ITEM>";
    }

    public function test_the_file_is_offered_as_a_download(): void
    {
        $this->get('/parts/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="brickcollector-parts-inventory-'.date('Y-m-d').'.xml"',
            );
    }

    /**
     * Документация BrickLink требует убрать объявление XML: файл должен
     * начинаться сразу с INVENTORY, иначе загрузка не принимается.
     */
    public function test_the_document_has_no_xml_declaration(): void
    {
        $xml = $this->xml('/parts/export');

        $this->assertStringStartsWith('<INVENTORY>', $xml);
        $this->assertStringNotContainsString('<?xml', $xml);
    }

    /**
     * Опись отвечает на «сколько у меня», поэтому две копии одного набора —
     * это одна строка с количеством, а не две строки.
     */
    public function test_the_sets_export_counts_copies_of_the_same_set(): void
    {
        $this->entry('S', 'set-a');
        $this->entry('S', 'set-a');
        $this->entry('S', 'set-b');

        $xml = $this->xml('/sets/export');

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>S</ITEMTYPE>', '<ITEMID>set-a</ITEMID>', '<QTY>2</QTY>'),
            $xml,
        );
        $this->assertStringContainsString('<ITEMID>set-b</ITEMID>', $xml);
    }

    public function test_the_export_honours_the_filter(): void
    {
        $this->entry('S', 'set-a');
        $this->entry('S', 'set-b');

        $xml = $this->xml('/sets/export?year=2020');

        $this->assertStringNotContainsString('<ITEMID>set-a</ITEMID>', $xml);
        $this->assertStringContainsString('<ITEMID>set-b</ITEMID>', $xml);
    }

    /**
     * Количество идёт по выбранному месту: фильтр «отдельно» обещает свободные
     * детали, и выгрузить по нему заодно лежащие в наборах значило бы ответить
     * не на заданный вопрос.
     */
    public function test_the_parts_export_counts_the_place_that_was_asked_about(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->row($set, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 10]);

        $lot = $this->entry('P', 'brick', ['color_id' => 11]);
        $this->row($lot, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 2]);

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>P</ITEMTYPE>', '<ITEMID>brick</ITEMID>', '<COLOR>11</COLOR>', '<QTY>12</QTY>'),
            $this->xml('/parts/export'),
        );

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>P</ITEMTYPE>', '<ITEMID>brick</ITEMID>', '<COLOR>11</COLOR>', '<QTY>2</QTY>'),
            $this->xml('/parts/export?placement=loose'),
        );
    }

    /**
     * С «есть недостача» выгрузка становится списком желаемого, а в примечании
     * оказывается то, чего иначе не узнать: каким наборам детали недостаёт.
     */
    public function test_the_parts_export_asks_for_what_is_missing_and_says_where_from(): void
    {
        $first = $this->entry('S', 'set-a');
        $this->row($first, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 4, 'lost_qty' => 3]);

        $second = $this->entry('S', 'set-b');
        $this->row($second, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 2, 'lost_qty' => 1]);

        // Целая деталь в списке желаемого делать нечего.
        $this->row($second, ['item_type' => 'P', 'item_id' => 'plate', 'qty' => 5]);

        $xml = $this->xml('/parts/export?lost=true');

        $this->assertStringContainsString(
            $this->item(
                '<ITEMTYPE>P</ITEMTYPE>',
                '<ITEMID>brick</ITEMID>',
                '<COLOR>11</COLOR>',
                '<MINQTY>4</MINQTY>',
                '<REMARKS>set-a, set-b</REMARKS>',
            ),
            $xml,
        );
        $this->assertStringNotContainsString('plate', $xml);
    }

    /** Потерянное в одном наборе не превращает всю коллекцию в недостачу. */
    public function test_the_parts_shortage_export_follows_the_place(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->row($set, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 4, 'lost_qty' => 3]);

        $lot = $this->entry('P', 'brick', ['color_id' => 11]);
        $this->row($lot, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 2, 'lost_qty' => 1]);

        $this->assertStringContainsString('<MINQTY>1</MINQTY>', $this->xml('/parts/export?placement=loose&lost=true'));
        $this->assertStringContainsString('<MINQTY>3</MINQTY>', $this->xml('/parts/export?placement=set&lost=true'));
    }

    public function test_the_minifigures_export_switches_with_the_lost_filter(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->row($set, ['item_type' => 'M', 'item_id' => 'fig-a', 'qty' => 2, 'lost_qty' => 1]);

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>M</ITEMTYPE>', '<ITEMID>fig-a</ITEMID>', '<QTY>2</QTY>'),
            $this->xml('/minifigures/export'),
        );

        $this->assertStringContainsString(
            $this->item(
                '<ITEMTYPE>M</ITEMTYPE>',
                '<ITEMID>fig-a</ITEMID>',
                '<MINQTY>1</MINQTY>',
                '<REMARKS>set-a</REMARKS>',
            ),
            $this->xml('/minifigures/export?lost=true'),
        );
    }

    /**
     * У желания нет количества: хотят вещь, а не пять её штук. Цвет осмыслен
     * только у детали — у набора его нет вовсе.
     */
    public function test_the_wishlist_asks_for_one_of_each_and_a_colour_only_for_parts(): void
    {
        Wish::create(['item_type' => 'S', 'item_id' => 'set-a', 'color_id' => 0]);
        Wish::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 5]);

        $xml = $this->xml('/wishlist/export');

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>S</ITEMTYPE>', '<ITEMID>set-a</ITEMID>', '<MINQTY>1</MINQTY>'),
            $xml,
        );
        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>P</ITEMTYPE>', '<ITEMID>brick</ITEMID>', '<COLOR>5</COLOR>', '<MINQTY>1</MINQTY>'),
            $xml,
        );
    }

    /**
     * Сборка выгружается дважды и по-разному: опись считает деталь целиком,
     * вместе с помеченной недостающей, потому что собрать такую же — значит
     * купить все; недостача — ровно вторая половина ответа.
     */
    public function test_an_assembly_exports_its_inventory_and_its_shortage(): void
    {
        $assembly = $this->entry(null, null, ['name' => 'Домик']);

        $this->row($assembly, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 5, 'lost_qty' => 2]);
        $this->row($assembly, ['item_type' => 'P', 'item_id' => 'plate', 'qty' => 3]);

        $inventory = $this->xml("/assemblies/{$assembly->id}/export/inventory");

        $this->assertStringContainsString(
            $this->item('<ITEMTYPE>P</ITEMTYPE>', '<ITEMID>brick</ITEMID>', '<COLOR>11</COLOR>', '<QTY>5</QTY>'),
            $inventory,
        );
        $this->assertStringContainsString('<ITEMID>plate</ITEMID>', $inventory);

        $shortage = $this->xml("/assemblies/{$assembly->id}/export/shortage");

        $this->assertStringContainsString(
            $this->item(
                '<ITEMTYPE>P</ITEMTYPE>',
                '<ITEMID>brick</ITEMID>',
                '<COLOR>11</COLOR>',
                '<MINQTY>2</MINQTY>',
                '<REMARKS>Домик</REMARKS>',
            ),
            $shortage,
        );
        $this->assertStringNotContainsString('plate', $shortage, 'целой детали в недостаче делать нечего');
    }

    public function test_a_set_is_not_an_assembly_to_export(): void
    {
        $set = $this->entry('S', 'set-a');

        $this->get("/assemblies/{$set->id}/export/inventory")->assertNotFound();
    }

    /** Чужой вид выгрузки адресом не выпросить. */
    public function test_an_unknown_kind_of_assembly_export_is_not_a_route(): void
    {
        $assembly = $this->entry(null, null, ['name' => 'Домик']);

        $this->get("/assemblies/{$assembly->id}/export/everything")->assertNotFound();
    }

    public function test_the_document_escapes_text_and_trims_a_long_remark(): void
    {
        $xml = BrickLinkXml::wanted([
            ['type' => 'P', 'id' => 'brick&co', 'color' => 5, 'qty' => 1, 'remarks' => str_repeat('set-1234, ', 40)],
            // Ноль штук — не строка выгрузки, а отсутствие строки.
            ['type' => 'P', 'id' => 'plate', 'color' => 5, 'qty' => 0],
        ]);

        $this->assertStringContainsString('<ITEMID>brick&amp;co</ITEMID>', $xml);
        $this->assertStringNotContainsString('plate', $xml);

        preg_match('#<REMARKS>(.*)</REMARKS>#', $xml, $matches);

        $this->assertLessThanOrEqual(255, mb_strlen($matches[1]));
        // Многоточие идёт сразу за последним целым номером: обрубок «set-» —
        // это другой набор, и в примечании ему делать нечего.
        $this->assertStringEndsWith('set-1234…', $matches[1], 'режем по границе перечисления');
    }
}
