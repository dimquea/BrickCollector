<?php

namespace App\Catalog\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Куда BrickLink увёл предмет, которого больше нет в справочнике.
 *
 * Журнал изменений (btchglog.csv) — полная история с 2005 года: смены артикула
 * (I), слияния двух предметов в один (M) и смены типа (T). Коллекция хранит
 * артикул, каким он был в день добавления, и после переименования ссылается в
 * пустоту: ни названия, ни картинки, и отметка о недостаче при обновлении
 * описи не находит своей строки.
 *
 * Правила чтения:
 *
 *  - Спрашиваем только о том, чего в справочнике нет. Существующий артикул
 *    верен, даже если когда-то он значил другое: повторно занятых номеров за
 *    последние годы — доли процента, и угадать, по какому справочнику записана
 *    строка, нельзя.
 *  - Цепочка идёт по порядку записей: X → Y, потом Y → Z, — значит X теперь Z.
 *    Каждый следующий шаг берётся только из записей позже предыдущего; иначе
 *    обмены — левая и правая детали меняются номерами через промежуточный, —
 *    закольцевали бы цепочку.
 *  - Итог должен существовать. Цепочка, которая ни к чему не привела, — не
 *    повод переписывать артикул на другой несуществующий.
 */
final class Renames
{
    /** Виды записей, которые переносят предмет; прочие — о цветах и категориях. */
    private const KINDS = ['I', 'M', 'T'];

    /** Длиннее цепочек в журнале нет; предел — страховка от его ошибок. */
    private const MAX_STEPS = 50;

    /**
     * @param  iterable<array{0: string, 1: string}>  $refs  тип и артикул
     * @return array<string, array{type: string, id: string}>  «тип/артикул» => куда он ведёт сейчас
     */
    public function resolve(iterable $refs): array
    {
        $found = [];

        foreach ($refs as [$type, $id]) {
            $key = $type.'/'.$id;

            if (isset($found[$key]) || $this->exists($type, $id)) {
                continue;
            }

            $target = $this->follow($type, $id);

            if ($target !== null && $this->exists($target['type'], $target['id'])) {
                $found[$key] = $target;
            }
        }

        return $found;
    }

    /** @return array{type: string, id: string}|null */
    private function follow(string $type, string $id): ?array
    {
        $after = 0;
        $moved = false;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $change = DB::table('bl_changelog')
                ->where('from_type', $type)
                ->where('from_id', $id)
                ->where('id', '>', $after)
                ->whereIn('kind', self::KINDS)
                ->orderBy('id')
                ->first(['id', 'to_type', 'to_id']);

            if ($change === null) {
                break;
            }

            [$type, $id, $after, $moved] = [$change->to_type, $change->to_id, (int) $change->id, true];
        }

        return $moved ? ['type' => $type, 'id' => $id] : null;
    }

    private function exists(string $type, string $id): bool
    {
        return DB::table('bl_items')->where('type', $type)->where('id', $id)->exists();
    }
}
