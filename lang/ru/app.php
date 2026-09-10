<?php

return [
    'name' => 'BrickCollector',

    'nav' => [
        'catalog' => 'Справочник',
        'sets' => 'Наборы',
        'parts' => 'Детали',
        'minifigures' => 'Минифигурки',
        'analytics' => 'Аналитика',
        'settings' => 'Настройки',
    ],

    'home' => [
        'title' => 'Добро пожаловать',
        'tagline' => 'Самостоятельно размещаемый учёт коллекции LEGO.',
        'catalog_empty' => 'Справочник ещё не импортирован.',
    ],

    'catalog' => [
        'title' => 'Справочник',
        'query' => 'Поиск',
        'query_hint' => 'Название или артикул',
        'type' => 'Тип',
        'theme' => 'Тема',
        'year' => 'Год',
        'any' => 'Любой',
        'reset' => 'Сбросить',
        'only_with_inventory' => 'Только с известным составом',
        'has_inventory' => 'Состав известен',
        'nothing_found' => 'По этим фильтрам ничего не нашлось.',
        'found' => '{0} ничего не найдено|{1} :count предмет|[2,4] :count предмета|[5,*] :count предметов',
    ],

    'settings' => [
        'title' => 'Настройки',
        'appearance' => 'Внешний вид',
        'appearance_hint' => 'Язык интерфейса и параметры отображения.',
        'saved' => 'Сохранено.',
    ],

    'locale' => [
        'label' => 'Язык',
        'en' => 'English',
        'ru' => 'Русский',
    ],

    'parts_counted' => '{0} Нет деталей|{1} :count деталь|[2,4] :count детали|[5,*] :count деталей',
];
