<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Hakuvahti API client.
 */
final readonly class Hakuvahti implements HakuvahtiInterface {

  public function __construct(
    private ClientInterface $client,
    private ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function subscribe(HakuvahtiRequest $request): void {
    $this->makeRequest('POST', "/subscription", [
      RequestOptions::JSON => $request->getServiceRequestData(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(string $subscriptionHash, string $subscriptionId): void {
    try {
      $this->makeRequest('POST', "/subscription/confirm/{$subscriptionId}/{$subscriptionHash}");
    }
    catch (HakuvahtiException $e) {
      $previous = $e->getPrevious();

      // Rewrite the exception type if the subscription is already confirmed.
      if ($previous instanceof BadResponseException) {
        if ($previous->getResponse()->getStatusCode() === 409) {
          throw new HakuvahtiAlreadyConfirmedException("Hakuvahti already confirmed", $e->getCode(), previous: $e);
        }
      }

      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renew(string $subscriptionHash, string $subscriptionId): void {
    $this->makeRequest('POST', "/subscription/renew/{$subscriptionId}/{$subscriptionHash}");
  }

  /**
   * {@inheritdoc}
   */
  public function unsubscribe(string $subscriptionHash, string $subscriptionId): void {
    $this->makeRequest('DELETE', "/subscription/delete/{$subscriptionId}/{$subscriptionHash}");
  }

  /**
   * {@inheritdoc}
   */
  public function confirmSms(string $subscriptionId, string $code): void {
    try {
      $this->makeRequest('POST', "/subscription/sms/confirm/$subscriptionId", [
        RequestOptions::JSON => [
          'code' => $code,
        ],
      ]);
    }
    catch (HakuvahtiException $e) {
      $previous = $e->getPrevious();

      // Rewrite the exception type if the subscription is already confirmed.
      if ($previous instanceof BadResponseException) {
        if ($previous->getResponse()->getStatusCode() === 409) {
          throw new HakuvahtiAlreadyConfirmedException("Hakuvahti already confirmed", $e->getCode(), previous: $e);
        }
      }

      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renewSms(string $subscriptionId): void {
    $this->makeRequest('POST', "/subscription/sms/renew/$subscriptionId");
  }

  /**
   * {@inheritdoc}
   */
  public function deleteSms(string $subscriptionId): void {
    $this->makeRequest('DELETE', "/subscription/sms/delete/$subscriptionId");
  }

  /**
   * {@inheritdoc}
   */
  public function broadcast(BroadcastRequest $request, #[\SensitiveParameter] string $accessToken): string {
    $response = $this->makeRequest('POST', '/broadcast', [
      RequestOptions::JSON => $request->getServiceRequestData(),
      // The api key says the request comes from this site, the access token
      // says which user is behind it.
      RequestOptions::HEADERS => ['X-Access-Token' => $accessToken],
      // Broadcasts get a longer timeout than the other requests.
      RequestOptions::TIMEOUT => 10,
    ]);

    $data = $this->decodeJson($response);

    if (empty($data->id) || !is_string($data->id)) {
      throw new HakuvahtiException('Hakuvahti broadcast response did not contain an id.', $response->getStatusCode());
    }

    return $data->id;
  }

  /**
   * {@inheritdoc}
   */
  public function getBroadcastStatus(string $id): BroadcastStatus {
    $response = $this->makeRequest('GET', "/broadcast/$id");

    try {
      return BroadcastStatus::fromObject($this->decodeJson($response));
    }
    catch (\InvalidArgumentException $exception) {
      throw new HakuvahtiException(
        'Hakuvahti returned an unexpected broadcast status: ' . $exception->getMessage(),
        $response->getStatusCode(),
        previous: $exception,
      );
    }
  }

  /**
   * Decodes a JSON response.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  private function decodeJson(ResponseInterface $response): \stdClass {
    try {
      $data = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new HakuvahtiException(
        'Hakuvahti returned an invalid JSON response: ' . $exception->getMessage(),
        $response->getStatusCode(),
        previous: $exception,
      );
    }

    if (!$data instanceof \stdClass) {
      throw new HakuvahtiException('Hakuvahti returned an unexpected JSON response.', $response->getStatusCode());
    }

    return $data;
  }

  /**
   * Make hakuvahti request.
   *
   * @param string $method
   *   HTTP method.
   * @param string $url
   *   Endpoint path.
   * @param array<string, mixed> $options
   *   Guzzle options.
   *
   * @phpstan-return \Psr\Http\Message\ResponseInterface
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  private function makeRequest(string $method, string $url, array $options = []): ResponseInterface {
    $settings = $this->configFactory->get('helfi_hakuvahti.settings');
    if (!$baseUrl = $settings->get('base_url')) {
      throw new HakuvahtiException('Hakuvahti base url is not configured.');
    }

    $apiKey = $settings->get('api_key');

    try {
      return $this->client->request($method, "$baseUrl$url", NestedArray::mergeDeep([
        RequestOptions::HEADERS => [
          'Authorization' => "api-key $apiKey",
        ],
        RequestOptions::TIMEOUT => 5,
      ], $options));
    }
    catch (GuzzleException $exception) {
      throw new HakuvahtiException(
        sprintf('Hakuvahti %s %s request failed: %s', $method, $url, $exception->getMessage()),
        $exception->getCode(),
        previous: $exception,
      );
    }
  }

}
