<?php

namespace Tests\Feature;

use App\Catalog\Images\ItemImages;
use App\Catalog\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Картинки, которые не скачались, — по расписанию, а не навсегда.
 *
 * Прежде неудача так и оставалась неудачей: служба брала только новое. Теперь
 * сетевой сбой повторяется назавтра, «нет картинки» — через месяц, и каждая
 * следующая неудача отодвигает попытку вдвое дальше.
 */
class ImageRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');
        Carbon::setTestNow('2026-11-11 12:00:00');

        DB::table('bl_item_types')->insert([['code' => 'P', 'name' => 'Part']]);

        foreach (['brick', 'plate', 'tile'] as $id) {
            DB::table('bl_items')->insert([
                'type' => 'P', 'id' => $id, 'name' => ucfirst($id), 'image_color_id' => 11, 'has_inventory' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function images(): ItemImages
    {
        return app(ItemImages::class);
    }

    private function item(string $id): Item
    {
        return Item::where('type', 'P')->where('id', $id)->firstOrFail();
    }

    private function queue(string $id, string $status = 'pending', ?string $retryAt = null, int $attempts = 0): void
    {
        DB::table('image_cache')->insert([
            'item_type' => 'P', 'item_id' => $id, 'color_id' => 11, 'path' => null,
            'status' => $status, 'fetched_at' => null, 'attempts' => $attempts, 'retry_at' => $retryAt,
        ]);
    }

    private function row(string $id): object
    {
        return DB::table('image_cache')->where('item_id', $id)->first();
    }

    public function test_a_network_failure_is_retried_the_next_day_then_less_often(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertSame('error', $this->images()->fetch($this->item('brick'), 11));
        $this->assertSame('2026-11-12 12:00:00', $this->row('brick')->retry_at);
        $this->assertSame(1, (int) $this->row('brick')->attempts);

        $this->images()->fetch($this->item('brick'), 11);
        $this->images()->fetch($this->item('brick'), 11);

        $this->assertSame('2026-11-15 12:00:00', $this->row('brick')->retry_at, 'третья неудача — через четыре дня');
    }

    /** «Нет картинки» меняется редко: месяц, потом два, и не дальше полугода. */
    public function test_a_missing_picture_waits_a_month_and_never_more_than_half_a_year(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->images()->fetch($this->item('brick'), 11);
        $this->assertSame('2026-12-11 12:00:00', $this->row('brick')->retry_at);

        DB::table('image_cache')->where('item_id', 'brick')->update(['attempts' => 9]);
        $this->images()->fetch($this->item('brick'), 11);

        $this->assertSame(Carbon::now()->addDays(180)->toDateTimeString(), $this->row('brick')->retry_at);
    }

    /**
     * Отказ — не «нет картинки». BrickLink отвечает 403 сети, которую не
     * пускает, и такой ответ — состояние источника, а не предмета: повтор
     * назавтра, а не через месяц.
     */
    public function test_a_refusal_is_a_failure_not_a_missing_picture(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403, ['Content-Type' => 'text/html'])]);

        $this->assertSame('error', $this->images()->fetch($this->item('brick'), 11));
        $this->assertSame('2026-11-12 12:00:00', $this->row('brick')->retry_at);
    }

    public function test_success_clears_the_count(): void
    {
        $this->queue('brick', 'error', '2026-11-10 00:00:00', 3);
        Http::fake(['*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

        $this->assertSame('ok', $this->images()->fetch($this->item('brick'), 11));
        $this->assertSame(0, (int) $this->row('brick')->attempts);
        $this->assertNull($this->row('brick')->retry_at);
    }

    /** Сначала новое, потом подошедшие повторы; не подошедшие ждут. */
    public function test_the_queue_takes_new_ones_first_and_due_retries_after(): void
    {
        $this->queue('tile', 'error', '2026-11-10 00:00:00', 1);
        $this->queue('plate', 'missing', '2026-12-01 00:00:00', 1);
        $this->queue('brick');

        $this->assertSame(['brick', 'tile'], $this->images()->pending(10)->pluck('item_id')->all());
        $this->assertSame(['brick'], $this->images()->pending(1)->pluck('item_id')->all(), 'пачка мала — повторы ждут');
        $this->assertSame(['brick', 'tile', 'plate'], $this->images()->pending(10, true)->pluck('item_id')->all(), '--retry — все сразу');
    }

    /** Неудачи, накопившиеся до расписания, срока не имеют — подходят сразу. */
    public function test_an_old_failure_without_a_date_is_due_at_once(): void
    {
        $this->queue('brick', 'error');

        $this->assertSame(['brick'], $this->images()->pending(10)->pluck('item_id')->all());
    }

    public function test_the_settings_count_and_the_button_put_them_on_the_next_run(): void
    {
        $this->queue('brick', 'error', '2026-11-20 00:00:00', 1);
        $this->queue('plate', 'missing', '2026-12-20 00:00:00', 1);
        $this->queue('tile');

        $this->assertSame(['error' => 1, 'missing' => 1], $this->get('/settings')->viewData('page')['props']['imageFailures']);
        $this->assertSame(['tile'], $this->images()->pending(10)->pluck('item_id')->all());

        $this->postJson('/settings/images/retry')->assertOk()->assertJsonPath('queued', 2);

        $this->assertSame(['tile', 'brick', 'plate'], $this->images()->pending(10)->pluck('item_id')->all());
        $this->assertSame(1, (int) $this->row('brick')->attempts, 'счёт попыток кнопка не сбрасывает');
    }
}
