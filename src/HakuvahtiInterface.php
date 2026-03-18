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

}
