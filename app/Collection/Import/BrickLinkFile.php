<?php

namespace App\Collection\Import;

/**
 * Файл BrickLink XML, разобранный построчно.
 *
 * Опись и список желаемого — один и тот же формат, различающийся одним полем:
 * QTY против MINQTY. Читаются оба, что стоит в строке, то и берётся, а чем файл
 * выглядит в целом — сказано отдельно. Это подсказка человеку, а не решение за
 * него: опись можно захотеть в желаемое, а список желаемого — отметить как
 * купленное.
 *
 * Разбор ничего не создаёт и никуда не смотрит, кроме самого файла. Справочник
 * подключается снаружи: здесь неоткуда знать, существует ли такой артикул.
 */
final class BrickLinkFile
{
    /**
     * Сколько позиций показываем за раз.
     *
     * Ограничение не от лени: импорт идёт одним запросом, а опись большого
     * набора — это сотни строк, каждая из которых копируется в коллекцию со
     * всем своим содержимым. Лучше честно сказать «показаны первые тысяча», чем
     * молча упереться в таймаут на середине.
     */
    public const LIMIT = 1000;

    /**
     * @return array{items: array<int, array<string, mixed>>, wanted: bool, total: int}|null
     *                                                                                      null — это не разбирается как XML
     */
    public function read(string $contents): ?array
    {
        // Ошибки разбора нужны нам самим, а не журналу PHP: файл выбирает
        // человек, и «не похоже на XML» — обычный ответ, а не происшествие.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $items = [];
        $wanted = 0;
        $total = 0;

        foreach ($xml->ITEM as $node) {
            $total++;

            if (count($items) >= self::LIMIT) {
                continue;
            }

            $type = strtoupper($this->text($node->ITEMTYPE));
            $id = $this->text($node->ITEMID);

            if ($type === '' || $id === '') {
                continue;
            }

            // Что стоит в строке, то и количество. MINQTY — «хочу не меньше
            // стольких», QTY — «столько есть»; для нас это одно число, а
            // разница в том, куда строка поедет.
            $minimum = $this->text($node->MINQTY) !== '';

            if ($minimum) {
                $wanted++;
            }

            $colour = $this->text($node->COLOR);

            $items[] = [
                'type' => $type,
                'id' => $id,
                // Цвета нет у набора и фигурки, и это не ноль, а «неприменимо».
                'color_id' => $colour === '' ? null : (int) $colour,
                'qty' => max(1, (int) $this->text($minimum ? $node->MINQTY : $node->QTY)),
                'remarks' => $this->text($node->REMARKS),
                'is_extra' => $this->flag($node->EXTRA),
                'is_alternate' => $this->flag($node->ALTERNATE),
                'is_counterpart' => $this->flag($node->COUNTERPART),
            ];
        }

        return [
            'items' => $items,
            // Файл смешанным не бывает, но если попадётся — судим по большинству.
            'wanted' => $items !== [] && $wanted * 2 > count($items),
            'total' => $total,
        ];
    }

    private function flag(?\SimpleXMLElement $node): bool
    {
        return strtoupper($this->text($node)) === 'Y';
    }

    private function text(?\SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }
}
