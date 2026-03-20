<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\Hakuvahti;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\helfi_api_base\Traits\EnvironmentResolverTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Loader\Configurator\Traits\PropertyTrait;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tests for hakuvahti subscribe controller.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class HakuvahtiSubscribeControllerTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;
  use EnvironmentResolverTrait;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $client = $this->setupMockHttpClient([
      new RequestException('Test error', new Request('POST', 'test'), new Response(400)),
      new Response(200),
    ]);

    $this->container->set(ClientInterface::class, $client);

    // Populate site_id in default config using entity storage.
    $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'default',
        'label' => 'Foobar',
        'site_id' => 'rekry',
      ])
      ->save();
  }

  /**
   * Tests handleConfirmFormSubmission.
   */
  public function testHandleConfirmFormSubmission(): void {
    // Subscribe without permissions.
    $response = $this->makeRequest([]);
    $this->assertEquals(403, $response->getStatusCode());

    $this->setUpCurrentUser(permissions: ['access content']);

    // Subscribe with bad request.
    $response = $this->makeRequest([]);
    $this->assertEquals(400, $response->getStatusCode());

    // Subscribe with missing value.
    $response = $this->makeRequest(['elasticQuery' => '']);
    $this->assertEquals(400, $response->getStatusCode());

    // Missing config.
    $response = $this->makeRequest([
      'email' => 'valid@email.fi',
      'query' => '?query=123&parameters=4567',
      'elasticQuery' => 'eyJxdWVyeSI6eyJib29sIjp7ImZpbHRlciI6W3sidGVybSI6eyJlbnRpdHlfdHlwZSI6Im5vZGUifX1dfX19',
      'searchDescription' => 'This, is the query filters string, separated, by comma',
    ]);
    $this->assertEquals(500, $response->getStatusCode());

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->save();

    // Subscribe with api error.
    $response = $this->makeRequest([
      'email' => 'valid@email.fi',
      'query' => '?query=123&parameters=4567',
      'elasticQuery' => 'eyJxdWVyeSI6eyJib29sIjp7ImZpbHRlciI6W3sidGVybSI6eyJlbnRpdHlfdHlwZSI6Im5vZGUifX1dfX19',
      'searchDescription' => 'This, is the query filters string, separated, by comma',
    ]);
    $this->assertEquals(500, $response->getStatusCode());

    // Success.
    $response = $this->makeRequest([
      'email' => 'valid@email.fi',
      'query' => '?query=123&parameters=4567',
      'elasticQuery' => 'eyJxdWVyeSI6eyJib29sIjp7ImZpbHRlciI6W3sidGVybSI6eyJlbnRpdHlfdHlwZSI6Im5vZGUifX1dfX19',
      'searchDescription' => 'This, is the query filters string, separated, by comma',
    ]);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests flood protection on subscribe endpoint.
   */
  public function testFloodProtection(): void {
    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->save();

    // Set up enough mock responses for 10 successful requests.
    $responses = [];
    for ($i = 0; $i < 10; $i++) {
      $responses[] = new Response(200);
    }

    $client = $this->createMockHttpClient($responses);
    $this->container->set(HakuvahtiInterface::class, new Hakuvahti($client, $this->container->get(ConfigFactoryInterface::class)));

    $this->setUpCurrentUser(permissions: ['access content']);

    $body = [
      'email' => 'valid@email.fi',
      'query' => '?query=123&parameters=4567',
      'elasticQuery' => 'eyJxdWVyeSI6eyJib29sIjp7ImZpbHRlciI6W3sidGVybSI6eyJlbnRpdHlfdHlwZSI6Im5vZGUifX1dfX19',
      'searchDescription' => 'This, is the query filters string, separated, by comma',
    ];

    for ($i = 0; $i < 10; $i++) {
      $response = $this->makeRequest($body);
      $this->assertEquals(200, $response->getStatusCode());
    }

    // 11th request should be rate limited.
    $response = $this->makeRequest($body);
    $this->assertEquals(429, $response->getStatusCode());
  }

  /**
   * Process a request.
   */
  private function makeRequest(array $body = []): SymfonyResponse {
    $url = Url::fromRoute('helfi_hakuvahti.subscribe');
    $request = $this->getMockedRequest($url->toString(), 'POST', document: $body);
    return $this->processRequest($request);
  }

}
