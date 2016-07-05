#!/usr/bin/php
<?php
if(!defined("ZENDERATOR_ROOT")){
    define("ZENDERATOR_ROOT", __DIR__ . "/zenderator");
}
$begin = microtime(true);
echo "PSR2ifying... \n";
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

function psr2ify($pathToPSR2)
{
    ob_start();
    echo " > {$pathToPSR2} ... ";
    $begin = microtime(true);
    exec(ZENDERATOR_ROOT . "/vendor/bin/phpcbf --standard=PSR2 {$pathToPSR2}");
    $time = microtime(true) - $begin;
    echo " [Complete in " . number_format($time, 2) . "]\n";
    echo ob_get_clean();
}

foreach ($pathsToPSR2 as $pathToPSR2) {
    if (file_exists($pathToPSR2)) {
        psr2ify($pathToPSR2);
    }
}

$time = microtime(true) - $begin;
echo "[ALL DONE]";
echo " [Complete in " . number_format($time, 2) . "]\n";
