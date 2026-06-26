<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\helfi_hakuvahti\Hook\BreadcrumbHook;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests BreadcrumbHook breadcrumb alter behaviour.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class BreadcrumbHookTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_hakuvahti',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['helfi_hakuvahti']);
  }

  /**
   * Creates a BreadcrumbHook instance with an optional site_id on the request.
   */
  private function createHook(?string $siteId = NULL): BreadcrumbHook {
    $requestStack = new RequestStack();
    if ($siteId !== NULL) {
      $requestStack->push(Request::create('/hakuvahti/confirm', 'GET', ['site_id' => $siteId]));
    }
    return new BreadcrumbHook(
      $this->container->get('entity_type.manager'),
      $requestStack,
    );
  }

  /**
   * Creates a mocked RouteMatchInterface returning the given route name.
   */
  private function createRouteMatch(string $routeName): RouteMatchInterface {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($routeName);
    return $routeMatch;
  }

  /**
   * Creates a Breadcrumb with three links for testing.
   */
  private function createBreadcrumb(): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addLink(Link::createFromRoute('Home', '<front>'));
    $breadcrumb->addLink(Link::createFromRoute('Parent page', '<none>'));
    $breadcrumb->addLink(Link::createFromRoute('Current page', '<none>'));
    return $breadcrumb;
  }

  /**
   * Non-hakuvahti routes are not touched and no cache context is added.
   */
  public function testNonHakuvahtiRouteIsNotAltered(): void {
    $breadcrumb = $this->createBreadcrumb();
    $this->createHook()->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('some.other.route'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
    $this->assertNotContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A hakuvahti route without site_id adds the cache context but leaves links.
   */
  public function testHakuvahtiRouteWithoutSiteIdAddsCacheContext(): void {
    $breadcrumb = $this->createBreadcrumb();
    $this->createHook()->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
    $this->assertContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A hakuvahti route with an unknown site_id leaves links unchanged.
   */
  public function testHakuvahtiRouteWithUnknownSiteIdLeavesLinksUnchanged(): void {
    $breadcrumb = $this->createBreadcrumb();
    $this->createHook('no-such-site')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
    $this->assertContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A matching config entity with a non-empty title replaces the last link.
   */
  public function testCustomTitleReplacesLastBreadcrumbLink(): void {
    /** @var \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'test_site',
        'label' => 'Test Site',
        'site_id' => 'test-site',
      ]);
    $entity->setConfirmationText('confirm_page_title', 'Custom Confirm Title');
    $entity->save();

    $breadcrumb = $this->createBreadcrumb();
    $this->createHook('test-site')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertCount(3, $links);
    $this->assertEquals('Custom Confirm Title', (string) $links[2]->getText());
    $this->assertContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * All three hakuvahti routes use the correct title key.
   *
   * @dataProvider provideRouteTitleKeys
   */
  public function testAllRoutesUseCorrectTitleKey(string $routeName, string $titleKey): void {
    /** @var \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'test_site',
        'label' => 'Test Site',
        'site_id' => 'test-site',
      ]);
    $entity->setConfirmationText($titleKey, 'Custom Title for ' . $routeName);
    $entity->save();

    $breadcrumb = $this->createBreadcrumb();
    $this->createHook('test-site')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch($routeName),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Custom Title for ' . $routeName, (string) $links[2]->getText());
  }

  /**
   * Data provider for testAllRoutesUseCorrectTitleKey.
   *
   * @return array<string, array{string, string}>
   *   Route name and expected title key pairs.
   */
  public static function provideRouteTitleKeys(): array {
    return [
      'confirm' => ['helfi_hakuvahti.confirm', 'confirm_page_title'],
      'renew' => ['helfi_hakuvahti.renew', 'renew_page_title'],
      'unsubscribe' => ['helfi_hakuvahti.unsubscribe', 'unsubscribe_page_title'],
    ];
  }

  /**
   * A config entity with an empty title leaves the breadcrumb links unchanged.
   */
  public function testEmptyCustomTitleLeavesLinksUnchanged(): void {
    $this->container->get('entity_type.manager')
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'test_site',
        'label' => 'Test Site',
        'site_id' => 'test-site',
      ])
      ->save();

    $breadcrumb = $this->createBreadcrumb();
    $this->createHook('test-site')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
  }

}
