<?php

namespace App\Catalog\Import;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Что сейчас происходит со справочником.
 *
 * Импорт идёт отдельным процессом — на слабой машине это минуты, — поэтому
 * состояние надо где-то держать: запустивший его запрос давно закончился, а
 * страница должна суметь спросить «ну как там».
 *
 * Держим в файле, а не в базе, и это не вкусовщина: ввоз справочника целиком
 * идёт одной транзакцией, и всё, записанное в базу изнутри неё, снаружи не
 * видно до самого конца — то есть ровно тогда, когда сообщать уже нечего.
 *
 * Шаг заодно служит признаком жизни: процесс, убитый на середине, перестаёт его
 * обновлять, и вечное «идёт импорт» не остаётся.
 */
class CatalogStatus
{
    /** Сколько молчания достаточно, чтобы счесть процесс мёртвым. */
    private const STALE_AFTER_MINUTES = 15;

    public static function start(): void
    {
        self::write(['state' => 'running', 'step' => null, 'message' => null]);
    }

    public static function step(string $step): void
    {
        self::write(['state' => 'running', 'step' => $step, 'message' => null]);
    }

    public static function finished(int $items): void
    {
        self::write([
            'state' => 'idle',
            'step' => null,
            'message' => null,
            'items' => $items,
            'imported_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    public static function failed(string $message): void
    {
        self::write(['state' => 'failed', 'step' => null, 'message' => $message]);
    }

    /**
     * @return array{state: string, step: ?string, message: ?string, updated_at: ?string, imported_at: ?string}
     */
    public static function current(): array
    {
        $stored = self::stored();

        $status = [
            'state' => $stored['state'] ?? 'idle',
            'step' => $stored['step'] ?? null,
            'message' => $stored['message'] ?? null,
            'updated_at' => $stored['updated_at'] ?? null,
            'imported_at' => $stored['imported_at'] ?? null,
        ];

        if ($status['state'] === 'running' && self::stale($status['updated_at'])) {
            $status['state'] = 'failed';
            $status['message'] = __('app.settings.catalog_abandoned');
        }

        return $status;
    }

    public static function isRunning(): bool
    {
        return self::current()['state'] === 'running';
    }

    private static function path(): string
    {
        return config('brickcollector.data_path').'/catalog-status.json';
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    private static function stale(?string $updatedAt): bool
    {
        return $updatedAt === null
            || CarbonImmutable::parse($updatedAt)->diffInMinutes(CarbonImmutable::now()) >= self::STALE_AFTER_MINUTES;
    }

    /** @param array<string, mixed> $status */
    private static function write(array $status): void
    {
        $path = self::path();

        // Дата прошлого удачного ввоза переживает и запуск нового, и неудачу:
        // «обновлялось никогда» на установке с готовым справочником — неправда.
        $keep = array_intersect_key(self::stored(), array_flip(['imported_at', 'items']));

        try {
            @mkdir(dirname($path), 0775, true);

            file_put_contents($path, json_encode(
                $status + $keep + ['updated_at' => CarbonImmutable::now()->toDateTimeString()],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));
        } catch (Throwable) {
            // Состояние — удобство, а не часть работы: не сумели записать —
            // импорт всё равно должен идти дальше.
        }
    }
}
