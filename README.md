# Maestro

### Features

- Choose to clone from an existing branch or release tag. 
- Import from a database dump. 
- Backup and restore any existing 'sites' directory.
- Replace settings.php by placing a drupal.settings.php file in the root app directory.
- Override the default maestro settings by copying config from settings.php to settings.local.php
- Run just the update commands against an existing site.

### Installation
 1. Clone this repo.
 2. Open a shell prompt into the new directory.
 3. run ./install 
 
 ### Usage
 The maestro command should be run within the root of a lando instance. 
 By default it will check for a drupal install within /drupal8 but this can be changed (see maestro/settings.php)
 - maestro install  - Will clone a site release or branch, optionally import a database, and run the update commands.
 - maestro update - Will only run the update commands.  
