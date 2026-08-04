<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\BroadcastRequest;
use Drupal\helfi_tunnistamo\TokenManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests access to the broadcast pages.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class BroadcastAccessTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'language',
    'helfi_hakuvahti',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'helfi_hakuvahti']);

    foreach (BroadcastRequest::LANGUAGES as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->set('api_key', '123')
      ->save();

    HakuvahtiConfig::create([
      'id' => 'news',
      'label' => 'News',
      'site_id' => 'etusivu',
    ])->save();

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    // This test is about permissions, so the broadcast form gets a session that
    // is allowed to send.
    $tokenManager = $this->prophesize(TokenManagerInterface::class);
    $tokenManager->hasSession()->willReturn(TRUE);
    $tokenManager->getAccessToken()->willReturn('access-token');
    $this->container->set(TokenManagerInterface::class, $tokenManager->reveal());

    $client = $this->setupMockHttpClient([
      new Response(202),
    ]);

    $this->container->set('http_client', $client);
    $this->container->set(ClientInterface::class, $client);
  }

  /**
   * Tests that the broadcast pages access.
   */
  public function testAccess(): void {
    // Anonymous user.
    $this->assertSame(403, $this->requestStatusCode('helfi_hakuvahti.broadcast'));

    // Invalid permission.
    $this->setUpCurrentUser(permissions: ['administer site configuration']);
    $this->assertSame(403, $this->requestStatusCode('helfi_hakuvahti.broadcast'));

    $this->setUpCurrentUser(permissions: ['send hakuvahti broadcast']);

    $this->assertSame(200, $this->requestStatusCode('helfi_hakuvahti.broadcast'));
  }

  /**
   * Requests a route and returns the status code.
   */
  private function requestStatusCode(string $route): int {
    $url = Url::fromRoute($route);

    return $this->processRequest($this->getMockedRequest($url->toString()))->getStatusCode();
  }

}
