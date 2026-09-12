<?php

use App\Http\Controllers\AssemblyImageController;
use App\Http\Controllers\ItemImageController;
use Illuminate\Support\Facades\Route;

/*
 * Картинки предметов — вне группы web.
 *
 * Им не нужна ни сессия, ни cookies, ни CSRF, а сессия от них вредила: каждый
 * GET, прошедший через неё, запоминается как «предыдущая страница». Список
 * рисует два десятка картинок, и любой редирект «назад» уводил на последнюю из
 * них — в Home Assistant это выглядело как переход на /images/S/21108-1/0.
 * Заодно ушла запись сессии на каждую картинку и Set-Cookie в её ответе, из-за
 * которого ответ хуже кэшируется.
 *
 * Номера предметов бывают с точками и прочими странными символами, поэтому
 * сегмент id принимает всё, кроме слеша.
 */
Route::get('/images/{type}/{id}/{color}', [ItemImageController::class, 'show'])
    ->where('type', '[A-Z]')
    ->where('color', '[0-9]+')
    ->name('item.image');

// Картинка сборки — сюда же и по тем же причинам. Тип предмета однобуквенный,
// поэтому «assembly» ни с чем не спутается.
Route::get('/images/assembly/{entry}', [AssemblyImageController::class, 'show'])
    ->where('entry', '[0-9]+')
    ->name('assembly.image');
