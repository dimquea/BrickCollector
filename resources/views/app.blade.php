<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'BrickCollector') }}</title>
    {{-- Путь, под которым нас видит браузер: пусто в обычной установке,
         префикс Ingress — в аддоне Home Assistant. --}}
    <script>window.__base = @json(request()->attributes->get(\App\Http\Middleware\HandleIngress::ATTRIBUTE, ''), JSON_UNESCAPED_SLASHES);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
