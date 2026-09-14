<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Поиск предмета по фотографии.
 *
 * Снимок уходит в чужую службу — единственное место, где приложение отправляет
 * наружу данные человека. Поэтому первое, что здесь проверяется: без согласия
 * он не уходит никуда. Остальное про то, что человек видит наш справочник, а не
 * чужой ответ.
 */
class PhotoSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Настройки кэшируются на процесс, а база между тестами откатывается:
        // без этого следующий тест читал бы согласие, данное предыдущим.
        Settings::forget();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 9, 'name' => 'Light Gray', 'rgb' => '9BA19D'],
        ]);

        DB::table('bl_items')->insert([[
            'type' => 'P', 'id' => '32270', 'name' => 'Technic, Gear 12 Tooth Double Bevel',
            'image_color_id' => 11, 'has_inventory' => 0,
        ]]);
    }

    /**
     * Ответ службы в том виде, в каком он приходит на самом деле: снят с живого
     * обращения, вплоть до цветов строками и типа «part».
     *
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function answer(?array $items = null): array
    {
        return [
            'listing_id' => 'res-d492bca0',
            'bounding_box' => [
                'left' => 0, 'upper' => 0, 'right' => 10, 'lower' => 10,
                'image_width' => 20, 'image_height' => 20, 'score' => 0.99,
            ],
            'items' => $items ?? [[
                'id' => '32270',
                'name' => 'Technic, Gear 12 Tooth Double Bevel',
                'img_url' => 'https://storage.googleapis.com/brickognize-static/thumb.webp',
                'external_sites' => [],
                'category' => 'Technic',
                'type' => 'part',
                'score' => 0.864,
            ]],
            'colors' => [
                ['id' => '11', 'name' => 'Black', 'score' => 0.786],
                ['id' => '9', 'name' => 'Light Gray', 'score' => 0.308],
            ],
        ];
    }

    private function send(?UploadedFile $photo = null): TestResponse
    {
        return $this->post(
            '/catalog/recognise',
            ['photo' => $photo ?? UploadedFile::fake()->image('brick.jpg', 200, 200)],
            ['Accept' => 'application/json'],
        );
    }

    public function test_without_the_setting_the_photo_goes_nowhere(): void
    {
        Http::fake();

        $this->send()->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_catalogue_offers_the_button_only_once_it_is_switched_on(): void
    {
        $this->get('/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('photoSearch', false));

        Settings::put('photo_search', '1');

        $this->get('/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('photoSearch', true));
    }

    public function test_candidates_come_back_as_rows_of_our_own_catalogue(): void
    {
        Settings::put('photo_search', '1');
        Http::fake(['api.brickognize.com/*' => Http::response($this->answer())]);

        $this->send()
            ->assertOk()
            // Тип наш, а не их «part»: артикул надёжнее перевода между словарями.
            ->assertJsonPath('items.0.type', 'P')
            ->assertJsonPath('items.0.id', '32270')
            ->assertJsonPath('items.0.name', 'Technic, Gear 12 Tooth Double Bevel')
            ->assertJsonPath('colours.0.id', 11)
            ->assertJsonPath('colours.0.rgb', '2E2E2E');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/predict/')
            && str_contains($request->url(), 'predict_color=true'));
    }

    /** Предложить артикул, которого у нас нет, значит обещать несуществующую страницу. */
    public function test_a_candidate_our_catalogue_does_not_know_is_dropped(): void
    {
        Settings::put('photo_search', '1');
        Http::fake(['api.brickognize.com/*' => Http::response($this->answer([[
            'id' => 'not-in-our-catalogue',
            'name' => 'Whatever',
            'img_url' => '',
            'external_sites' => [],
            'category' => null,
            'type' => 'part',
            'score' => 0.91,
        ]]))]);

        $this->send()->assertOk()->assertJsonCount(0, 'items');
    }

    /** Служба не обещает ни точности, ни доступности: молчание должно стать фразой. */
    public function test_a_service_that_does_not_answer_is_reported_plainly(): void
    {
        Settings::put('photo_search', '1');
        Http::fake(['api.brickognize.com/*' => Http::response('', 503)]);

        $this->send()->assertStatus(502)->assertJsonStructure(['message']);
    }

    public function test_only_an_image_is_sent_anywhere(): void
    {
        Settings::put('photo_search', '1');
        Http::fake();

        $this->send(UploadedFile::fake()->create('notes.txt', 10))->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Проверка связи не требует согласия: наружу уходит пустой запрос без единого
     * байта пользовательских данных, а знать ответ полезно до того, как включать.
     */
    public function test_the_health_check_needs_no_consent_and_the_switch_is_saved(): void
    {
        Http::fake(['api.brickognize.com/*' => Http::response(['success' => true])]);

        $this->getJson('/settings/recognition')->assertOk()->assertJson(['available' => true]);

        $this->assertFalse(Settings::photoSearch());

        $this->patchJson('/settings', ['photo_search' => true])->assertOk();

        $this->assertTrue(Settings::photoSearch());
    }
}
