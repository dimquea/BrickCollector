<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Живёт ли приложение под путевым префиксом прокси.
 *
 * Home Assistant Ingress отдаёт аддон по адресу /api/hassio_ingress/<токен>/,
 * а запрос проксирует уже без префикса, называя его в заголовке. Значит
 * маршрутизация должна остаться нетронутой, а префикс — появиться во всём, что
 * мы отдаём обратно: в ссылках, ассетах, адресе страницы Inertia.
 */
class IngressTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/hassio_ingress/TESTTOKEN';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('brickcollector.trust_ingress', true);
    }

    /** @return array<string, string> */
    private function headers(array $extra = []): array
    {
        return ['X-Ingress-Path' => self::PREFIX] + $extra;
    }

    /**
     * Корень отдельным тестом: именно он и ломался.
     *
     * Прежняя реализация подмешивала префикс в SCRIPT_NAME самого запроса. С
     * закэшированными маршрутами Laravel сопоставляет копию запроса, у которой
     * обрезан хвостовой слеш, — а на корне этот слеш единственное, по чему
     * Symfony узнаёт базовый путь. Путь переставал совпадать, и «/» отвечал 405.
     */
    public function test_the_site_root_works_under_a_prefix(): void
    {
        $this->get('/', $this->headers())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home'));
    }

    public function test_the_page_url_carries_the_prefix(): void
    {
        $this->get('/sets', $this->headers())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->url(self::PREFIX.'/sets'));
    }

    /** Иначе браузер пойдёт за стилями мимо аддона и получит от Home Assistant 404. */
    public function test_assets_are_addressed_under_the_prefix(): void
    {
        $this->get('/', $this->headers())->assertSee(self::PREFIX.'/build/', false);
    }

    /** Фронтенд строит из него адреса, которые не проходят через url(). */
    public function test_the_prefix_reaches_the_frontend(): void
    {
        $this->get('/', $this->headers())->assertSee('window.__base = "'.self::PREFIX.'"', false);
    }

    /**
     * Постраничка строит ссылки от адреса запроса, а он приходит без префикса,
     * поэтому её приходится отправлять тем же путём отдельно.
     */
    public function test_pagination_links_carry_the_prefix(): void
    {
        $this->get('/sets', $this->headers())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'entries.links.1.url',
                fn (?string $url) => $url === null || str_contains((string) $url, self::PREFIX.'/sets'),
            ));
    }

    /**
     * Ничего абсолютного.
     *
     * Аддон видят по внутреннему http-адресу, а браузер может прийти по https
     * через внешний прокси — и заголовки, которые Home Assistant нам шлёт,
     * описывают его собственный слушатель, а не то, что стоит перед ним. Любой
     * абсолютный адрес здесь — обещание про происхождение, которого мы не
     * знаем; браузер блокирует его как смешанное содержимое.
     */
    public function test_nothing_absolute_is_emitted(): void
    {
        $this->get('/sets', $this->headers([
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Host' => '192.168.0.10',
        ]))->assertOk()->assertDontSee('http://192.168.0.10', false);
    }

    public function test_a_redirect_points_at_a_path_not_an_origin(): void
    {
        DB::table('bl_item_types')->insert(['code' => 'S', 'name' => 'Set']);

        $entry = Entry::create(['item_type' => 'S', 'item_id' => 'nothing']);

        $response = $this->delete('/sets/'.$entry->id, [], $this->headers());

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(self::PREFIX.'/sets', $location, 'Location строится от корня');
    }

    /**
     * Заголовок переписывает корень всех ссылок на странице, поэтому верить ему
     * можно только там, где его больше некому прислать.
     */
    public function test_the_header_is_ignored_unless_the_deployment_trusts_it(): void
    {
        config()->set('brickcollector.trust_ingress', false);

        $response = $this->get('/', $this->headers());

        $response->assertOk()->assertDontSee(self::PREFIX, false);
    }

    public function test_nothing_changes_without_the_header(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('window.__base = ""', false)
            ->assertInertia(fn ($page) => $page->url('/'));
    }
}
