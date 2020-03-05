<?php
/**
 * ====================================================================
 * COPY THE SETTINGS BELOW TO A SETTINGS.LOCAL.PHP FILE TO OVERRIDE.
 * ====================================================================
 */

// Default directory for drupal installs.
$settings['drupal_root'] = 'drupal8';

// Duration to run symfony process commands. If you have a slow laptop
// or net connection you may need to increase this.
$settings['timeout'] = '3600';

/**
 * ====================================================================
 * DO NOT COPY ANYTHING BELOW THIS LINE TO YOUR SETTINGS.LOCAL.PHP FILE
 * ====================================================================
 */
if (file_exists(__DIR__.'/settings.local.php')) {
    include_once __DIR__.'/settings.local.php';
}