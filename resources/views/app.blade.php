@php($theme = \App\Support\Settings::theme())
<!DOCTYPE html>
{{-- Тема ставится здесь, а не из приложения: атрибут должен существовать до
     того, как браузер нарисует первый кадр, иначе тёмная страница успевает
     мигнуть светлым. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($theme !== 'system') data-bs-theme="{{ $theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Подсказка браузеру для его собственных элементов: полей ввода,
         полос прокрутки, календаря в поле даты. --}}
    <meta name="color-scheme" content="{{ $theme === 'system' ? 'light dark' : $theme }}">
    <title inertia>{{ config('app.name', 'BrickCollector') }}</title>
    @if ($theme === 'system')
        {{-- «Системная» — это не «светлая»: спрашиваем систему и продолжаем
             слушать, потому что она меняется и на ходу, по расписанию дня. --}}
        <script>
            (function () {
                var query = window.matchMedia('(prefers-color-scheme: dark)');
                var apply = function (dark) {
                    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
                };

                apply(query.matches);
                query.addEventListener('change', function (event) { apply(event.matches); });
            })();
        </script>
    @endif
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
