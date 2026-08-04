<?php

namespace Bluecadet\DrupalPackageManager;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\update\UpdateManagerInterface;
use z4kn4fein\SemVer\SemverException;
use z4kn4fein\SemVer\Version;

/**
 * Checks Packagist for available updates to custom Bluecadet Drupal modules.
 *
 * Computes the same status/recommended/latest_version/releases data that
 * Drupal's own update.module calculates for drupal.org-hosted projects, but
 * sourced from Packagist and composer.json metadata instead, so it can be
 * merged into $projects from hook_update_status_alter().
 */
class Checker {

  /**
   * The composer.json "extra" key holding Bluecadet's release-status data.
   *
   * Points at the minimum_supported/recommended/security metadata
   * described in the README.
   */
  const EXTRA_KEY = 'bluecadet-package-manager';

  /**
   * The modules to check, keyed by Packagist vendor name.
   *
   * @var array
   *   An array of arrays of module machine names, keyed by vendor name, e.g.
   *   ['bluecadet' => ['bluecadet_utilities', 'bluecadet_file_struct']].
   */
  protected $modules = [];

  /**
   * The Drupal project data passed in from hook_update_status_alter().
   *
   * @var array
   *   An array of project data, keyed by module machine name.
   */
  protected $projects = [];

  /**
   * The module handler used to check whether a module is enabled.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Packagist release data fetched via getPackagistData().
   *
   * @var array
   *   An array of version => release-data maps, keyed by vendor name and
   *   then module machine name.
   */
  protected $packagistData = [];

  /**
   * Unused: reserved for future error tracking.
   *
   * @var array
   */
  protected $errors = [];

  /**
   * Warnings recorded by logWarning(), keyed by module machine name.
   *
   * @var array
   */
  protected $warnings = [];

  /**
   * Unused: reserved for future informational messages.
   *
   * @var array
   */
  protected $info = [];

  /**
   * Packagist project links, keyed by vendor name and then module name.
   *
   * @var array
   */
  protected $links = [];

  /**
   * Project titles, keyed by vendor name and then module name.
   *
   * @var array
   */
  protected $titles = [];

  /**
   * UpdateManagerInterface status constants, keyed by vendor and module name.
   *
   * @var array
   */
  protected $statuses = [];

  /**
   * The highest available version newer than what's installed.
   *
   * Keyed by vendor name and then module name.
   *
   * @var array
   */
  protected $latestVersions = [];

  /**
   * The recommended version to update to, keyed by vendor and module name.
   *
   * @var array
   */
  protected $recommended = [];

  /**
   * Other notable available versions for a module.
   *
   * Higher majors and pre-releases on the current branch, keyed by vendor
   * name, module name, and then a "major.minor" or "major.minor.x" branch
   * label.
   *
   * @var array
   */
  protected $also = [];

  /**
   * Release data for every version newer than what's installed.
   *
   * Keyed by vendor name, module name, and then version string.
   *
   * @var array
   */
  protected $releases = [];

  /**
   * Release data for versions flagged as security releases.
   *
   * Sourced from composer.json's "extra" key, keyed by vendor name and
   * then module name.
   *
   * @var array
   */
  protected $securityUpdates = [];

  /**
   * Drupal-native {class, label, data} admin notices.
   *
   * Keyed by vendor name and then module name.
   *
   * @var array
   */
  protected $extra = [];

  /**
   * Constructs a Checker.
   *
   * @param array $modules
   *   The modules to check, keyed by Packagist vendor name; see $modules.
   * @param array $projects
   *   Drupal's project data, keyed by module machine name; see $projects.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $moduleHandler
   *   The module handler to use, or NULL to look one up via
   *   \Drupal::service('module_handler').
   */
  public function __construct(array $modules, array $projects, ?ModuleHandlerInterface $moduleHandler = NULL) {
    $this->modules = $modules;
    $this->projects = $projects;
    $this->moduleHandler = $moduleHandler ?? \Drupal::service('module_handler');
  }

