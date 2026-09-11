<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Фильтры списка, прочитанные из строки запроса.
 *
 * Два отличия от обычной валидации, и оба про то, что фильтр — это не форма.
 *
 * Переключатели читаются как переключатели. В строке запроса всё — строки, и
 * правило boolean не пускает "true": галочка «Некомплект» отправляла
 * incomplete=true и получала отказ. Здесь «да» — это 1, true, on и yes, а всё
 * остальное — «нет», как это понимает сам PHP.
 *
 * Негодное значение отбрасывается, а не отбрасывает страницу. Адрес с фильтром
 * копируют и открывают руками; ?year=abc заслуживает полного списка, а не
 * редиректа «назад» — который к тому же уводил неизвестно куда.
 */
final class ListFilters
{
    /**
     * @param  array<string, array<int, mixed>>  $rules  правила значений; «nullable» не нужен
     * @param  array<int, string>  $switches  ключи-переключатели
     * @return array<string, mixed> только то, что прошло проверку и не пусто
     */
    public static function read(Request $request, array $rules, array $switches = []): array
    {
        $input = [];

        foreach (array_keys($rules) as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $input[$key] = $value;
            }
        }

        foreach ($switches as $key) {
            // Выключенный переключатель — это отсутствие фильтра, а не фильтр
            // «только выключенные»: в запрос его не кладём вовсе.
            if ($request->boolean($key)) {
                $input[$key] = true;
            } else {
                unset($input[$key]);
            }
        }

        $validator = Validator::make($input, array_intersect_key($rules, $input));

        if ($validator->fails()) {
            foreach ($validator->errors()->keys() as $key) {
                unset($input[$key]);
            }
        }

        return $input;
    }
}
