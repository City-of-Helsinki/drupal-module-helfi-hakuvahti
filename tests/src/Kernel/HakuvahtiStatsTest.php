<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\helfi_hakuvahti\Traits\StatsResponseTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the statistics requests of the hakuvahti client.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class HakuvahtiStatsTest extends KernelTestBase {

  use ApiTestTrait;
  use StatsResponseTrait;

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
      new Response(200, body: $this->statsResponseBody()),
    ]));

    $report = $this->sut()->stats(
      'rekry',
      'day',
      new \DateTimeImmutable('2026-07-01'),
      new \DateTimeImmutable('2026-08-31'),
    );

    $request = $history[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/stats/rekry', $request->getUri()->getPath());
    $this->assertSame('interval=day&from=2026-07-01&to=2026-08-31', $request->getUri()->getQuery());
    $this->assertSame('api-key 123', $request->getHeaderLine('Authorization'));
    $this->assertSame($this->statsResponse(), $report);
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
      new Response(200, body: $this->statsResponseBody()),
    ]));

    $this->sut()->stats('rekry');

    $this->assertSame('interval=month', $history[0]['request']->getUri()->getQuery());
  }

  /**
   * Tests that the status code hakuvahti responded with is kept.
   *
   * The form turns it into the message, so a 404 from a hakuvahti that has no
   * statistics has to stay apart from a 400.
   */
  #[TestWith([400])]
  #[TestWith([403])]
  #[TestWith([404])]
  public function testStatsError(int $statusCode): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response($statusCode, body: '{"error":"Invalid site_id provided."}'),
    ]));

    $this->expectException(HakuvahtiException::class);
    $this->expectExceptionCode($statusCode);
    $this->expectExceptionMessage('Hakuvahti GET /stats/rekry request failed');

    $this->sut()->stats('rekry');
  }

  /**
   * Tests that a response which is not a json object fails as a hakuvahti error.
   */
  #[TestWith(['<html><body>504 Gateway Timeout</body></html>', 'unreadable'])]
  #[TestWith(['"nope"', 'unexpected'])]
  public function testUnusableResponse(string $body, string $expected): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response(200, body: $body),
    ]));

    $this->expectException(HakuvahtiException::class);
    $this->expectExceptionMessage("Hakuvahti returned an $expected statistics response.");

    $this->sut()->stats('rekry');
  }

  /**
   * The client under test.
   */
  private function sut(): HakuvahtiInterface {
    return $this->container->get(HakuvahtiInterface::class);
  }

}
