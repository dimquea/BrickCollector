<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оформление: системное, светлое или тёмное.
 *
 * Тема ставится на сам документ, до того как приложение загрузится: иначе
 * тёмная страница успевает мигнуть светлым, пока браузер разбирает скрипты.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Settings::forget();

        parent::tearDown();
    }

    /**
     * Открывающий тег документа.
     *
     * Искать атрибут по всей странице нельзя: скрипт, который ставит тему
     * системы, сам содержит его имя — проверка срабатывала бы на собственном
     * коде.
     */
    private function htmlTag(string $content): string
    {
        preg_match('/<html[^>]*>/', $content, $matches);

        return $matches[0] ?? '';
    }

    /**
     * По умолчанию решает устройство. Атрибута в разметке нет — его поставит
     * скрипт, спросив систему; иначе пришлось бы угадывать за неё.
     */
    public function test_without_a_choice_the_device_decides(): void
    {
        $this->assertSame('system', Settings::theme());

        $content = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-bs-theme', $this->htmlTag($content));
        $this->assertStringContainsString('prefers-color-scheme', $content);
        $this->assertStringContainsString('content="light dark"', $content);
    }

    public function test_a_chosen_theme_is_stamped_on_the_document(): void
    {
        $this->patchJson('/settings', ['theme' => 'dark'])->assertOk();

        Settings::forget();

        $this->assertSame('dark', Settings::theme());

        $content = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-bs-theme="dark"', $this->htmlTag($content));
        // Выбор сделан — спрашивать систему больше не о чем.
        $this->assertStringNotContainsString('prefers-color-scheme', $content);
        $this->assertStringContainsString('content="dark"', $content);
    }

    public function test_the_choice_can_go_back_to_the_system(): void
    {
        Settings::put('theme', 'light');

        $this->patchJson('/settings', ['theme' => 'system'])->assertOk();

        Settings::forget();

        $this->assertSame('system', Settings::theme());
        $this->assertStringNotContainsString('data-bs-theme', $this->htmlTag($this->get('/')->getContent()));
    }

    public function test_a_theme_nobody_has_is_refused(): void
    {
        $this->patchJson('/settings', ['theme' => 'neon'])->assertUnprocessable();

        Settings::forget();

        $this->assertSame('system', Settings::theme());
    }

    /** Настройку правят и мимо интерфейса: мусор в таблице читается как «системное». */
    public function test_nonsense_in_the_table_reads_as_system(): void
    {
        Settings::put('theme', 'neon');

        $this->assertSame('system', Settings::theme());
    }

    public function test_the_settings_page_shows_the_current_theme(): void
    {
        Settings::put('theme', 'light');

        $this->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('theme', 'light'));
    }
}
