<?php

namespace Bluecadet\DrupalPackageManager\Tests;

use Bluecadet\DrupalPackageManager\Checker;

/**
 * Checker subclass that lets tests inject Packagist data directly,
 * bypassing the real curl_*() calls in getPackagistData().
 */
class TestableChecker extends Checker {

  public function setPackagistData(array $data): void {
    $this->packagistData = $data;
  }

  protected function getPackagistData() {
    // No-op: data is injected via setPackagistData() instead of fetched.
  }

}
