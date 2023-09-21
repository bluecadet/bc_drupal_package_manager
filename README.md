# Bluecadet Drupal Module/Package Manager

Adds functionality to check for updates for custom Drupal modules built by Bluecadet.

EX.

```php

use Bluecadet\DrupalPackageManager\Checker;

/**
 * Implements hook_update_status_alter().
 */
function HOOK_update_status_alter(&$projects) {
  $modules['bluecadet'] = [
    'bluecadet_utilities',
    'bluecadet_file_struct',
  ];

  $filtered_projects = [];

  foreach ($modules as $user => $user_data) {
    $filtered_projects += array_filter($projects, function($v) use ($user_data) {
        return in_array($v, $user_data);
    }, ARRAY_FILTER_USE_KEY);
  }

  $checker = new Checker($modules, $filtered_projects);

  foreach ($modules as $user => $user_data) {
    foreach ($user_data as $module_name) {
      if (in_array($module_name, array_keys($projects))) {
        $projects[$module_name] = $checker->updateDrupalModulePackage($projects[$module_name], $user, $module_name);
      }
    }
  }
}

```
