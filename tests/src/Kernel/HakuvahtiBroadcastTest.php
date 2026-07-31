<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\helfi_hakuvahti\BroadcastRequest;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the broadcast requests of the hakuvahti client.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class HakuvahtiBroadcastTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'helfi_hakuvahti',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['helfi_hakuvahti']);

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->set('api_key', '123')
      ->save();
  }

  /**
   * Tests that a broadcast is sent in the format hakuvahti expects.
   */
  public function testBroadcastRequest(): void {
    $history = [];
    // Hakuvahti acknowledges the broadcast with an empty response.
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(202),
    ]));

    $this->sut()->broadcast(new BroadcastRequest([
      'siteId' => 'etusivu',
      'messages' => [
        'fi' => ['subject' => 'FI subject', 'body' => 'FI body', 'sms' => 'FI sms'],
        'sv' => ['subject' => 'SV subject', 'body' => 'SV body', 'sms' => 'SV sms'],
        'en' => ['subject' => 'EN subject', 'body' => 'EN body', 'sms' => 'EN sms'],
      ],
      'subscriptionIds' => ['0123456789abcdef01234567'],
    ]), 'access-token');

    $this->assertCount(1, $history);

    $request = $history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://example.com/broadcast', (string) $request->getUri());
    $this->assertSame('api-key 123', $request->getHeaderLine('Authorization'));
    $this->assertSame('access-token', $request->getHeaderLine('X-Access-Token'));
    $this->assertSame([
      'site_id' => 'etusivu',
      'messages' => [
        'fi' => ['subject' => 'FI subject', 'body' => 'FI body', 'sms' => 'FI sms'],
        'sv' => ['subject' => 'SV subject', 'body' => 'SV body', 'sms' => 'SV sms'],
        'en' => ['subject' => 'EN subject', 'body' => 'EN body', 'sms' => 'EN sms'],
      ],
      'subscription_ids' => ['0123456789abcdef01234567'],
    ], json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Tests that the status code hakuvahti responded with is kept.
   *
   * The status code is the only thing that tells the failures apart.
   *
   * @param int $statusCode
   *   The status code hakuvahti responded with.
   * @param string $body
   *   The response body.
   */
  #[DataProvider('errorResponses')]
  public function testBroadcastError(int $statusCode, string $body): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response($statusCode, body: $body),
    ]));

    try {
      $this->sut()->broadcast($this->request(), 'access-token');
      $this->fail('Expected HakuvahtiException.');
    }
    catch (HakuvahtiException $exception) {
      $this->assertSame($statusCode, $exception->getCode());
      $this->assertStringContainsString('Hakuvahti POST /broadcast request failed', $exception->getMessage());
    }
  }

  /**
   * Gets the errors hakuvahti responds with.
   *
   * @return array<string, array{int, string}>
   *   Test data.
   */
  public static function errorResponses(): array {
    return [
      'rejected payload' => [
        400,
        '{"error":"SMS text must be provided for either all languages or none.","field":"sms"}',
      ],
      'rejected payload without a JSON body' => [400, 'not json'],
      'invalid access token' => [403, '{"error":"Invalid or expired access token.","field":"access_token"}'],
      'sender not in an allowed AD group' => [
        403,
        '{"error":"Not authorized to broadcast for this site.","field":"access_token"}',
      ],
      'server error' => [500, '{"error":"fail"}'],
    ];
  }

  /**
   * Tests that a connection error is reported.
   */
  public function testBroadcastTransportError(): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new RequestException('womp womp', new Request('POST', 'test')),
    ]));

    $this->expectException(HakuvahtiException::class);
    $this->expectExceptionMessage('Hakuvahti POST /broadcast request failed: womp womp');
    $this->sut()->broadcast($this->request(), 'access-token');
  }

  /**
   * Gets the hakuvahti client.
   */
  private function sut(): HakuvahtiInterface {
    return $this->container->get(HakuvahtiInterface::class);
  }

  /**
   * Creates a valid broadcast request.
   *
   * @return \Drupal\helfi_hakuvahti\BroadcastRequest
   *   The request.
   */
  private function request(): BroadcastRequest {
    return new BroadcastRequest([
      'siteId' => 'etusivu',
      'messages' => [
        'fi' => ['subject' => 'FI subject', 'body' => 'FI body'],
        'sv' => ['subject' => 'SV subject', 'body' => 'SV body'],
        'en' => ['subject' => 'EN subject', 'body' => 'EN body'],
      ],
    ]);
  }

}
