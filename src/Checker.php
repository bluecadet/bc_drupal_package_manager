<?php

namespace Bluecadet\DrupalPackageManager;

use Drupal\update\UpdateManagerInterface;
use Melbahja\Semver\Semver;

class Checker {

  protected $modules = [];
  protected $projects = [];

  protected $packagistData = [];
  // protected $packagistData = [];

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

            $exisiting_version = $this->projects[$module_name]['existing_version'] ? Semver::parse($this->projects[$module_name]['existing_version']) : "";
            $packages = $this->packagistData[$user][$module_name]['packages'][$package_name];

            // ksm($exisiting_version, $packages);

            // Sort pakcages from packagist lowest to highest.
            uasort($packages, [$this, 'orderPackages']);

            // $this->projects[$module_name]['status'] = UpdateManagerInterface::CURRENT;
            $this->statuses[$user][$module_name] = UpdateManagerInterface::CURRENT;

            // ksm($packages);

            foreach ($packages as $package_data) {
              try {

                $release_version = Semver::parse($package_data['version']);

                if ($exisiting_version->compare($release_version, "<")) {

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

                  // Is is also?
                  if ($exisiting_version->getMajor() < $release_version->getMajor()) {
                    $this->statuses[$user][$module_name] = UpdateManagerInterface::NOT_CURRENT;
                    $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor()] = $package_data['version'];
                  }
                  elseif ($exisiting_version->getMajor() == $release_version->getMajor() && $exisiting_version->getMinor() < $release_version->getMinor()) {
                    $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor()] = $package_data['version'];
                  }

                  // Is it latest?
                  // Is it recommended?
                  if ($exisiting_version->getMajor() == $release_version->getMajor()) {
                    $this->latestVersion[$user][$module_name] = $package_data['version'];
                    $this->recommended[$user][$module_name] = $package_data['version'];

                    if ($exisiting_version != $release_version) {
                      $this->statuses[$user][$module_name] = UpdateManagerInterface::NOT_CURRENT;
                    }
                  }
                }
              }
              catch (\Exception $e) {
                $this->warnings[$module_name][] = 'Caught exception while checking release: ' . $e->getMessage();
              }
            }
          }
        }
        catch  (\Exception $e) {
          ksm("data error");
        }
      }
    }
  }

  protected function getPackagistData() {

    foreach ($this->modules as $user => $user_mods) {
      foreach ($user_mods as $module_name) {
        try {
          $package_name = $user . '/' . $module_name;
          $packagist_base = "https://packagist.org/packages/" . $user . "/" . $module_name;
          $url = "https://repo.packagist.org/p2/" . $user . "/$module_name.json";

          // Initiate curl and get info from Packagist.
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
          curl_setopt($ch, CURLOPT_URL, $url);
          $result = curl_exec($ch);
          curl_close($ch);

          $this->packagistData[$user][$module_name] = json_decode($result, TRUE);
        }
        catch  (\Exception $e) {
          ksm("curl error");
        }
      }
    }
  }

  protected function orderPackages($a, $b) {
    if (Semver::compare($a['version'], $b['version'])) {
      return 0;
    }
    return Semver::compare($a['version'], $b['version'], '>') ? 1 : -1;
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

    return $this->latestVersion[$user][$module] ?? "";
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
