<?php

namespace Tests\Feature;

use App\Collection\Models\Link;
use App\Support\ExternalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Настройка блоков внешних ссылок.
 *
 * Блоков ровно шесть, они поставляются с приложением: создания и удаления здесь
 * нет — только правка. Два последних оставлены под ресурсы владельца.
 */
class LinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function block(string $code): Link
    {
        return Link::where('code', $code)->firstOrFail();
    }

    public function test_the_settings_page_carries_all_six_blocks(): void
    {
        $this->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('links', 6)
                ->where('links.0.code', 'bricklink')
                // У инструкции одно поле: она бывает только у набора.
                ->where('links.3.set_only', true));
    }

    public function test_a_block_can_be_filled_in_and_starts_answering(): void
    {
        $link = $this->block('custom1');

        $this->patchJson("/settings/links/{$link->id}", [
            'enabled' => true,
            'label' => 'Мой каталог',
            'url_set' => 'https://example.test/sets/{number}',
        ])->assertOk();

        $this->assertSame(
            [['label' => 'Мой каталог', 'url' => 'https://example.test/sets/75005']],
            array_values(array_filter(
                ExternalLinks::for('S', '75005-1'),
                fn (array $row) => $row['label'] === 'Мой каталог',
            )),
        );
    }

    /** Выключенный блок кнопок не даёт, что бы в нём ни было записано. */
    public function test_turning_a_block_off_removes_its_buttons(): void
    {
        $link = $this->block('bricklink');

        $this->patchJson("/settings/links/{$link->id}", [
            'enabled' => false,
            'url_set' => $link->url_set,
        ])->assertOk();

        $this->assertNotContains(
            'BrickLink',
            array_column(ExternalLinks::for('S', '8038-1'), 'label'),
        );
    }

    /** Инструкция бывает только у набора: остальные паттерны ей не положены. */
    public function test_the_instructions_block_keeps_only_its_set_pattern(): void
    {
        $link = $this->block('instructions');

        $this->patchJson("/settings/links/{$link->id}", [
            'enabled' => true,
            'url_set' => 'https://example.test/{id}.pdf',
            'url_minifig' => 'https://example.test/figure/{id}',
            'url_part' => 'https://example.test/part/{id}',
        ])->assertOk();

        $link->refresh();

        $this->assertSame('https://example.test/{id}.pdf', $link->url_set);
        $this->assertNull($link->url_minifig);
        $this->assertNull($link->url_part);
    }

    /**
     * Адрес уходит в атрибут href чужой страницы, поэтому схему проверяем:
     * javascript: там быть не должно.
     */
    public function test_an_address_that_is_not_a_web_address_is_refused(): void
    {
        $link = $this->block('custom2');

        $this->patchJson("/settings/links/{$link->id}", [
            'enabled' => true,
            'url_set' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors('url_set');

        $this->assertNull($link->fresh()->url_set);
    }

    public function test_an_empty_pattern_is_allowed_and_simply_hides_the_button(): void
    {
        $link = $this->block('rebrickable');

        $this->patchJson("/settings/links/{$link->id}", [
            'enabled' => true,
            'url_set' => null,
            'url_part' => $link->url_part,
        ])->assertOk();

        $labels = array_column(ExternalLinks::for('S', '8038-1'), 'label');

        $this->assertNotContains('Rebrickable', $labels);
        $this->assertContains('Rebrickable', array_column(ExternalLinks::for('P', '3001', 5), 'label'));
    }

    /** Строки фиксированы: удалять их нечем и незачем. */
    public function test_there_is_no_way_to_add_or_remove_a_block(): void
    {
        // Создавать негде — такого маршрута нет вовсе; удалять нечем — по
        // адресу блока живёт только правка.
        $this->postJson('/settings/links', ['code' => 'seventh'])->assertNotFound();
        $this->deleteJson('/settings/links/'.$this->block('custom2')->id)->assertStatus(405);

        $this->assertSame(6, DB::table('ref_links')->count());
    }
}
