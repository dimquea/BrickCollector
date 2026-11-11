<?php

namespace App\Catalog\Images;

use App\Catalog\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Local cache of item images.
 *
 * Images are never hotlinked from the page. BrickLink answers 403 to requests
 * that do not come from its own site — verified from a browser and from the
 * server, with and without a Referer — so a proxy is required rather than
 * merely polite. Fetching once and keeping the file also spares the source a
 * request per card per page view.
 *
 * Serving and fetching are deliberately separate. A page renders 48 cards, and
 * having the request path fetch a missing image turned every one of those into
 * a blocking outbound call: a single card took 3.9 s to answer and a full page
 * exhausted the web server's workers. The route now answers from the cache
 * alone and records what is missing; catalog:images fills it in afterwards.
 */
class ItemImages
{
    private const DISK = 'images';

    /**
     * Когда пробовать снова — в днях, для первой неудачи и потолок.
     *
     * Сетевой сбой обычно временный: назавтра картинка скачается. «Нет
     * картинки» — источник ответил, но не снимком — меняется редко: у предмета
     * просто нет фотографии, и спрашивать о ней чаще раза в месяц незачем.
     * Каждая следующая неудача удваивает срок: то, что не находится годами, не
     * должно занимать очередь каждый прогон.
     */
    private const RETRY = [
        'error' => ['first' => 1, 'cap' => 30],
        'missing' => ['first' => 30, 'cap' => 180],
    ];

    /**
     * Cached file for an item, or null when we do not have one.
     *
     * Never performs a network call and never writes: what gets cached is
     * decided by what the person owns, not by what a page happened to show.
     */
    public function cached(Item $item, ?int $colorId = null): ?string
    {
        $colorId ??= $item->image_color_id ?? 0;

        $row = $this->row($item->type, $item->id, $colorId);

        return $row && $row->status === 'ok' && Storage::disk(self::DISK)->exists($row->path)
            ? $row->path
            : null;
    }

    /**
     * Which of the given items have a cached picture.
     *
     * A page renders dozens of cards. Letting each one ask the server was a
     * flood of requests that answered a placeholder every time, and on Windows
     * two dozen concurrent requests are enough that some fail to read .env at
     * all. One query here, one write for the whole page, and a card with no
     * picture draws its placeholder without asking anyone.
     *
     * @param  array<int, array{0: string, 1: string, 2: int}>  $items  type, id, colour
     * @return array<string, bool>  "type/id/colour" => is there a picture
     */
    public function availability(array $items): array
    {
        if (! $items) {
            return [];
        }

        $keys = [];

        foreach ($items as [$type, $id, $colorId]) {
            $keys[$type.'/'.$id.'/'.$colorId] = [$type, $id, (int) $colorId];
        }

        $known = DB::table('image_cache')
            ->select('item_type', 'item_id', 'color_id', 'status', 'path')
            ->where(function ($query) use ($keys) {
                foreach ($keys as [$type, $id, $colorId]) {
                    $query->orWhere(fn ($w) => $w
                        ->where('item_type', $type)
                        ->where('item_id', $id)
                        ->where('color_id', $colorId));
                }
            })
            ->get()
            ->keyBy(fn ($row) => $row->item_type.'/'.$row->item_id.'/'.$row->color_id);

        $available = [];

        foreach ($keys as $key => [$type, $id, $colorId]) {
            $row = $known[$key] ?? null;

            $available[$key] = $row !== null && $row->status === 'ok' && $row->path !== null;
        }

        return $available;
    }

