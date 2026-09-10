<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Home Assistant Ingress проксирует аддон под путём вида
 * /api/hassio_ingress/<токен>/ и сообщает этот префикс заголовком
 * X-Ingress-Path, отдавая нам путь уже без него.
 *
 * Вместо того чтобы учитывать префикс в каждом месте, где строится ссылка,
 * говорим о нём самому запросу: SCRIPT_NAME с префиксом — это обычная
 * установка в подкаталог, которую Symfony понимает искоробки. Дальше
 * маршрутизация видит путь без префикса, а url(), asset() и Inertia — с ним.
 *
 * Заголовку верим только когда аддон сам это разрешил: снаружи Ingress
 * подделать его некому, но приложение может стоять и просто на порту.
 */
$trusted = getenv('BRICKCOLLECTOR_TRUST_INGRESS') ?: ($_SERVER['BRICKCOLLECTOR_TRUST_INGRESS'] ?? null);

if (filter_var($trusted, FILTER_VALIDATE_BOOL) && isset($_SERVER['HTTP_X_INGRESS_PATH'])) {
    $prefix = '/'.trim($_SERVER['HTTP_X_INGRESS_PATH'], '/');

    if ($prefix !== '/') {
        $_SERVER['SCRIPT_NAME'] = $prefix.'/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $_SERVER['REQUEST_URI'] = $prefix.($_SERVER['REQUEST_URI'] ?? '/');
    }
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
