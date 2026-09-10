<?php
// Временная заглушка. Будет заменена на public/index.php при установке Laravel.
header('Content-Type: text/plain; charset=utf-8');
echo "BrickCollector — заглушка\n\n";
echo "DocumentRoot: ", $_SERVER['DOCUMENT_ROOT'], "\n";
echo "PHP: ", PHP_VERSION, "\n";
echo "ext-zip: ", extension_loaded('zip') ? 'ok' : 'ВЫКЛЮЧЕНО — нужен рестарт Apache', "\n";
