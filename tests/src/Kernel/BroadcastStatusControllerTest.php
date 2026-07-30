<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tests for the broadcast status page.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class BroadcastStatusControllerTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;

  private const string BROADCAST_ID = '0123456789abcdef01234567';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'helfi_hakuvahti',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'helfi_hakuvahti']);

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->set('api_key', '123')
      ->save();

    $this->setUpCurrentUser(permissions: ['send hakuvahti broadcast']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());
  }

  /**
   * Tests a completed broadcast.
   */
  public function testCompleted(): void {
    $response = $this->request([
      new Response(200, body: (string) json_encode([
        'id' => self::BROADCAST_ID,
        'site_id' => 'etusivu',
        'status' => 'completed',
        'test' => FALSE,
        'created' => '2026-07-30T12:00:00.000Z',
        'stats' => [
          'subscriptionsChecked' => 3,
          'emailsQueued' => 2,
          'smsQueued' => 0,
          'missingContacts' => 1,
        ],
      ])),
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $content = $response->getContent() ?: '';
    $this->assertStringContainsString(self::BROADCAST_ID, $content);
    $this->assertStringContainsString('Subscriptions checked', $content);
    $this->assertStringContainsString('Emails added to the sending queue', $content);
    $this->assertStringContainsString('SMS messages added to the sending queue', $content);
    $this->assertStringContainsString('Subscriptions without contact details', $content);
    $this->assertStringNotContainsString('still being processed', $content);
  }

  /**
   * Requests the status page.
   *
   * @phpstan-param \Psr\Http\Message\ResponseInterface[]|\GuzzleHttp\Exception\GuzzleException[] $responses
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function request(array $responses): SymfonyResponse {
    $client = $this->setupMockHttpClient($responses);
    $this->container->set('http_client', $client);

    $url = Url::fromRoute('helfi_hakuvahti.broadcast_status', ['broadcast_id' => self::BROADCAST_ID]);

    return $this->processRequest($this->getMockedRequest($url->toString()));
  }

}
