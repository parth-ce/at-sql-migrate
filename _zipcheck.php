<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'extension_loaded_zip=' . (extension_loaded('zip') ? 'yes' : 'no') . PHP_EOL;
echo 'class_exists_ZipArchive=' . (class_exists('ZipArchive') ? 'yes' : 'no') . PHP_EOL;
echo 'php_ini_loaded_file=' . php_ini_loaded_file() . PHP_EOL;
?>
