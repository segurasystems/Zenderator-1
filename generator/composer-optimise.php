<?php
$begin = microtime(true);
echo "Optimising Composer Autoloader... \n";
exec("composer dump-autoload -o");
$time = microtime(true) - $begin;
echo " [Complete in " . number_format($time, 2) . "]\n";
