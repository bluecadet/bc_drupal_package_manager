<?php

namespace Bluecadet\DrupalPackageManager\Tests;

use Bluecadet\DrupalPackageManager\Checker;

/**
 * Checker subclass that lets tests inject Packagist data directly.
 *
 * Bypasses the real curl_*() calls in getPackagistData(), so tests never
 * hit the network.
 */
class TestableChecker extends Checker {

  /**
   * Injects Packagist release data directly, bypassing getPackagistData().
   *
   * @param array $data
   *   Release data in the same shape getPackagistData() would populate:
   *   a version => release-data map, keyed by vendor name and then module
   *   machine name.
   */
  public function setPackagistData(array $data): void {
    $this->packagistData = $data;
  }

  /**
   * No-op: data is injected via setPackagistData() instead of fetched.
   */
  protected function getPackagistData() {
  }

}
