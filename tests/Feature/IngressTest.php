<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** Прокси говорит с нами по http, даже когда браузер пришёл по https. */
    public function test_forwarded_scheme_and_host_are_used(): void
    {
        $this->get('/', $this->headers([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'ha.example',
        ]))->assertSee('https://ha.example'.self::PREFIX.'/build/', false);
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
