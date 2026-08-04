<?php

namespace Bluecadet\DrupalPackageManager\Tests;

use Bluecadet\DrupalPackageManager\Checker;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\update\UpdateManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Bluecadet\DrupalPackageManager\Checker
 */
class CheckerTest extends TestCase {

  /**
   * Builds a TestableChecker with a mocked, always-enabled module handler.
   *
   * @param array $modules
   *   The modules to check, keyed by Packagist vendor name.
   * @param array $projects
   *   Drupal's project data, keyed by module machine name.
   *
   * @return \Bluecadet\DrupalPackageManager\Tests\TestableChecker
   *   The constructed checker.
   */
  protected function checker(array $modules, array $projects): TestableChecker {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(TRUE);

    return new TestableChecker($modules, $projects, $moduleHandler);
  }

  /**
   * Builds a minimal $projects entry as Drupal's update module would supply.
   */
  protected function project(string $existing_version): array {
    return [
      'name' => 'bluecadet_utilities',
      'info' => ['name' => 'Bluecadet Utilities'],
      'existing_version' => $existing_version,
    ];
  }

  /**
   * Builds a flat version => release-data map, as Packagist would return it.
   *
   * Matches the shape of package.versions from
   * https://packagist.org/packages/{vendor}/{package}.json.
   * $extra_by_version optionally attaches composer.json "extra" data to
   * specific versions, keyed by version string, to simulate
   * maintainer-curated release-status metadata.
   *
   * @param array $versions
   *   Version strings to include.
   * @param array $extra_by_version
   *   An optional map of version string to "extra" data to attach to that
   *   version's release entry.
   *
   * @return array
   *   The version => release-data map.
   */
  protected function packagistPackages(array $versions, array $extra_by_version = []): array {
    $packages = [];
    foreach ($versions as $version) {
      $packages[$version] = ['version' => $version, 'time' => '2024-01-01T00:00:00+00:00'];
      if (isset($extra_by_version[$version])) {
        $packages[$version]['extra'] = [Checker::EXTRA_KEY => $extra_by_version[$version]];
      }
    }
    return $packages;
  }

