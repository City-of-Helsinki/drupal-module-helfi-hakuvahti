<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the statistics requests of the hakuvahti client.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class HakuvahtiStatsTest extends KernelTestBase {

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
   * Tests that the whole range is asked for in the way hakuvahti expects.
   */
  public function testStatsRequest(): void {
    $history = [];
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(200, body: json_encode($this->report(), flags: JSON_THROW_ON_ERROR)),
    ]));

    $report = $this->sut()->stats('rekry', 'day', '2026-07-01', '2026-08-31');

    $this->assertCount(1, $history);

    $request = $history[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/stats/rekry', $request->getUri()->getPath());
    $this->assertSame('interval=day&from=2026-07-01&to=2026-08-31', $request->getUri()->getQuery());
    $this->assertSame('api-key 123', $request->getHeaderLine('Authorization'));

    $this->assertSame('rekry', $report['site_id']);
    $this->assertCount(2, $report['periods']);
  }

  /**
   * Tests that an omitted range is left out rather than sent empty.
   *
   * Hakuvahti applies its own defaults, which an empty from or to would not
   * trigger.
   */
  public function testStatsWithoutRange(): void {
    $history = [];
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(200, body: json_encode($this->report(), flags: JSON_THROW_ON_ERROR)),
    ]));

    $this->sut()->stats('rekry');

    $this->assertSame('interval=month', $history[0]['request']->getUri()->getQuery());
  }

  /**
   * Tests that the status code hakuvahti responded with is kept.
   *
   * The form turns it into the message, so a 404 from a hakuvahti that has no
   * statistics has to stay apart from a 400.
   *
   * @param int $statusCode
   *   The status code hakuvahti responded with.
   */
  #[DataProvider('errorResponses')]
  public function testStatsError(int $statusCode): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response($statusCode, body: '{"error":"Invalid site_id provided."}'),
    ]));

    try {
      $this->sut()->stats('rekry');
      $this->fail('Expected HakuvahtiException.');
    }
    catch (HakuvahtiException $exception) {
      $this->assertSame($statusCode, $exception->getCode());
      $this->assertStringContainsString('Hakuvahti GET /stats/rekry request failed', $exception->getMessage());
    }
  }

  /**
   * Gets the errors hakuvahti responds with.
   *
   * @return array<string, array{int}>
   *   Test data.
   */
  public static function errorResponses(): array {
    return [
      'unknown site or impossible date' => [400],
      'api key not accepted' => [403],
      'hakuvahti without statistics' => [404],
      'hakuvahti broke' => [500],
    ];
  }

  /**
   * Tests that a response that is not json fails as a hakuvahti error.
   *
   * A proxy answering with an html error page would otherwise throw a
   * JsonException, which nothing up the stack is catching.
   */
  public function testUnreadableResponse(): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response(200, body: '<html><body>504 Gateway Timeout</body></html>'),
    ]));

    $this->expectException(HakuvahtiException::class);
    $this->expectExceptionMessage('Hakuvahti returned an unreadable statistics response.');

    $this->sut()->stats('rekry');
  }

  /**
   * Tests that json which is not an object fails as a hakuvahti error.
   */
  public function testUnexpectedResponse(): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response(200, body: '"nope"'),
    ]));

    $this->expectException(HakuvahtiException::class);
    $this->expectExceptionMessage('Hakuvahti returned an unexpected statistics response.');

    $this->sut()->stats('rekry');
  }

  /**
   * The client under test.
   */
  private function sut(): HakuvahtiInterface {
    return $this->container->get(HakuvahtiInterface::class);
  }

  /**
   * A response in the shape hakuvahti answers with.
   *
   * @return array<string, mixed>
   *   The report.
   */
  private function report(): array {
    return [
      'site_id' => 'rekry',
      'generated_at' => '2026-08-17T09:12:33.000Z',
      'collecting_since' => '2026-03-10',
      'range' => ['from' => '2026-07-01', 'to' => '2026-08-31', 'interval' => 'month'],
      'current' => ['active' => 178, 'unconfirmed' => 4],
      'periods' => [
        [
          'period' => '2026-07',
          'created' => 25,
          'confirmed' => 22,
          'cancelled' => 3,
          'cancelled_unconfirmed' => 1,
          'expired' => 0,
          'expired_unconfirmed' => 0,
          'confirmed_by_lang' => ['fi' => 19, 'sv' => 2, 'en' => 1],
          'net_change' => 19,
          'active_end' => 172,
          'incomplete' => FALSE,
        ],
        [
          'period' => '2026-08',
          'created' => 0,
          'confirmed' => 0,
          'cancelled' => 0,
          'cancelled_unconfirmed' => 0,
          'expired' => 0,
          'expired_unconfirmed' => 0,
          'confirmed_by_lang' => ['fi' => 0, 'sv' => 0, 'en' => 0],
          'net_change' => NULL,
          'active_end' => NULL,
          'incomplete' => TRUE,
        ],
      ],
    ];
  }

}
