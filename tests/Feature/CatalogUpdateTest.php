<?php

namespace Tests\Feature;

use App\Catalog\Import\CatalogRefresh;
use App\Catalog\Import\CatalogStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Обновление справочника из интерфейса.
 *
 * Сама работа идёт отдельным процессом: запрос её только запускает, а страница
 * потом спрашивает, как дела. Поэтому проверяем состояние, а не импорт — он
 * проверен отдельно.
 */
class CatalogUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Состояние живёт файлом рядом с базой: каталог у тестов свой.
        config()->set('brickcollector.data_path', sys_get_temp_dir().'/brickcollector-test-'.getmypid());

        $this->forgetStatus();
    }

    protected function tearDown(): void
    {
        $this->forgetStatus();

        parent::tearDown();
    }

    private function forgetStatus(): void
    {
        @unlink(config('brickcollector.data_path').'/catalog-status.json');
    }

    public function test_a_fresh_installation_says_nothing_is_running(): void
    {
        $this->assertSame('idle', CatalogStatus::current()['state']);
        $this->assertNull(CatalogStatus::current()['imported_at']);
        $this->assertFalse(CatalogStatus::isRunning());
    }

    public function test_the_step_is_what_the_page_shows_while_it_works(): void
    {
        CatalogStatus::start();
        CatalogStatus::step('inventories');

        $this->getJson('/settings/catalog')
            ->assertOk()
            ->assertJsonPath('status.state', 'running')
            ->assertJsonPath('status.step', 'inventories');
    }

    /** Дата прошлого ввоза не должна исчезать, пока идёт новый. */
    public function test_the_last_update_survives_a_new_run(): void
    {
        CatalogStatus::finished(175_000);

        $before = CatalogStatus::current()['imported_at'];

        CatalogStatus::start();

        $this->assertSame($before, CatalogStatus::current()['imported_at']);

        CatalogStatus::failed('что-то пошло не так');

        $this->assertSame($before, CatalogStatus::current()['imported_at']);
    }

    /**
     * Процесс, убитый на середине, не оставляет вечное «идёт импорт»: шаг
     * перестаёт обновляться, и молчание само становится ответом.
     */
    public function test_an_abandoned_run_stops_claiming_to_be_running(): void
    {
        CatalogStatus::start();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        $status = CatalogStatus::current();

        CarbonImmutable::setTestNow();

        $this->assertSame('failed', $status['state']);
        $this->assertNotNull($status['message']);
    }

    public function test_it_refuses_to_start_a_second_run(): void
    {
        CatalogStatus::start();

        $this->postJson('/settings/catalog')
            ->assertOk()
            ->assertJsonPath('message', __('app.settings.catalog_running'))
            ->assertJsonPath('status.state', 'running');
    }

    /**
     * Неверный путь к интерпретатору иначе обернулся бы не ошибкой, а вечным
     * «идёт импорт»: оболочка честно запустит что угодно и промолчит.
     */
    public function test_it_says_so_when_php_cannot_be_run(): void
    {
        config()->set('brickcollector.php_binary', 'no-such-php-binary');

        app(CatalogRefresh::class)->start();

        $status = CatalogStatus::current();

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('no-such-php-binary', $status['message']);
    }

    public function test_the_settings_page_carries_the_status(): void
    {
        CatalogStatus::finished(175_000);

        $this->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('catalog.status.state', 'idle')
                ->whereNot('catalog.imported_at', null));
    }
}
