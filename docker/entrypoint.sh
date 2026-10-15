#!/bin/sh
# ==============================================================================
# Запуск BrickCollector в обычном контейнере.
#
# То же, что делает аддон при старте, но без bashio и без Home Assistant:
# ключ, схема, справочник, кэши, и затем две службы — php-fpm и nginx.
# ==============================================================================
set -e

DATA="${BRICKCOLLECTOR_DATA_PATH:-/data}"

mkdir -p "${DATA}/images"

# Ключ переживает пересоздание контейнера, поэтому лежит рядом с базой: им
# подписываются сессии и шифруются значения. Задали APP_KEY снаружи — он
# сильнее, и файл не создаётся.
if [ -z "${APP_KEY}" ]; then
    if [ ! -f "${DATA}/app_key" ]; then
        echo "Первый запуск: генерирую ключ приложения"
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "${DATA}/app_key"
    fi

    APP_KEY="$(cat "${DATA}/app_key")"
    export APP_KEY
fi

# Миграции идемпотентны: гонять их каждый старт дешевле, чем угадывать, было ли
# обновление образа.
echo "Проверяю схему базы"
php /app/artisan migrate --force --no-interaction

#
# Справочник BrickLink. Архив остаётся рядом с базой: он же нужен следующему
# обновлению, чтобы сравнить CRC32 и не тянуть всё заново.
#
CATALOG_URL="${CATALOG_URL:-https://github.com/rgriebl/brickstore-database/releases/latest/download/downloads.zip}"

if [ "${IMPORT_CATALOG}" = "true" ]; then
    if [ ! -f "${DATA}/downloads.zip" ]; then
        echo "Скачиваю справочник, около 38 МБ"

        if curl -fsSL --retry 3 -o "${DATA}/downloads.zip.part" "${CATALOG_URL}"; then
            mv "${DATA}/downloads.zip.part" "${DATA}/downloads.zip"
        else
            rm -f "${DATA}/downloads.zip.part"
            echo "Скачать справочник не вышло — поиск по каталогу будет пуст"
        fi
    fi

    # Отметка о ввозе лежит рядом с архивом. Заменили архив на свежий — она
    # окажется старше него, и справочник переедет заново.
    if [ -f "${DATA}/downloads.zip" ] \
        && { [ ! -f "${DATA}/catalog.imported" ] || [ "${DATA}/downloads.zip" -nt "${DATA}/catalog.imported" ]; }; then

        echo "Импортирую справочник: 175 тысяч предметов, на слабой машине это минуты"

        if php /app/artisan catalog:import; then
            touch "${DATA}/catalog.imported"
        else
            echo "Импорт справочника не удался, приложение поднимется без него"
        fi
    fi
fi

#
# Кэши собираем здесь, а не в образе: значения зависят от переменных окружения,
# которые известны только сейчас.
#
php /app/artisan config:cache
php /app/artisan route:cache
php /app/artisan view:cache

#
# Картинки того, чем владеют, подкачиваются в фоне. Никто их не ждёт: та,
# которой в кэше нет, показывается прямо из источника. Кэш нужен для другого —
# чтобы коллекция не зависела от того, отвечает ли BrickLink.
#
if [ "${FETCH_IMAGES}" = "true" ]; then
    (
        while true; do
            php /app/artisan catalog:images \
                --limit="${FETCH_BATCH:-200}" --pause=100 --no-interaction \
                || echo "Загрузка картинок сорвалась, попробую в следующий раз"

            sleep "${FETCH_INTERVAL:-300}"
        done
    ) &
fi

# -R: пул объявлен от root, и php-fpm без этого ключа отказывается стартовать.
php-fpm84 -R

echo "BrickCollector готов: http://localhost:8080"

exec nginx -g 'daemon off;'