  /**
   * A newer stable release in the current major is recommended and flagged.
   */
  public function testFlagsNotCurrentForNewerStableReleaseWithinSameMajor() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(['1.0.0', '1.2.0'])],
    ]);
    $checker->getUpdates();

    $this->assertSame(UpdateManagerInterface::NOT_CURRENT, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $this->assertSame('1.2.0', $checker->getLatestVersion('bluecadet', 'bluecadet_utilities'));
    $this->assertSame('1.2.0', $checker->getRecommended('bluecadet', 'bluecadet_utilities'));

    $releases = $checker->getReleases('bluecadet', 'bluecadet_utilities');
    $this->assertArrayHasKey('1.2.0', $releases);
    $this->assertArrayNotHasKey('1.0.0', $releases);
  }

  /**
   * Regression test: a newer major release must still flag NOT_CURRENT.
   *
   * This used to leave the status at CURRENT because NOT_CURRENT was only
   * ever set inside the "same major" branch.
   */
  public function testFlagsNotCurrentForNewerMajorRelease() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.5.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(['1.5.0', '2.0.0'])],
    ]);
    $checker->getUpdates();

    $this->assertSame(UpdateManagerInterface::NOT_CURRENT, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $this->assertSame('2.0.0', $checker->getLatestVersion('bluecadet', 'bluecadet_utilities'));
    // Different major: not a same-branch upgrade, so nothing is "recommended".
    $this->assertSame('', $checker->getRecommended('bluecadet', 'bluecadet_utilities'));
  }

  /**
   * Regression test: "latest" tracking must be correct regardless of order.
   *
   * Packages are sorted before processing, and the comparator must yield
   * a real ascending sort for this to work.
   */
  public function testLatestVersionIsCorrectRegardlessOfInputOrder() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(['2.0.0', '1.1.0', '1.5.0'])],
    ]);
    $checker->getUpdates();

    $this->assertSame('2.0.0', $checker->getLatestVersion('bluecadet', 'bluecadet_utilities'));
  }

  /**
   * A pre-release patch on the current branch doesn't flip the status.
   *
   * It should appear as an "x.y.x" entry in also() instead.
   */
  public function testPreReleaseDoesNotAffectStatus() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(['1.0.1-beta1'])],
    ]);
    $checker->getUpdates();

    $this->assertSame(UpdateManagerInterface::CURRENT, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $also = $checker->getAlso('bluecadet', 'bluecadet_utilities');
    $this->assertSame('1.0.1-beta1', $also['1.0.x'] ?? NULL);
  }

  /**
   * Regression test: a missing/unparseable existing_version must not crash.
   *
   * This used to reach a method call on a bare string ("") and throw a
   * fatal Error. It should now be skipped gracefully with a warning
   * recorded instead.
   */
  public function testSkipsModuleWithMissingExistingVersionWithoutCrashing() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => ['name' => 'bluecadet_utilities', 'info' => ['name' => 'Bluecadet Utilities']]]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(['1.2.0'])],
    ]);
    $checker->getUpdates();

    $this->assertSame(0, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $this->assertNotEmpty($checker->getWarnings()['bluecadet_utilities'] ?? []);
  }

  /**
   * Regression test: missing/failed Packagist data must not crash.
   *
   * This used to reach usort(NULL, ...) and throw a fatal TypeError. It
   * should now be skipped gracefully with a warning recorded instead.
   */
  public function testSkipsModuleWithMissingPackagistDataWithoutCrashing() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData(['bluecadet' => ['bluecadet_utilities' => NULL]]);
    $checker->getUpdates();

    $this->assertSame(0, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $this->assertNotEmpty($checker->getWarnings()['bluecadet_utilities'] ?? []);
  }

  /**
   * A curated "recommended" entry overrides the automatic branch pick.
   *
   * The entry is read from the latest release's composer.json "extra" and
   * matched to the installed branch; it should override the automatic
   * "highest stable release in the current major" calculation.
   */
  public function testExtraRecommendedOverridesAutomaticCalculation() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.5.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(
        ['1.5.0', '1.5.9', '1.6.0'],
        ['1.6.0' => ['recommended' => ['1.5.9', '1.6.0']]]
      )],
    ]);
    $checker->getUpdates();

    // Without the override this would be '1.6.0' (highest stable in major 1).
    $this->assertSame('1.5.9', $checker->getRecommended('bluecadet', 'bluecadet_utilities'));
  }

  /**
   * A site below "minimum_supported" for its branch gets an admin notice.
   *
   * The notice uses Drupal's native "extra" {class, label, data} shape.
   */
  public function testBelowMinimumSupportedAddsExtraNotice() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.4.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(
        ['1.4.0', '2.0.0'],
        ['2.0.0' => ['minimum_supported' => ['1.4.12']]]
      )],
    ]);
    $checker->getUpdates();

    $extra = $checker->getExtra('bluecadet', 'bluecadet_utilities');
    $this->assertNotEmpty($extra);
    $this->assertSame('Below minimum supported version', $extra[0]['label'] ?? NULL);
  }

  /**
   * A version listed in "security" is marked and surfaced correctly.
   *
   * It should be marked with the Drupal-native terms shape
   * ProjectRelease::isSecurityRelease() reads, and surfaced via
   * getSecurityUpdates() (Drupal's "security updates" project key).
   */
  public function testSecurityVersionsAreMarkedAndSurfaced() {
    $checker = $this->checker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData([
      'bluecadet' => ['bluecadet_utilities' => $this->packagistPackages(
        ['1.0.0', '1.1.0', '1.2.0'],
        ['1.2.0' => ['security' => ['1.1.0']]]
      )],
    ]);
    $checker->getUpdates();

    $releases = $checker->getReleases('bluecadet', 'bluecadet_utilities');
    $this->assertSame(['Release type' => ['Security update']], $releases['1.1.0']['terms'] ?? NULL);

    $security_updates = $checker->getSecurityUpdates('bluecadet', 'bluecadet_utilities');
    $this->assertCount(1, $security_updates);
    $this->assertSame('1.1.0', $security_updates[0]['version'] ?? NULL);
  }

}
