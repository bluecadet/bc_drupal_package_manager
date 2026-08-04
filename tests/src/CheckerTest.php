<?php

namespace Bluecadet\DrupalPackageManager\Tests;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\update\UpdateManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Bluecadet\DrupalPackageManager\Checker
 */
class CheckerTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(TRUE);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('module_handler')->willReturn($moduleHandler);

    \Drupal::setContainer($container);
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

  protected function packagistPackages(array $versions): array {
    $packages = [];
    foreach ($versions as $version) {
      $packages[] = ['version' => $version, 'time' => '2024-01-01T00:00:00+00:00'];
    }
    return ['packages' => ['bluecadet/bluecadet_utilities' => $packages]];
  }

  public function testFlagsNotCurrentForNewerStableReleaseWithinSameMajor() {
    $checker = new TestableChecker(
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
   * Regression test: a newer major release used to leave the status at
   * CURRENT because NOT_CURRENT was only ever set inside the "same major"
   * branch. It should be flagged just like any other newer release.
   */
  public function testFlagsNotCurrentForNewerMajorRelease() {
    $checker = new TestableChecker(
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
   * Regression test: packages are sorted before processing, and the
   * comparator must yield a real ascending sort regardless of input order
   * for the "latest" tracking to be correct.
   */
  public function testLatestVersionIsCorrectRegardlessOfInputOrder() {
    $checker = new TestableChecker(
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
   * Regression test: a pre-release patch on the current minor branch should
   * surface as an "x.y.x" entry in also() without flipping the status.
   */
  public function testPreReleaseDoesNotAffectStatus() {
    $checker = new TestableChecker(
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
   * Regression test: a missing/unparseable existing_version used to reach
   * a method call on a bare string ("") and throw a fatal Error. It should
   * now be skipped gracefully with a warning recorded instead.
   */
  public function testSkipsModuleWithMissingExistingVersionWithoutCrashing() {
    $checker = new TestableChecker(
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
   * Regression test: missing/failed Packagist data used to reach
   * usort(null, ...) and throw a fatal TypeError. It should now be skipped
   * gracefully with a warning recorded instead.
   */
  public function testSkipsModuleWithMissingPackagistDataWithoutCrashing() {
    $checker = new TestableChecker(
      ['bluecadet' => ['bluecadet_utilities']],
      ['bluecadet_utilities' => $this->project('1.0.0')]
    );
    $checker->setPackagistData(['bluecadet' => ['bluecadet_utilities' => NULL]]);
    $checker->getUpdates();

    $this->assertSame(0, $checker->getStatus('bluecadet', 'bluecadet_utilities'));
    $this->assertNotEmpty($checker->getWarnings()['bluecadet_utilities'] ?? []);
  }

}
