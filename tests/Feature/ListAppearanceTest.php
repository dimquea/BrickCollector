<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Сколько строк в списке и какого размера карточки.
 *
 * Выбор пользователя живёт в таблице настроек, а не в окружении: он должен
 * переживать пересборку аддона и меняться из интерфейса.
 */
class ListAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::forget();
    }

    public function test_a_list_falls_back_to_the_shipped_size(): void
    {
        $this->assertSame(48, Settings::perPage('catalog'));
        $this->assertSame(24, Settings::perPage('sets'));
        $this->assertSame(50, Settings::perPage('parts'));
        $this->assertSame(['desktop' => 'large', 'mobile' => 'large'], Settings::cardSize('catalog'));
    }

    public function test_the_chosen_size_is_what_a_list_shows(): void
    {
        $this->patchJson('/settings', ['per_page' => ['catalog' => 10]])->assertOk();

        $this->get('/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.per_page', 10));
    }

    public function test_card_size_reaches_the_list_for_each_screen_apart(): void
    {
        $this->patchJson('/settings', [
            'card_size' => ['sets' => ['desktop' => 'small', 'mobile' => 'large']],
        ])->assertOk();

        $this->get('/sets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cardSize.desktop', 'small')
                ->where('cardSize.mobile', 'large'));
    }

    public function test_an_unreasonable_size_is_refused(): void
    {
        $this->patchJson('/settings', ['per_page' => ['catalog' => 0]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page.catalog');

        $this->patchJson('/settings', ['per_page' => ['catalog' => 5000]])
            ->assertStatus(422);

        $this->patchJson('/settings', ['card_size' => ['sets' => ['desktop' => 'huge']]])
            ->assertStatus(422);
    }

    /**
     * Настройку правят и мимо интерфейса: ноль в таблице уронил бы пагинатор,
     * поэтому бессмысленное значение читается как поставочное.
     */
    public function test_a_nonsense_value_in_the_table_is_ignored(): void
    {
        DB::table('settings')->insert([['key' => 'per_page.catalog', 'value' => '0']]);
        Settings::forget();

        $this->assertSame(48, Settings::perPage('catalog'));
    }

    public function test_the_settings_page_offers_every_list(): void
    {
        $this->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('appearance.lists', 5)
                ->where('appearance.lists.0.key', 'catalog')
                ->where('appearance.lists.3.key', 'assemblies')
                // Детали показываются таблицей: размер карточки к ним не применим.
                ->where('appearance.lists.4.key', 'parts')
                ->where('appearance.lists.4.cards', false));
    }
}
