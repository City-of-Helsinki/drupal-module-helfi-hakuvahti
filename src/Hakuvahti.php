<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

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
   * Make hakuvahti request.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  private function makeRequest(string $method, string $url, array $options = []): void {
    $settings = $this->configFactory->get('helfi_hakuvahti.settings');
    if (!$baseUrl = $settings->get('base_url')) {
      throw new HakuvahtiException('Hakuvahti base url is not configured.');
    }

    $apiKey = $settings->get('api_key');

    try {
      $this->client->request($method, "$baseUrl$url", NestedArray::mergeDeep([
        RequestOptions::HEADERS => [
          'Authorization' => "api-key $apiKey",
        ],
        RequestOptions::TIMEOUT => 5,
      ], $options));
    }
    catch (GuzzleException $exception) {
      throw new HakuvahtiException('Hakuvahti unsubscribe request failed: ' . $exception->getMessage(), $exception->getCode(), previous: $exception);
    }
  }

}
