<?php

namespace App\Catalog\Import;

use Symfony\Component\Process\Process;

/**
 * Запускает обновление справочника отдельным процессом.
 *
 * Импорт — это 175 тысяч предметов и полтора миллиона строк инвентаря: секунды
 * на приличной машине и минуты на Raspberry Pi. Держать на нём веб-запрос
 * нельзя ни по таймауту прокси, ни по здравому смыслу, а очереди в этом
 * приложении нет — и заводить воркера ради одной кнопки было бы дороже, чем
 * она стоит.
 *
 * Процесс отвязывается от нас средствами оболочки, а не PHP: Symfony Process
 * убивает потомка в деструкторе, то есть ровно тогда, когда запрос заканчивается
 * и работа должна была бы продолжаться.
 */
class CatalogRefresh
{
    public function start(): void
    {
        $php = config('brickcollector.php_binary');

        if (! $this->runnable($php)) {
            CatalogStatus::failed(__('app.settings.catalog_no_php', ['binary' => $php]));

            return;
        }

        CatalogStatus::start();

        Process::fromShellCommandline($this->command($php), base_path())
            ->setTimeout(null)
            ->run();
    }

    /**
     * Проверяем интерпретатор до запуска.
     *
     * Иначе неверный путь оборачивается не ошибкой, а вечным «идёт импорт»:
     * оболочка честно запустит что угодно и промолчит.
     */
    private function runnable(string $php): bool
    {
        $probe = Process::fromShellCommandline(escapeshellarg($php).' --version');
        $probe->setTimeout(10);
        $probe->run();

        return $probe->isSuccessful();
    }

    private function command(string $php): string
    {
        $php = escapeshellarg($php);
        $artisan = escapeshellarg(base_path('artisan'));

        // Логи процесса — в общий файл приложения: другого места, где их стоило
        // бы искать, всё равно нет.
        $log = escapeshellarg(storage_path('logs/catalog-import.log'));

        return PHP_OS_FAMILY === 'Windows'
            ? "start /B \"\" {$php} {$artisan} catalog:import --download >> {$log} 2>&1"
            : "nohup {$php} {$artisan} catalog:import --download >> {$log} 2>&1 &";
    }
}