    public function contents(string $path): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /**
     * Downloads one image. Called by the fetch command, never by a request.
     *
     * @return string one of ok, missing, error
     */
    public function fetch(Item $item, int $colorId): string
    {
        // Relative to the cache root, never absolute: the root is
        // configuration and moves when the deployment changes.
        $path = sprintf('%s/%s/%s.png', $item->type, $colorId, $this->safeName($item->id));

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Referer' => 'https://www.bricklink.com/'])
                ->get($item->imageUrl($colorId));

            if ($response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                Storage::disk(self::DISK)->put($path, $response->body());
                $this->remember($item->type, $item->id, $colorId, $path, 'ok');

                return 'ok';
            }

            // «Нет картинки» — только когда источник так и сказал: 404. Отказ
            // (403 — BrickLink так отвечает сети, которую не пускает), перегрузка
            // и всё прочее — не свойство предмета, а состояние источника, и
            // ждать из-за них месяц незачем: это сбой, повтор назавтра.
            $status = $response->status() === 404 ? 'missing' : 'error';

            $this->remember($item->type, $item->id, $colorId, null, $status);

            return $status;
        } catch (Throwable) {
            $this->remember($item->type, $item->id, $colorId, null, 'error');

            return 'error';
        }
    }

    /**
     * Что качать в этот прогон: сначала новое, потом неудачи, чей срок подошёл.
     *
     * Новое идёт первым — это то, что человек только что добавил и сейчас
     * разглядывает; повторы занимают остаток пачки. Неудача без срока — из тех,
     * что накопились до расписания, или та, которую попросили повторить
     * кнопкой, — подходит сразу. $now — «все неудачи сейчас, без расписания».
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function pending(int $limit, bool $now = false)
    {
        return DB::table('image_cache')
            ->where(fn ($query) => $query
                ->where('status', 'pending')
                ->orWhere(fn ($failed) => $failed
                    ->whereIn('status', ['missing', 'error'])
                    ->when(! $now, fn ($due) => $due->where(fn ($when) => $when
                        ->whereNull('retry_at')
                        ->orWhere('retry_at', '<=', now()->toDateTimeString())))))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderBy('retry_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Сколько картинок не скачалось — по причинам, для настроек.
     *
     * @return array{error: int, missing: int}
     */
    public function failures(): array
    {
        $counts = DB::table('image_cache')
            ->whereIn('status', ['missing', 'error'])
            ->groupBy('status')
            ->pluck(DB::raw('COUNT(*)'), 'status');

        return ['error' => (int) ($counts['error'] ?? 0), 'missing' => (int) ($counts['missing'] ?? 0)];
    }

    /**
     * Ставит все неудачи на ближайший прогон — по кнопке в настройках.
     *
     * Счёт попыток не сбрасывается: если картинки и правда нет, следующая
     * неудача отложит её так же далеко, как отложила бы и без кнопки.
     *
     * @return int сколько поставлено
     */
    public function retryNow(): int
    {
        return DB::table('image_cache')
            ->whereIn('status', ['missing', 'error'])
            ->update(['retry_at' => null]);
    }

    /**
     * Ставит в очередь картинки того, чем человек владеет и чего хочет.
     *
     * Кэшируем коллекцию, а не всё подряд: справочник — это 175 тысяч
     * предметов, и качать их «на всякий случай» бессмысленно. Всё, чего в кэше
     * нет, страница покажет прямо из источника, так что ждать очередь никому не
     * приходится; она лишь делает коллекцию независимой от того, жив ли BrickLink.
     *
     * Желаемое попадает сюда же: это короткий список, который человек
     * разглядывает, и картинки в нём нужны ровно так же.
     *
     * @return int сколько строк добавилось
     */
    public function queueCollection(): int
    {
        $wanted = DB::table('collection_entries')
            ->whereNotNull('item_type')
            ->selectRaw('item_type, item_id, COALESCE(color_id, 0) as color_id')
            ->union(
                DB::table('collection_items')
                    ->selectRaw('item_type, item_id, COALESCE(color_id, 0) as color_id')
            )
            ->union(
                DB::table('wishlist')
                    ->selectRaw('item_type, item_id, color_id')
            );

        $rows = DB::query()
            ->fromSub($wanted, 'wanted')
            ->whereNotExists(fn ($sub) => $sub
                ->from('image_cache')
                ->whereColumn('image_cache.item_type', 'wanted.item_type')
                ->whereColumn('image_cache.item_id', 'wanted.item_id')
                ->whereColumn('image_cache.color_id', 'wanted.color_id')
                ->selectRaw('1'))
            ->get();

        $added = 0;

        foreach ($rows->chunk(500) as $chunk) {
            $added += DB::table('image_cache')->insertOrIgnore(
                $chunk->map(fn ($row) => [
                    'item_type' => $row->item_type,
                    'item_id' => $row->item_id,
                    'color_id' => (int) $row->color_id,
                    'path' => null,
                    'status' => 'pending',
                    'fetched_at' => null,
                ])->all()
            );
        }

        return $added;
    }

    /**
     * Stand-in for an image we do not have: the item type on a neutral tile.
     * Drawn rather than shipped as a file so it inherits nothing and cannot go
     * missing.
     */
    public function placeholder(string $type): string
    {
        $letter = htmlspecialchars($type, ENT_QUOTES | ENT_XML1);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 120" width="160" height="120" role="img">
              <rect width="160" height="120" fill="#e9ecef"/>
              <circle cx="80" cy="52" r="22" fill="#dee2e6"/>
              <text x="80" y="60" text-anchor="middle" font-family="system-ui,sans-serif"
                    font-size="22" font-weight="600" fill="#adb5bd">{$letter}</text>
            </svg>
            SVG;
    }

    private function row(string $type, string $id, int $colorId): ?object
    {
        return DB::table('image_cache')
            ->where('item_type', $type)
            ->where('item_id', $id)
            ->where('color_id', $colorId)
            ->first();
    }

    /** Item ids contain slashes and other characters that are not path-safe. */
    private function safeName(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
    }

    /**
     * upsert, not updateOrInsert: the latter is a SELECT followed by an
     * INSERT, and one page asks for dozens of images at once. Two requests for
     * the same missing picture both saw no row, both inserted, and the loser
     * got a UNIQUE violation — a 500 in place of an image.
     */
    private function remember(string $type, string $id, int $colorId, ?string $path, string $status): void
    {
        // Удача обнуляет счёт; неудача прибавляет попытку и назначает
        // следующую — тем дальше, чем больше их было.
        $attempts = 0;
        $retryAt = null;

        if (isset(self::RETRY[$status])) {
            $attempts = (int) ($this->row($type, $id, $colorId)?->attempts ?? 0) + 1;
            $rule = self::RETRY[$status];
            $days = min($rule['cap'], $rule['first'] * 2 ** min($attempts - 1, 10));
            $retryAt = now()->addDays($days)->toDateTimeString();
        }

        DB::table('image_cache')->upsert(
            [[
                'item_type' => $type,
                'item_id' => $id,
                'color_id' => $colorId,
                'path' => $path,
                'status' => $status,
                'fetched_at' => now()->toDateTimeString(),
                'attempts' => $attempts,
                'retry_at' => $retryAt,
            ]],
            ['item_type', 'item_id', 'color_id'],
            ['path', 'status', 'fetched_at', 'attempts', 'retry_at'],
        );
    }
}
