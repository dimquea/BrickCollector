<?php

namespace App\Http\Controllers;

use App\Collection\Queries\Analytics;
use App\Support\Settings;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Сводка по коллекции.
 *
 * Ничего не хранит и не кеширует: считается по требованию, из тех же таблиц и
 * по тем же правилам, что и списки разделов.
 */
class AnalyticsController extends Controller
{
    public function index(Analytics $analytics): Response
    {
        return Inertia::render('Analytics/Index', [
            'counts' => $analytics->counts(),
            'finance' => $analytics->finance(),
            'themes' => $analytics->byTheme(),
            'years' => $analytics->byYear(),
            'currency' => Settings::currency(),
        ]);
    }
}
