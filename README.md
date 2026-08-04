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

## TODO

- **Caching**: Packagist responses are currently fetched live on every call to `getUpdates()`. Since this package isn't a Drupal module and shouldn't assume `\Drupal` is bootstrapped, add support for injecting a cache backend (rather than hard-coding Drupal's Cache API) so repeated update checks don't re-fetch from Packagist every time.
- **Logging**: `Checker` currently logs warnings via PHP's `error_log()`. Add support for injecting a PSR-3 `LoggerInterface`, which a caller could satisfy with a Drupal logger channel (`\Drupal::logger(...)`), Monolog, or any other PSR-3 logger.
