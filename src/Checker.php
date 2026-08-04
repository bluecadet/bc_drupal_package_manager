<?php

namespace Bluecadet\DrupalPackageManager;

use Drupal\update\UpdateManagerInterface;
use z4kn4fein\SemVer\Inc;
use z4kn4fein\SemVer\SemverException;
use z4kn4fein\SemVer\Version;

class Checker {

  protected $modules = [];
  protected $projects = [];

  protected $packagistData = [];

  protected $errors = [];
  protected $warnings = [];
  protected $info = [];


  protected $links = [];
  protected $titles = [];
  protected $statuses = [];
  protected $latestVersions = [];
  protected $recommended = [];
  protected $also = [];
  protected $releases = [];

  public function __construct(array $modules, array $projects) {
    $this->modules = $modules;
    $this->projects = $projects;
  }

  /**
   * Records a warning against a module and logs it, so failures are never
   * silently discarded even if a caller never inspects getWarnings().
   *
   * TODO: this package isn't a Drupal module and shouldn't assume \Drupal
   * is bootstrapped, so this uses error_log() rather than Drupal's logger.
   * Add support for injecting a PSR-3 LoggerInterface (which a caller could
   * satisfy with a Drupal logger channel, Monolog, etc.) so consumers can
   * route these warnings wherever they like.
   */
  protected function logWarning(string $module_name, string $message): void {
    $this->warnings[$module_name][] = $message;
    error_log("[bc_drupal_package_manager] $module_name: $message");
  }

  /**
   * Returns all warnings recorded so far, keyed by module name.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  public function getUpdates():void {

    $moduleHandler = \Drupal::service('module_handler');
    $this->getPackagistData();

    foreach ($this->modules as $user => $user_mods) {
      foreach ($user_mods as $module_name) {
        $package_name = "$user/$module_name";
        $packagist_base = "https://packagist.org/packages/" . $user . "/" . $module_name;

        try {
          if ($moduleHandler->moduleExists($module_name)) {
            $this->links[$user][$module_name] = $packagist_base;
            $this->titles[$user][$module_name] = $this->projects[$module_name]['info']['name'];

            $exisiting_version = NULL;
            try {
              if (isset($this->projects[$module_name]['existing_version']) && $this->validVersionString($this->projects[$module_name]['existing_version'], FALSE)) {
                $exisiting_version = Version::parse($this->projects[$module_name]['existing_version'], FALSE);
              }
            }
            catch (SemverException $e) {
              $this->logWarning($module_name, 'Could not parse existing version: ' . $e->getMessage());
              continue;
            }

            if (!$exisiting_version instanceof Version) {
              $this->logWarning($module_name, 'No valid existing version available for ' . $module_name . '; skipping update check.');
              continue;
            }

            if (!isset($this->packagistData[$user][$module_name]['packages'][$package_name]) || !is_array($this->packagistData[$user][$module_name]['packages'][$package_name])) {
              $this->logWarning($module_name, 'No Packagist data available for ' . $package_name . '; skipping update check.');
              continue;
            }

            $packages = $this->packagistData[$user][$module_name]['packages'][$package_name];

            // Sort packages from Packagist lowest to highest.
            usort($packages, [$this, 'orderPackages']);

            $this->statuses[$user][$module_name] = UpdateManagerInterface::CURRENT;

            foreach ($packages as $package_data) {

              try {

                if (isset($package_data['version']) && $this->validVersionString($package_data['version'], FALSE)) {
                  $release_version = Version::parse($package_data['version'], FALSE);

                  if ($exisiting_version->isLessThan($release_version)) {

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

                    // I want to see all versions higher than current regardless of stability.
                    $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor()] = $package_data['version'];

                    // Any newer release (regardless of major) means the
                    // module is no longer current.
                    if (!$release_version->isPreRelease()) {
                      $this->statuses[$user][$module_name] = UpdateManagerInterface::NOT_CURRENT;

                      // The recommended version is the highest stable
                      // release within the currently installed major.
                      if ($exisiting_version->getMajor() == $release_version->getMajor()) {
                        $this->recommended[$user][$module_name] = $package_data['version'];
                      }
                    }

                    if ($release_version->isPreRelease() && $exisiting_version->getMajor() == $release_version->getMajor() && $exisiting_version->getMinor() == $release_version->getMinor()) {
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
          }
        }
        catch (\Throwable $e) {
          $this->logWarning($module_name, 'Caught exception while checking release data: ' . $e->getMessage());
        }
      }
    }
  }

  protected function getPackagistData() {

    foreach ($this->modules as $user => $user_mods) {
      foreach ($user_mods as $module_name) {
        try {
          $package_name = $user . '/' . $module_name;
          $url = "https://repo.packagist.org/p2/" . rawurlencode($user) . "/" . rawurlencode($module_name) . ".json";

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
            curl_close($ch);
            continue;
          }

          $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);

          if ($status_code !== 200) {
            $this->logWarning($module_name, "Packagist returned HTTP $status_code for $package_name.");
            continue;
          }

          $data = json_decode($result, TRUE);

          if (!is_array($data)) {
            $this->logWarning($module_name, 'Could not decode Packagist response for ' . $package_name . '.');
            continue;
          }

          $this->packagistData[$user][$module_name] = $data;
        }
        catch (\Throwable $e) {
          $this->logWarning($module_name, 'Caught exception while fetching Packagist data: ' . $e->getMessage());
        }
      }
    }
  }

  protected function validVersionString(string $version, bool $strict = FALSE):bool {
    try {
      Version::parse($version, $strict);
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return TRUE;
  }

  protected function orderPackages($a, $b) {
    try {
      return Version::compare(Version::parse($a['version'], FALSE), Version::parse($b['version'], FALSE));
    }
    catch (\Exception $e) {
      return 0;
    }
  }





  public function getLink(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->links[$user][$module] ?? "";
  }

  public function getTitle(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->titles[$user][$module] ?? "";
  }


  public function getStatus(string $user, string $module):int {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->statuses[$user][$module] ?? 0;
  }

  public function getReleases(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->releases[$user][$module] ?? [];
  }

  public function getAlso(string $user, string $module):array {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->also[$user][$module] ?? [];
  }

  public function getLatestVersion(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->latestVersions[$user][$module] ?? "";
  }

  public function getRecommended(string $user, string $module):string {
    if (empty($this->packagistData)) {
      $this->getUpdates();
    }

    return $this->recommended[$user][$module] ?? "";
  }

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

    return $package;
  }

}
