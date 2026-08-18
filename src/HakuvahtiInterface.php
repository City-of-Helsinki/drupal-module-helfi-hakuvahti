<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

/**
 * Hakuvahti client.
 */
interface HakuvahtiInterface {

  /**
   * Create hakuvahti subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function subscribe(HakuvahtiRequest $request): void;

  /**
   * Confirm hakuvahti subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function confirm(string $subscriptionHash, string $subscriptionId): void;

  /**
   * Renew hakuvahti subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function renew(string $subscriptionHash, string $subscriptionId): void;

  /**
   * Unsubscribe hakuvahti subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function unsubscribe(string $subscriptionHash, string $subscriptionId): void;

  /**
   * Confirm SMS subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function confirmSms(string $subscriptionId, string $code): void;

  /**
   * Renew SMS subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function renewSms(string $subscriptionId): void;

  /**
   * Delete SMS subscription.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function deleteSms(string $subscriptionId): void;

  /**
   * Send a broadcast message.
   *
   * Hakuvahti only acknowledges that it accepted the message and sends it in
   * the background. It reports nothing back about the delivery.
   *
   * @param \Drupal\helfi_hakuvahti\BroadcastRequest $request
   *   The message to broadcast.
   * @param string $accessToken
   *   The OpenID Connect access token of the user sending the broadcast.
   *   Hakuvahti verifies it to know who is behind the request, so it has to
   *   belong to the user and cannot have expired.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function broadcast(BroadcastRequest $request, #[\SensitiveParameter] string $accessToken): void;

  /**
   * Read per-site subscription key figures.
   *
   * @param string $siteId
   *   The site id. Hakuvahti must have configuration for it.
   * @param string $interval
   *   The grain of the returned series, either 'day' or 'month'.
   * @param string|null $from
   *   Range start as YYYY-MM-DD, or NULL for hakuvahti's own default.
   * @param string|null $to
   *   Range end as YYYY-MM-DD, or NULL for today.
   *
   * @return array<string, mixed>
   *   The decoded response. Hakuvahti reports back the range it actually used,
   *   which is not necessarily the one that was asked for: it snaps both ends
   *   out to whole periods and caps the length.
   *
   * @throws \Drupal\helfi_hakuvahti\HakuvahtiException
   */
  public function stats(string $siteId, string $interval = 'month', ?string $from = NULL, ?string $to = NULL): array;

}
