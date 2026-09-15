<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
     * Порядок списка живёт в адресе — а адрес под ингрессом переписывается.
     *
     * Значит, ссылки постранички должны нести и префикс, и выбранный порядок.
     * Префикс проверяется на единственность: однажды он уже удваивался, и
     * страница уходила в /api/hassio_ingress/…/api/hassio_ingress/….
     */
    public function test_pagination_keeps_the_chosen_order_under_a_prefix(): void
    {
        DB::table('bl_item_types')->insert(['code' => 'S', 'name' => 'Set']);

        // Страница справочника — 48 строк; сорок девять дают вторую.
        $items = [];

        for ($index = 0; $index < 49; $index++) {
            $items[] = [
                'type' => 'S', 'id' => sprintf('set-%03d', $index), 'name' => sprintf('Set %03d', $index),
                'year' => 2000 + $index, 'image_color_id' => 0, 'has_inventory' => 0,
            ];
        }

        DB::table('bl_items')->insert($items);

        $this->get('/catalog?sort=year&dir=desc', $this->headers())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.links.1.url', fn (string $url) => substr_count($url, self::PREFIX) === 1
                && str_starts_with($url, self::PREFIX.'/catalog')
                && str_contains($url, 'sort=year')
                && str_contains($url, 'dir=desc')));
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
     * Маршрут для проверки «назад».
     *
     * Свой, а не чужой: в самом приложении back() больше не осталось — каждый
     * возврат называет адрес прямо, потому что под панелью «назад» уводит в
     * корень. Прежде эти два теста ездили на маршруте картинки сборки, и, когда
     * тот перестал возвращаться «назад», один из них молча перестал что-либо
     * проверять. Предмет проверки — правило прослойки, и держать его надо здесь.
     */
    private function routeGoingBack(): string
    {
        Route::middleware('web')->get('/testing/back', fn () => back());

        return '/testing/back';
    }

    /**
     * «Назад» под ингрессом.
     *
     * back() берёт адрес из сессии или из Referer, а там стоит тот хост,
     * которым приложение видит Home Assistant, — не тот, которым запрос пришёл
     * к нам. Прежнее правило переписывало Location только при совпадении
     * хостов, поэтому такой редирект уходил абсолютным на http://192.168.0.10,
     * браузер его блокировал, а флаг успеха оставался в сессии и всплывал
     * позже, при открытии другого раздела.
     */
    public function test_a_redirect_back_is_relative_even_when_it_names_another_host(): void
    {
        $response = $this->get($this->routeGoingBack(), $this->headers([
            'Referer' => 'http://192.168.0.10/assemblies/5',
        ]));

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(self::PREFIX.'/assemblies/5', $location);
        $this->assertStringNotContainsString('192.168.0.10', $location);
    }

    /**
     * «Назад» в никуда.
     *
     * Когда ни предыдущей страницы в сессии, ни Referer нет, back() падает на
     * корень и отдаёт голое «http://хост» — адрес без пути. Прежде такой
     * пропускался как «нечего переписывать», и наружу уходил ровно тот
     * абсолютный редирект, который браузер под ингрессом блокирует.
     */
    public function test_a_redirect_to_the_bare_root_is_relative_too(): void
    {
        $response = $this->get($this->routeGoingBack(), $this->headers());

        $response->assertRedirect();

        $this->assertSame(self::PREFIX.'/', (string) $response->headers->get('Location'));
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
