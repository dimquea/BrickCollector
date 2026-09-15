<?php

namespace Tests\Feature;

use App\Collection\Import\BrickLinkFile;
use Tests\TestCase;

/**
 * Чтение файла BrickLink XML.
 *
 * Разбор ничего не создаёт и ничего не знает про справочник: его дело — понять,
 * что написано в файле. Поэтому и проверяется он отдельно от всего остального.
 */
class ImportFileTest extends TestCase
{
    private function read(string $xml): ?array
    {
        return (new BrickLinkFile)->read($xml);
    }

    /** @param array<int, string> $items */
    private function file(array $items): string
    {
        return "<INVENTORY>\n".implode("\n", $items)."\n</INVENTORY>";
    }

    public function test_an_inventory_is_read_as_what_is_held(): void
    {
        $found = $this->read($this->file([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3001</ITEMID><COLOR>5</COLOR><QTY>4</QTY></ITEM>',
            '<ITEM><ITEMTYPE>S</ITEMTYPE><ITEMID>4559-1</ITEMID><QTY>2</QTY></ITEM>',
        ]));

        $this->assertFalse($found['wanted']);
        $this->assertSame(2, $found['total']);

        $this->assertSame('P', $found['items'][0]['type']);
        $this->assertSame('3001', $found['items'][0]['id']);
        $this->assertSame(5, $found['items'][0]['color_id']);
        $this->assertSame(4, $found['items'][0]['qty']);

        // У набора цвета нет вовсе, и это не ноль, а «неприменимо».
        $this->assertNull($found['items'][1]['color_id']);
    }

    public function test_a_wanted_list_is_read_and_says_so(): void
    {
        $found = $this->read($this->file([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3001</ITEMID><COLOR>11</COLOR><MINQTY>3</MINQTY>'
                .'<REMARKS>4559-1, 6800-1</REMARKS></ITEM>',
        ]));

        $this->assertTrue($found['wanted'], 'MINQTY — это список желаемого');
        $this->assertSame(3, $found['items'][0]['qty'], 'сколько хотят, столько и количество');
        $this->assertSame('4559-1, 6800-1', $found['items'][0]['remarks']);
    }

    /** Смешанного файла не бывает, но если попадётся — судим по большинству. */
    public function test_a_mixed_file_is_judged_by_the_majority(): void
    {
        $found = $this->read($this->file([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>a</ITEMID><MINQTY>1</MINQTY></ITEM>',
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>b</ITEMID><MINQTY>1</MINQTY></ITEM>',
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>c</ITEMID><QTY>1</QTY></ITEM>',
        ]));

        $this->assertTrue($found['wanted']);
    }

    public function test_the_three_flags_are_read(): void
    {
        $found = $this->read($this->file([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3001</ITEMID><QTY>1</QTY>'
                .'<EXTRA>Y</EXTRA><ALTERNATE>N</ALTERNATE><COUNTERPART>Y</COUNTERPART></ITEM>',
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3002</ITEMID><QTY>1</QTY></ITEM>',
        ]));

        $this->assertTrue($found['items'][0]['is_extra']);
        $this->assertFalse($found['items'][0]['is_alternate']);
        $this->assertTrue($found['items'][0]['is_counterpart']);

        // Нет пометки — значит нет: строка обычная.
        $this->assertFalse($found['items'][1]['is_extra']);
        $this->assertFalse($found['items'][1]['is_counterpart']);
    }

    /** То, что мы сами выгружаем, мы обязаны уметь прочесть. */
    public function test_our_own_export_reads_back(): void
    {
        $xml = \App\Collection\Export\BrickLinkXml::wanted([
            ['type' => 'P', 'id' => '2431', 'color' => 88, 'qty' => 2, 'remarks' => '6800-1'],
        ]);

        $found = $this->read($xml);

        $this->assertTrue($found['wanted']);
        $this->assertSame('2431', $found['items'][0]['id']);
        $this->assertSame(88, $found['items'][0]['color_id']);
        $this->assertSame(2, $found['items'][0]['qty']);
        $this->assertSame('6800-1', $found['items'][0]['remarks']);
    }

    public function test_a_row_without_a_type_or_a_number_is_skipped(): void
    {
        $found = $this->read($this->file([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><QTY>1</QTY></ITEM>',
            '<ITEM><ITEMID>3001</ITEMID><QTY>1</QTY></ITEM>',
            '<ITEM><ITEMTYPE>p</ITEMTYPE><ITEMID>3001</ITEMID><QTY>1</QTY></ITEM>',
        ]));

        $this->assertCount(1, $found['items']);
        $this->assertSame('P', $found['items'][0]['type'], 'тип приводится к верхнему регистру');
    }

    /**
     * Файл выбирает человек, и «это не XML» — обычный ответ, а не происшествие:
     * наверх уходит null, а страница скажет об этом словами.
     */
    public function test_something_that_is_not_xml_is_not_a_crash(): void
    {
        $this->assertNull($this->read('это не файл вовсе'));
        $this->assertNull($this->read(''));
    }

    /** Чужой XML разбирается, но позиций в нём нет. */
    public function test_a_foreign_xml_yields_nothing(): void
    {
        $found = $this->read('<ORDER><LINE>1</LINE></ORDER>');

        $this->assertSame([], $found['items']);
        $this->assertFalse($found['wanted']);
    }

    /**
     * Импорт идёт одним запросом, поэтому длинный файл показывается по частям —
     * и говорит, сколько в нём на самом деле.
     */
    public function test_a_long_file_is_capped_but_counted_honestly(): void
    {
        $items = [];

        for ($index = 0; $index < BrickLinkFile::LIMIT + 5; $index++) {
            $items[] = "<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>part-{$index}</ITEMID><QTY>1</QTY></ITEM>";
        }

        $found = $this->read($this->file($items));

        $this->assertCount(BrickLinkFile::LIMIT, $found['items']);
        $this->assertSame(BrickLinkFile::LIMIT + 5, $found['total']);
    }
}
