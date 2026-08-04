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

## Release status metadata

Tag whichever release currently has the highest version with an `extra` block in its `composer.json`, naming supported/recommended/security-fixing versions per branch. Since older tags can't be edited after the fact, `Checker` always reads this from the single highest-versioned release across the whole package, so it must be kept up to date whenever any actively-maintained branch cuts a release:

```json
{
  "extra": {
    "bluecadet-package-manager": {
      "minimum_supported": ["1.4.12", "1.5.8", "1.6.2", "2.1.0"],
      "recommended": ["1.5.8", "1.6.2", "2.1.0"],
      "security": ["1.5.8", "1.6.1", "2.0.1", "2.1.0"]
    }
  }
}
```

- `minimum_supported` — the floor version for every branch still receiving fixes, whether or not it's actively recommended.
- `recommended` — a subset of the above: the branches you actively want people to move to.
- `security` — a running, cumulative history of every release that ever shipped a security fix (including from branches no longer listed above). Never edit old entries out — just keep appending as new fixes ship.

`Checker` matches a site's `existing_version` to these lists by major.minor branch (falling back to the highest entry sharing the same major if there's no exact minor match), and:

- overrides the automatic "highest stable release in the current major" pick with the matching `recommended` entry, if any;
- adds a Drupal-native `extra` admin notice if `existing_version` is below the matching `minimum_supported` entry;
- marks any release listed in `security` with `'terms' => ['Release type' => ['Security update']]` (the shape `Drupal\update\ProjectRelease::isSecurityRelease()` expects) and surfaces it via `security updates`.

## Testing

```bash
composer install
composer test
```

## Coding standards

This package follows Drupal's coding standards (via [drupal/coder](https://www.drupal.org/project/coder)), even though it isn't a Drupal module itself:

```bash
composer install
composer lint
```

## TODO

- **Caching**: Packagist responses are currently fetched live on every call to `getUpdates()`. Since this package isn't a Drupal module and shouldn't assume `\Drupal` is bootstrapped, add support for injecting a cache backend (rather than hard-coding Drupal's Cache API) so repeated update checks don't re-fetch from Packagist every time.
- **Logging**: `Checker` currently logs warnings via PHP's `error_log()`. Add support for injecting a PSR-3 `LoggerInterface`, which a caller could satisfy with a Drupal logger channel (`\Drupal::logger(...)`), Monolog, or any other PSR-3 logger.
