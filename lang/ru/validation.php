<?php

/*
 * Russian validation messages.
 *
 * Deliberately partial: only the rules this application actually uses are
 * translated. Laravel falls back to the English file for anything missing, so
 * an untranslated rule reads awkwardly rather than showing a raw key — and the
 * list stays short enough to keep honest.
 */

return [

    'array' => 'Поле «:attribute» должно быть массивом.',
    'boolean' => 'Поле «:attribute» должно иметь значение да или нет.',
    'date' => 'Поле «:attribute» должно содержать корректную дату.',
    'date_format' => 'Поле «:attribute» не соответствует формату :format.',
    'exists' => 'Выбранное значение поля «:attribute» отсутствует в справочнике.',
    'in' => 'Выбранное значение поля «:attribute» недопустимо.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'string' => 'Поле «:attribute» должно быть строкой.',
    'url' => 'Поле «:attribute» должно содержать корректную ссылку.',

    'max' => [
        'array' => 'Поле «:attribute» должно содержать не более :max элементов.',
        'file' => 'Размер файла в поле «:attribute» не должен превышать :max килобайт.',
        'numeric' => 'Поле «:attribute» не должно быть больше :max.',
        'string' => 'Поле «:attribute» не должно быть длиннее :max символов.',
    ],

    'min' => [
        'array' => 'Поле «:attribute» должно содержать не менее :min элементов.',
        'file' => 'Размер файла в поле «:attribute» должен быть не менее :min килобайт.',
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute» должно быть не короче :min символов.',
    ],

    'custom' => [
        'url_set' => ['regex' => 'Адрес должен начинаться с http:// или https://.'],
        'url_minifig' => ['regex' => 'Адрес должен начинаться с http:// или https://.'],
        'url_part' => ['regex' => 'Адрес должен начинаться с http:// или https://.'],
],

    /*
     * Field names as a person sees them, not as the columns are called.
     * ":attribute" otherwise renders "lost qty", which means nothing to the
     * person who just typed a number into a box.
     */
    'attributes' => [
        'label' => 'Подпись кнопки',
        'url_set' => 'Адрес набора',
        'url_minifig' => 'Адрес минифигурки',
        'url_part' => 'Адрес детали',
        'lost_qty' => 'Утеряно',
        'acquired_at' => 'Дата приобретения',
        'price' => 'Стоимость',
        'source_id' => 'Источник',
        'storage_id' => 'Хранение',
        'note' => 'Заметка',
        'status_ids' => 'Статусы',
        'tag_ids' => 'Теги',
        'q' => 'Поиск',
        'type' => 'Тип',
        'theme_id' => 'Тема',
        'year' => 'Год',
        'locale' => 'Язык',
        'qty' => 'Количество',
        'id' => 'Артикул',
    ],

];
