#!/usr/bin/php
<?php
if(!defined("ZENDERATOR_ROOT")){
    define("ZENDERATOR_ROOT", __DIR__ . "/zenderator");
}
$begin = microtime(true);
echo "php-cs-fixer-fying... \n";
$pathsToPSR2 = [
    ZENDERATOR_ROOT . "/src/Models/Base",
    ZENDERATOR_ROOT . "/src/Models",
    ZENDERATOR_ROOT . "/src/Controllers/Base",
    ZENDERATOR_ROOT . "/src/Controllers",
    ZENDERATOR_ROOT . "/src/Services/Base",
    ZENDERATOR_ROOT . "/src/Services",
    ZENDERATOR_ROOT . "/src/*.php",
    ZENDERATOR_ROOT . "/tests/Api/Generated",
    ZENDERATOR_ROOT . "/tests/Models/Generated",
    ZENDERATOR_ROOT . "/public/index.php",
];

function phpcsfixerfy($pathToPSR2)
{
    ob_start();
    echo " > {$pathToPSR2} ... ";
    $begin = microtime(true);
    exec(ZENDERATOR_ROOT . "/vendor/bin/php-cs-fixer fix {$pathToPSR2} --rules=no_unused_imports,align_double_arrow,align_equals");
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
