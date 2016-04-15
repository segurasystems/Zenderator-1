#!/usr/bin/php
<?php
if(!defined("APP_ROOT")){
    define("APP_ROOT", __DIR__ . "/zenderator");
}
$begin = microtime(true);
echo "php-cs-fixer-fying... \n";
$pathsToPSR2 = [
    APP_ROOT . "/src/Models/Base",
    APP_ROOT . "/src/Models",
    APP_ROOT . "/src/Controllers/Base",
    APP_ROOT . "/src/Controllers",
    APP_ROOT . "/src/Services/Base",
    APP_ROOT . "/src/Services",
    APP_ROOT . "/src/*.php",
    APP_ROOT . "/tests/Api/Generated",
    APP_ROOT . "/tests/Models/Generated",
    APP_ROOT . "/public/index.php",
];

function phpcsfixerfy($pathToPSR2)
{
    ob_start();
    echo " > {$pathToPSR2} ... ";
    $begin = microtime(true);
    exec(APP_ROOT . "/vendor/bin/php-cs-fixer fix {$pathToPSR2} --rules=no_unused_imports,align_double_arrow,align_equals");
    $time = microtime(true) - $begin;
    echo " [Complete in " . number_format($time, 2) . "]\n";
    echo ob_get_clean();
}

foreach ($pathsToPSR2 as $pathToPSR2) {
    if (file_exists($pathToPSR2)) {
        phpcsfixerfy($pathToPSR2);
    }
}

$time = microtime(true) - $begin;
echo "[ALL DONE]";
echo " [Complete in " . number_format($time, 2) . "]\n";