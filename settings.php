<?php

$settings['drupal_root'] = 'drupal8';
$settings['timeout'] = '3600';

if (file_exists(__DIR__.'/settings.local.php')) {
    include_once __DIR__.'/settings.local.php';
}