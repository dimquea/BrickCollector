<?php

namespace App\Http\Controllers;

use App\Catalog\Recognition\Brickognize;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Поиск предмета по фотографии.
 *
 * Кандидаты возвращаются строками нашего справочника, а не ответом службы. У
 * нас есть и название, и картинка, а их условия прямо запрещают копировать и
 * зеркалить чужие материалы — так что их миниатюры мы не показываем вовсе.
 *
 * Чего нет в нашем справочнике, того человек не увидит: предложить артикул,
 * который у нас не открывается, значит пообещать несуществующую страницу.
 */
class RecognitionController extends Controller
{
    /** Их типы предметов — наши, но другими словами. Подсказка, а не истина. */
    private const TYPES = ['part' => 'P', 'fig' => 'M', 'set' => 'S', 'sticker' => 'P'];

    public function identify(Request $request, Brickognize $service): JsonResponse
    {
        // Настройка — это согласие отправить снимок наружу. Без неё запрос не
        // должен уходить даже при прямом обращении к адресу, поэтому проверка
        // стоит раньше разбора загруженного файла.
        abort_unless(Settings::photoSearch(), 404);

        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $found = $service->identify($request->file('photo'));

        if ($found === null) {
            return response()->json(['message' => __('app.recognition.failed')], 502);
        }

        return response()->json([
            'items' => $this->ours($found['items']),
            'colours' => $this->colours($found['colors']),
        ]);
    }

    /**
     * Отвечает ли служба.
     *
     * Настройки не требует: наружу уходит пустой запрос без единого байта
     * пользовательских данных, а знать ответ полезно как раз до того, как
     * включить функцию.
     */
    public function health(Brickognize $service): JsonResponse
    {
        return response()->json(['available' => $service->reachable()]);
    }

    /**
     * Сопоставляет кандидатов с нашим справочником.
     *
     * Тип берём свой: у них part/fig/set/sticker, у нас типы BrickLink, и
     * артикул надёжнее любого перевода между ними. Их тип идёт лишь подсказкой
     * на случай, когда один и тот же номер носят предметы разного рода.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function ours(array $candidates): array
    {
        $ids = collect($candidates)->pluck('id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $known = DB::table('bl_items')
            ->whereIn('id', $ids)
            ->get(['type', 'id', 'name', 'image_color_id'])
            ->groupBy('id');

        return collect($candidates)
            ->map(function (array $candidate) use ($known) {
                $rows = $known->get($candidate['id'] ?? '');

                if ($rows === null) {
                    return null;
                }

                $hinted = self::TYPES[$candidate['type'] ?? ''] ?? null;
                $row = $rows->firstWhere('type', $hinted) ?? $rows->first();

                return [
                    'type' => $row->type,
                    'id' => $row->id,
                    'name' => $row->name,
                    'image_color_id' => (int) $row->image_color_id,
                    'score' => round((float) ($candidate['score'] ?? 0), 3),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Цвета службы — бриклинковские, те же, что у нас: проверено на живом
     * ответе, где 11 это Black, а 9 — Light Gray. Незнакомый номер всё равно
     * отсеивается соединением: угадывать цвет по имени мы не станем.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function colours(array $candidates): array
    {
        $ids = collect($candidates)->pluck('id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $known = DB::table('bl_colors')->whereIn('id', $ids)->get(['id', 'name', 'rgb'])->keyBy('id');

        return collect($candidates)
            ->map(function (array $candidate) use ($known) {
                $row = $known->get((int) ($candidate['id'] ?? 0));

                return $row === null ? null : [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'rgb' => $row->rgb,
                    'score' => round((float) ($candidate['score'] ?? 0), 3),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
