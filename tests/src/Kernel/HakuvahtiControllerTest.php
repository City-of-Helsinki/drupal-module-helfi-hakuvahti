<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\Traits\PropertyTrait;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tests for hakuvahti controller.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class HakuvahtiControllerTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;
  use PropertyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'helfi_hakuvahti',
  ];

  /**
   * Tests confirm route.
   */
  public function testConfirmRoute(): void {
    $this->setupHakuvahtiConfig([
      // POST 1: confirm succeeds.
      new Response(200, body: ''),
      // POST 2: confirm returns 409 (already confirmed).
      new Response(409, body: 'conflict'),
      // POST 3: confirm returns 404.
      new Response(404, body: 'not found'),
      // POST 4: confirm returns 500.
      new Response(500, body: 'fail'),
    ]);

    $this->setUpCurrentUser(permissions: ['access content']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    $tests = [
      ['GET', 'Confirm saved search'],
      ['POST', 'Search alert subscription successful'],
      ['POST', 'already confirmed this search alert'],
      ['POST', 'Search alert confirmation failed'],
      ['POST', 'Search alert confirmation failed'],
    ];

    foreach ($tests as $test) {
      [$method, $message] = $test;

      $response = $this->makeRequest($method, 'helfi_hakuvahti.confirm', ['hash' => 'a', 'subscription' => 'b']);
      $this->assertEquals(200, $response->getStatusCode());
      $this->assertStringContainsString($message, $response->getContent() ?? '');
    }
  }

  /**
   * Tests renew and unsubscribe routes.
   */
  #[DataProvider('dataProvider')]
  public function testRenewAndUnsubscribeRoutes(string $route, array $tests): void {
    $this->setupHakuvahtiConfig([
      new Response(200, body: ''),
      new Response(404, body: 'not found'),
      new Response(500, body: 'fail'),
      new RequestException("womp womp", new Request('POST', 'test')),
    ]);

    $this->setUpCurrentUser(permissions: ['access content']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    foreach ($tests as $test) {
      [$method, $message] = $test;

      $response = $this->makeRequest($method, $route, ['hash' => 'a', 'subscription' => 'b']);
      $this->assertEquals(200, $response->getStatusCode());
      $this->assertStringContainsString($message, $response->getContent() ?? '');
    }
  }

  /**
   * Process a request.
   *
   * @param string $method
   *   HTTP method.
   * @param string $route
   *   Drupal route.
   * @param array $query
   *   Query parameters.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Controller response.
   */
  private function makeRequest(string $method, string $route, array $query = []): SymfonyResponse {
    $url = Url::fromRoute($route, options: [
      'query' => $query,
    ]);

    $request = $this->getMockedRequest($url->toString(), $method);

    return $this->processRequest($request);
  }

  /**
   * Tests SMS confirm route GET renders the form.
   */
  public function testSmsConfirmGetRendersForm(): void {
    $this->setupHakuvahtiConfig();
    $this->setUpCurrentUser(permissions: ['access content']);

    $response = $this->makeRequest('GET', 'helfi_hakuvahti.confirm', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $content = $response->getContent() ?? '';
    $this->assertStringContainsString('Confirm saved search', $content);
    $this->assertStringContainsString('Confirmation code', $content);
    $this->assertStringContainsString('name="id"', $content);
    $this->assertStringContainsString('name="code"', $content);
  }

  /**
   * Tests SMS confirm route GET with empty id shows error.
   */
  public function testSmsConfirmGetWithEmptyIdShowsError(): void {
    $this->setupHakuvahtiConfig();
    $this->setUpCurrentUser(permissions: ['access content']);

    $response = $this->makeRequest('GET', 'helfi_hakuvahti.confirm', ['id' => '']);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Search alert confirmation failed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS confirm route POST success.
   */
  public function testSmsConfirmPostSuccess(): void {
    $this->setupHakuvahtiConfig([
      new Response(200, body: ''),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    $response = $this->makeSmsPostRequest(
      'helfi_hakuvahti.confirm',
      ['id' => 'sub-123'],
      ['id' => 'sub-123', 'code' => '1234']
    );
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Search alert subscription successful', $response->getContent() ?? '');
  }

  /**
   * Tests SMS confirm route POST with already confirmed subscription.
   */
  public function testSmsConfirmPostAlreadyConfirmed(): void {
    $this->setupHakuvahtiConfig([
      new Response(409, body: 'already confirmed'),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    $response = $this->makeSmsPostRequest(
      'helfi_hakuvahti.confirm',
      ['id' => 'sub-123'],
      ['id' => 'sub-123', 'code' => '1234']
    );
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('already confirmed this search alert', $response->getContent() ?? '');
  }

  /**
   * Tests SMS confirm route POST failure.
   */
  public function testSmsConfirmPostFailure(): void {
    $this->setupHakuvahtiConfig([
      new Response(500, body: 'fail'),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    $response = $this->makeSmsPostRequest(
      'helfi_hakuvahti.confirm',
      ['id' => 'sub-123'],
      ['id' => 'sub-123', 'code' => '1234']
    );
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Search alert confirmation failed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS renew route.
   */
  public function testSmsRenewRoute(): void {
    $this->setupHakuvahtiConfig([
      new Response(200, body: ''),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    // GET renders autosubmit form (no code field).
    $response = $this->makeRequest('GET', 'helfi_hakuvahti.renew', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $content = $response->getContent() ?? '';
    $this->assertStringContainsString('Renew saved search', $content);
    $this->assertStringNotContainsString('name="code"', $content);

    // POST success.
    $response = $this->makeRequest('POST', 'helfi_hakuvahti.renew', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('renewed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS renew route failure.
   */
  public function testSmsRenewRouteFailure(): void {
    $this->setupHakuvahtiConfig([
      new Response(500, body: 'fail'),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    $response = $this->makeRequest('POST', 'helfi_hakuvahti.renew', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Renewal failed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS delete route.
   */
  public function testSmsDeleteRoute(): void {
    $this->setupHakuvahtiConfig([
      new Response(200, body: ''),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    // GET renders autosubmit form (no code field).
    $response = $this->makeRequest('GET', 'helfi_hakuvahti.unsubscribe', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $content = $response->getContent() ?? '';
    $this->assertStringContainsString('Delete saved search', $content);
    $this->assertStringNotContainsString('name="code"', $content);

    // POST success.
    $response = $this->makeRequest('POST', 'helfi_hakuvahti.unsubscribe', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('removed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS delete route failure.
   */
  public function testSmsDeleteRouteFailure(): void {
    $this->setupHakuvahtiConfig([
      new Response(500, body: 'fail'),
    ]);
    $this->setUpCurrentUser(permissions: ['access content']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());

    $response = $this->makeRequest('POST', 'helfi_hakuvahti.unsubscribe', ['id' => 'sub-123']);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Search alert removal failed', $response->getContent() ?? '');
  }

  /**
   * Tests SMS flood protection.
   */
  public function testSmsFloodProtection(): void {
    // Exhaust flood limit (10 requests).
    $responses = [];
    for ($i = 0; $i < 10; $i++) {
      $responses[] = new Response(200, body: '');
    }
    $this->setupHakuvahtiConfig($responses);
    $this->setUpCurrentUser(permissions: ['access content']);

    for ($i = 0; $i < 10; $i++) {
      $this->makeSmsPostRequest('helfi_hakuvahti.confirm', ['id' => 'sub-123'], ['id' => 'sub-123', 'code' => '1234']);
    }

    // 11th request should be flood-limited.
    $response = $this->makeSmsPostRequest(
      'helfi_hakuvahti.confirm',
      ['id' => 'sub-123'],
      ['id' => 'sub-123', 'code' => '1234']
    );
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertStringContainsString('Too many requests', $response->getContent() ?? '');
  }

  /**
   * Data provider for testRenewAndUnsubscribeRoutes.
   */
  public static function dataProvider(): array {
    return [
      [
        'helfi_hakuvahti.renew',
        [
          ['GET', 'Renew saved search'],
          ['POST', 'Search renewed successfully'],
          ['POST', 'Renewal failed'],
          ['POST', 'Renewal failed'],
        ],
      ],
      [
        'helfi_hakuvahti.unsubscribe',
        [
          ['GET', 'Delete saved search'],
          ['POST', 'search alert has been removed'],
          ['POST', 'Saved search not found'],
          ['POST', 'Search alert removal failed'],
        ],
      ],
    ];
  }

  /**
   * Sets up hakuvahti configuration and mock HTTP client.
   */
  private function setupHakuvahtiConfig(array $responses = []): void {
    if ($responses) {
      $client = $this->setupMockHttpClient($responses);
      $this->container->set(ClientInterface::class, $client);
    }

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->save();
  }

  /**
   * Makes a POST request with form data for SMS routes.
   *
   * @param string $route
   *   Drupal route.
   * @param array $query
   *   Query parameters.
   * @param array $postData
   *   POST form data.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Controller response.
   */
  private function makeSmsPostRequest(string $route, array $query, array $postData): SymfonyResponse {
    $url = Url::fromRoute($route, options: [
      'query' => $query,
    ]);

    $request = SymfonyRequest::create($url->toString(), 'POST', $postData);

    return $this->processRequest($request);
  }

}