  /**
   * Records a warning against a module and logs it.
   *
   * This ensures failures are never silently discarded even if a caller
   * never inspects getWarnings().
   *
   * @param string $module_name
   *   The module machine name the warning applies to.
   * @param string $message
   *   The warning message.
   *
   * @todo This package isn't a Drupal module and shouldn't assume \Drupal
   *   is bootstrapped, so this uses error_log() rather than Drupal's logger.
   *   Add support for injecting a PSR-3 LoggerInterface (which a caller
   *   could satisfy with a Drupal logger channel, Monolog, etc.) so
   *   consumers can route these warnings wherever they like.
   */
  protected function logWarning(string $module_name, string $message): void {
    $this->warnings[$module_name][] = $message;
    error_log("[bc_drupal_package_manager] $module_name: $message");
  }

  /**
   * Returns all warnings recorded so far, keyed by module name.
   *
   * @return array
   *   An array of arrays of warning message strings, keyed by module
   *   machine name.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Fetches Packagist data and calculates update status for every module.
   *
   * Populates $links, $titles, $statuses, $releases, $also,
   * $latestVersions, $recommended, $securityUpdates, and $extra for every
   * module in $modules. Called lazily by the get*() methods below the
   * first time any of them is invoked.
   */
  public function getUpdates():void {

    $this->getPackagistData();

    foreach ($this->modules as $user => $user_mods) {
      foreach ($user_mods as $module_name) {
        $package_name = "$user/$module_name";
        $packagist_base = "https://packagist.org/packages/" . $user . "/" . $module_name;

        try {
          if ($this->moduleHandler->moduleExists($module_name)) {
            $this->links[$user][$module_name] = $packagist_base;
            $this->titles[$user][$module_name] = $this->projects[$module_name]['info']['name'];

            $existing_version = NULL;
            try {
              if (isset($this->projects[$module_name]['existing_version']) && $this->validVersionString($this->projects[$module_name]['existing_version'], FALSE)) {
                $existing_version = Version::parse($this->projects[$module_name]['existing_version'], FALSE);
              }
            }
            catch (SemverException $e) {
              $this->logWarning($module_name, 'Could not parse existing version: ' . $e->getMessage());
              continue;
            }

            if (!$existing_version instanceof Version) {
              $this->logWarning($module_name, 'No valid existing version available for ' . $module_name . '; skipping update check.');
              continue;
            }

            if (!isset($this->packagistData[$user][$module_name]) || !is_array($this->packagistData[$user][$module_name])) {
              $this->logWarning($module_name, 'No Packagist data available for ' . $package_name . '; skipping update check.');
              continue;
            }

            $packages = array_values($this->packagistData[$user][$module_name]);

            // Sort packages from Packagist lowest to highest.
            usort($packages, [$this, 'orderPackages']);

            $this->statuses[$user][$module_name] = UpdateManagerInterface::CURRENT;

            foreach ($packages as $package_data) {

              try {

                if (isset($package_data['version']) && $this->validVersionString($package_data['version'], FALSE)) {
                  $release_version = Version::parse($package_data['version'], FALSE);

                  if ($existing_version->isLessThan($release_version)) {

                    // Create release data.
                    $release_data = [
                      'name' => $this->projects[$module_name]['name'],
                      'version' => $package_data['version'],
                      'tag' => $package_data['version'],
                      'status' => "published",
                      'release_link' => $packagist_base . "#" . $package_data['version'],
                      'download_link' => $packagist_base . "#" . $package_data['version'],
                      'date' => strtotime($package_data['time']),
                      'files' => "",
                      'terms' => [],
                      'security' => "",
                    ];

                    $this->releases[$user][$module_name][$package_data['version']] = $release_data;

                    // Packages are processed lowest to highest, so the last
                    // one assigned here ends up being the highest available.
                    $this->latestVersions[$user][$module_name] = $package_data['version'];

                    // Every version higher than current is recorded here,
                    // regardless of stability.
                    $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor()] = $package_data['version'];

                    // Any newer release (regardless of major) means the
                    // module is no longer current.
                    if (!$release_version->isPreRelease()) {
                      $this->statuses[$user][$module_name] = UpdateManagerInterface::NOT_CURRENT;

                      // The recommended version is the highest stable
                      // release within the currently installed major.
                      if ($existing_version->getMajor() == $release_version->getMajor()) {
                        $this->recommended[$user][$module_name] = $package_data['version'];
                      }
                    }

                    if ($release_version->isPreRelease() && $existing_version->getMajor() == $release_version->getMajor() && $existing_version->getMinor() == $release_version->getMinor()) {
                      $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor() . ".x"] = $package_data['version'];
                    }
                  }
                }
              }
              catch (SemverException $e) {
                $this->logWarning($module_name, 'Could not parse release version: ' . $e->getMessage());
                continue;
              }
              catch (\Throwable $e) {
                $this->logWarning($module_name, 'Caught exception while checking release: ' . $e->getMessage());
              }
            }

            $this->applyReleaseStatusMetadata($user, $module_name, $existing_version, $packages);
          }
        }
        catch (\Throwable $e) {
          $this->logWarning($module_name, 'Caught exception while checking release data: ' . $e->getMessage());
        }
      }
    }
  }

  /**
   * Applies maintainer-curated release-status metadata on top of getUpdates().
   *
   * Reads the "bluecadet-package-manager" block from composer.json's
   * "extra" key on whichever release currently has the highest version
   * (older tags can't be edited after the fact, so this is always read
   * from a single, current source), and uses it to override/augment the
   * automatic semver-based calculations already performed in getUpdates().
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module_name
   *   The module machine name.
   * @param \z4kn4fein\SemVer\Version $existing_version
   *   The currently installed version.
   * @param array $packages
   *   The full list of release data for this module, as returned by
   *   Packagist.
   */
  protected function applyReleaseStatusMetadata(string $user, string $module_name, Version $existing_version, array $packages): void {
    $latest_release = $this->findLatestRelease($packages);
    $extra = $latest_release['extra'][self::EXTRA_KEY] ?? NULL;

    if (!is_array($extra)) {
      return;
    }

    // A maintainer-curated recommendation overrides the automatic "highest
    // stable release in the current major" calculation from getUpdates().
    if ($match = $this->findMatchingBranchVersion($existing_version, $extra['recommended'] ?? [])) {
      $this->recommended[$user][$module_name] = (string) $match;
    }

    // Flag sites running below the minimum supported version for their
    // branch via Drupal's native "extra" admin-notice mechanism.
    if (($match = $this->findMatchingBranchVersion($existing_version, $extra['minimum_supported'] ?? [])) && $existing_version->isLessThan($match)) {
      $this->extra[$user][$module_name][] = [
        'class' => ['bluecadet-below-minimum-supported'],
        'label' => 'Below minimum supported version',
        'data' => "The installed version ($existing_version) is older than the minimum supported version ($match) for this branch. Update as soon as possible.",
      ];
    }

    // Mark any release already recorded in $this->releases as a security
    // release, matching the shape Drupal's own ProjectRelease::
    // isSecurityRelease() expects, and collect them for "security updates".
    foreach ($extra['security'] ?? [] as $security_version) {
      if (!is_string($security_version) || !isset($this->releases[$user][$module_name][$security_version])) {
        continue;
      }
      $this->releases[$user][$module_name][$security_version]['terms'] = ['Release type' => ['Security update']];
      $this->securityUpdates[$user][$module_name][] = $this->releases[$user][$module_name][$security_version];
    }
  }

  /**
   * Fetches release data for every module from Packagist and caches it.
   *
   * Populates $packagistData with a version => release-data map for each
   * module, keyed by vendor name and then module machine name. Uses
   * Packagist's plain package API rather than the p2/ "provider" endpoint;
   * see the comment above the $url assignment below for why.
   */
  protected function getPackagistData() {

    foreach ($this->modules as $user => $user_mods) {
      foreach ($user_mods as $module_name) {
        try {
          $package_name = $user . '/' . $module_name;

          // The p2/ "provider" endpoint is meant for Composer's own
          // dependency resolver and minifies repeated values (including
          // "extra") down to the literal string "__unset", so it isn't
          // reliable for reading custom composer.json "extra" metadata.
          //
          // The plain package API returns full, unminified data instead.
          $url = "https://packagist.org/packages/" . rawurlencode($user) . "/" . rawurlencode($module_name) . ".json";

          // Initiate curl and get info from Packagist.
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
          curl_setopt($ch, CURLOPT_TIMEOUT, 10);
          curl_setopt($ch, CURLOPT_USERAGENT, 'bluecadet/bc_drupal_package_manager');
          $result = curl_exec($ch);

          if ($result === FALSE) {
            $this->logWarning($module_name, 'Curl error fetching Packagist data for ' . $package_name . ': ' . curl_error($ch));
            continue;
          }

          $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

          if ($status_code !== 200) {
            $this->logWarning($module_name, "Packagist returned HTTP $status_code for $package_name.");
            continue;
          }

          $data = json_decode($result, TRUE);

          if (!isset($data['package']['versions']) || !is_array($data['package']['versions'])) {
            $this->logWarning($module_name, 'Could not decode Packagist response for ' . $package_name . '.');
            continue;
          }

          $this->packagistData[$user][$module_name] = $data['package']['versions'];
        }
        catch (\Throwable $e) {
          $this->logWarning($module_name, 'Caught exception while fetching Packagist data: ' . $e->getMessage());
        }
      }
    }
  }

  /**
   * Determines whether a version string can be parsed as a semantic version.
   *
   * @param string $version
   *   The version string to check.
   * @param bool $strict
   *   Whether to require strict SemVer compliance. FALSE (the default)
   *   allows partial versions like "1.0".
   *
   * @return bool
   *   TRUE if the version string is parseable, FALSE otherwise.
   */
  protected function validVersionString(string $version, bool $strict = FALSE):bool {
    try {
      Version::parse($version, $strict);
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Comparison callback for usort(), ordering release data low to high.
   *
   * @param array $a
   *   Release data with a 'version' key.
   * @param array $b
   *   Release data with a 'version' key.
   *
   * @return int
   *   A negative, zero, or positive integer, per usort()'s contract. Also
   *   returns 0 (treating the pair as equal) if either version fails to
   *   parse, e.g. a "dev-main" branch entry.
   */
  protected function orderPackages($a, $b) {
    try {
      return Version::compare(Version::parse($a['version'], FALSE), Version::parse($b['version'], FALSE));
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Finds the release with the highest parseable version in $packages.
   *
   * This is independent of whether it's newer than any particular existing
   * version. It's the release whose composer.json "extra" data is treated
   * as the authoritative source for minimum_supported/recommended/security,
   * since older tags can't be edited after the fact.
   *
   * @param array $packages
   *   The full list of release data for a module, as returned by Packagist.
   *
   * @return array|null
   *   The release data for the highest parseable version, or NULL if none
   *   of the entries in $packages have a parseable version.
   */
  protected function findLatestRelease(array $packages): ?array {
    $latest_version = NULL;
    $latest_release = NULL;

    foreach ($packages as $package_data) {
      if (!isset($package_data['version']) || !$this->validVersionString($package_data['version'], FALSE)) {
        continue;
      }
      try {
        $version = Version::parse($package_data['version'], FALSE);
      }
      catch (SemverException $e) {
        continue;
      }
      if ($latest_version === NULL || $latest_version->isLessThan($version)) {
        $latest_version = $version;
        $latest_release = $package_data;
      }
    }

    return $latest_release;
  }

  /**
   * Picks the entry in $candidates that best matches $target's branch.
   *
   * Prefers an exact major.minor match if one exists, otherwise falls back
   * to the highest entry sharing the same major, otherwise returns NULL if
   * nothing matches.
   *
   * @param \z4kn4fein\SemVer\Version $target
   *   The version whose branch to match against.
   * @param array $candidates
   *   An array of version strings to search, e.g. from composer.json's
   *   "extra" "recommended"/"minimum_supported" lists.
   *
   * @return \z4kn4fein\SemVer\Version|null
   *   The best-matching candidate version, or NULL if none match.
   */
  protected function findMatchingBranchVersion(Version $target, array $candidates): ?Version {
    $same_major_minor = NULL;
    $same_major = NULL;

    foreach ($candidates as $candidate) {
      if (!is_string($candidate) || !$this->validVersionString($candidate, FALSE)) {
        continue;
      }
      try {
        $candidate_version = Version::parse($candidate, FALSE);
      }
      catch (SemverException $e) {
        continue;
      }
      if ($candidate_version->getMajor() !== $target->getMajor()) {
        continue;
      }
      if ($candidate_version->getMinor() === $target->getMinor()) {
        $same_major_minor = $candidate_version;
      }
      if ($same_major === NULL || $same_major->isLessThan($candidate_version)) {
        $same_major = $candidate_version;
      }
    }

    return $same_major_minor ?? $same_major;
  }

  /**
   * Returns the Packagist project link for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return string
   *   The Packagist project URL, or an empty string if unavailable.
   */
  public function getLink(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->links[$user][$module] ?? "";
  }

  /**
   * Returns the human-readable project title for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return string
   *   The project title, or an empty string if unavailable.
   */
  public function getTitle(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->titles[$user][$module] ?? "";
  }

  /**
   * Returns the update status for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return int
   *   One of the \Drupal\update\UpdateManagerInterface status constants, or
   *   0 if the status could not be determined.
   */
  public function getStatus(string $user, string $module):int {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->statuses[$user][$module] ?? 0;
  }

  /**
   * Returns release data for every version newer than what's installed.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return array
   *   An array of Drupal-shaped release data, keyed by version string.
   */
  public function getReleases(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->releases[$user][$module] ?? [];
  }

  /**
   * Returns other notable available versions for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return array
   *   An array of version strings, keyed by "major.minor" (a higher major
   *   branch) or "major.minor.x" (a pre-release on the current branch).
   */
  public function getAlso(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->also[$user][$module] ?? [];
  }

  /**
   * Returns the highest available version for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return string
   *   The highest available version newer than what's installed, or an
   *   empty string if there isn't one.
   */
  public function getLatestVersion(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->latestVersions[$user][$module] ?? "";
  }

  /**
   * Returns the recommended version to update to for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return string
   *   The recommended version, or an empty string if there isn't one.
   */
  public function getRecommended(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->recommended[$user][$module] ?? "";
  }

  /**
   * Returns release data for versions flagged as security releases.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return array
   *   An array of Drupal-shaped release data for every version listed in
   *   composer.json's "extra" "security" list.
   */
  public function getSecurityUpdates(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->securityUpdates[$user][$module] ?? [];
  }

  /**
   * Returns Drupal-native admin notices for a module.
   *
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module
   *   The module machine name.
   *
   * @return array
   *   An array of {class, label, data} notices, e.g. flagging that a site
   *   is below the minimum supported version for its branch.
   */
  public function getExtra(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->extra[$user][$module] ?? [];
  }

  /**
   * Merges this module's calculated update data into a Drupal project array.
   *
   * @param array $package
   *   The project data for this module, as passed into
   *   hook_update_status_alter().
   * @param string $user
   *   The Packagist vendor name.
   * @param string $module_name
   *   The module machine name.
   *
   * @return array
   *   The $package array, with link/title/status/releases/also/
   *   latest_version/recommended/"security updates"/extra merged in.
   */
  public function updateDrupalModulePackage(array $package, string $user, string $module_name):array {

    $package['link'] = $this->getLink($user, $module_name);
    $package['title'] = $this->getTitle($user, $module_name);
    $package['status'] = $this->getStatus($user, $module_name);

    if ($releases = $this->getReleases($user, $module_name)) {
      $package['releases'] = $releases;
    }
    if ($also = $this->getAlso($user, $module_name)) {
      $package['also'] = $also;
    }

    if ($latest_version = $this->getLatestVersion($user, $module_name)) {
      $package['latest_version'] = $latest_version;
    }
    if ($recommended = $this->getRecommended($user, $module_name)) {
      $package['recommended'] = $recommended;
    }
    if ($security_updates = $this->getSecurityUpdates($user, $module_name)) {
      $package['security updates'] = $security_updates;
    }
    if ($extra = $this->getExtra($user, $module_name)) {
      $package['extra'] = array_merge($package['extra'] ?? [], $extra);
    }

    return $package;
  }

}
