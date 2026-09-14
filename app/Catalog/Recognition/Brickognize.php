<?php

namespace App\Catalog\Recognition;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Распознавание предмета по фотографии.
 *
 * Единственное место, где приложение знает про чужой API. Изоляция здесь не
 * украшение: нынешние адреса службы помечены у неё как legacy и обещаны к
 * замене, а условия меняются без предупреждения — когда это случится, править
 * придётся один файл.
 *
 * Снимок нигде не сохраняется: он приходит во временный файл PHP, уходит в
 * запрос и исчезает вместе с концом обращения. Хранить его нам незачем, а
 * обещание «наружу уходит только то, что вы отправили сами» стоит того, чтобы
 * быть правдой буквально.
 *
 * Служба ничего не обещает — ни точности, ни доступности, — поэтому «не
 * ответила» здесь обычный исход, а не исключительный случай: наверх уходит
 * null, и страница показывает человеку внятную фразу вместо пятисотки.
 */
final class Brickognize
{
    /** Представляемся: лимитов служба не публикует, но знать, кто пришёл, ей полезно. */
    private const AGENT = 'BrickCollector (+https://github.com/dimquea/BrickCollector)';

    private readonly string $base;

    private readonly int $timeout;

    public function __construct()
    {
        $this->base = rtrim((string) config('brickcollector.recognition_url'), '/');
        $this->timeout = max(1, (int) config('brickcollector.recognition_timeout'));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, colors: array<int, array<string, mixed>>}|null
     *                                                                                                     null — служба не ответила
     */
    public function identify(UploadedFile $photo, int $limit = 5): ?array
    {
        // Параметры идут в адресе, а не в теле: тело здесь занято самим файлом.
        $query = http_build_query([
            'predict_color' => 'true',
            'top_k_items' => $limit,
            'top_k_colors' => 3,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withUserAgent(self::AGENT)
                ->attach('query_image', $photo->get(), $photo->getClientOriginalName() ?: 'photo.jpg')
                ->post($this->base.'/predict/?'.$query);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return [
            'items' => $response->json('items') ?? [],
            'colors' => $response->json('colors') ?? [],
        ];
    }

    /** Отвечает ли служба вообще: их health объявлен публичным и данных не несёт. */
    public function reachable(): bool
    {
        try {
            return Http::timeout(5)
                ->withUserAgent(self::AGENT)
                ->get($this->base.'/health/')
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
