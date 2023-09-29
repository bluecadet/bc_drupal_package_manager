<?php

namespace Bluecadet\DrupalPackageManager;

use Drupal\update\UpdateManagerInterface;
use z4kn4fein\SemVer\Constraints\Constraint;
use z4kn4fein\SemVer\Inc;
use z4kn4fein\SemVer\SemverException;
use z4kn4fein\SemVer\Version;

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

            try {
              $exisiting_version = "";
              if (isset($this->projects[$module_name]['existing_version']) && $this->validVersionString($this->projects[$module_name]['existing_version'], FALSE)) {
                $exisiting_version = Version::parse($this->projects[$module_name]['existing_version'], FALSE);
              }
            }
            catch (SemverException $e) {
              // ksm($e);
              break;
            }

            $packages = $this->packagistData[$user][$module_name]['packages'][$package_name];

            // Sort pakcages from packagist lowest to highest.
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

                    // Is is also?
                    if (Version::lessThan($exisiting_version, $release_version)) {

                      if (!$release_version->isPreRelease()) {
                        $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor()] = $package_data['version'];
                      }

                      // Is it latest?
                      // Is it recommended?
                      if ($exisiting_version->getMajor() == $release_version->getMajor()) {

                        $constraint = Constraint::parse("^" . $exisiting_version->__toString());
                        if (!$release_version->isPreRelease()) {
                          $this->statuses[$user][$module_name] = UpdateManagerInterface::NOT_CURRENT;
                        }


                        if ($release_version->isPreRelease() && $exisiting_version->getMinor() == $release_version->getMinor())  {
                          $this->also[$user][$module_name][$release_version->getMajor() . "." . $release_version->getMinor() . ".x"] = $package_data['version'];
                        }
                      }
                    }
                  }
                }
              }
              catch (SemverException $e) {
                // ksm($e);
                break;
              }
              catch (\Exception $e) {
                $this->warnings[$module_name][] = 'Caught exception while checking release: ' . $e->getMessage();
                // ksm($e);
              }
            }
          }
        }
        catch  (\Exception $e) {
          // ksm($e);
          // ksm("data error");
          $this->warnings[$module_name][] = 'Caught exception while checking release data: ' . $e->getMessage();
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

      // ksm($a['version'], $b['version'], Version::compare(Version::parse($a['version'], FALSE), Version::parse($b['version'], FALSE), '>'));
      return Version::compare(Version::parse($a['version'], FALSE), Version::parse($b['version'], FALSE), '>');
    }
    catch(\Exception $e) {
      // ksm("error", $a['version'], $b['version']);
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
