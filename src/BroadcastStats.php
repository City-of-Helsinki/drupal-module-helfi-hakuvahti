<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

/**
 * Delivery statistics of a finished broadcast.
 */
final readonly class BroadcastStats {

  /**
   * Constructs a BroadcastStats.
   *
   * @param int $subscriptionsChecked
   *   Number of subscriptions that were processed.
   * @param int $emailsQueued
   *   Number of emails that were added to the sending queue.
   * @param int $smsQueued
   *   Number of sms messages that were added to the sending queue.
   * @param int $missingContacts
   *   Number of subscriptions whose contact details were missing.
   */
  public function __construct(
    public int $subscriptionsChecked,
    public int $emailsQueued,
    public int $smsQueued,
    public int $missingContacts,
  ) {
  }

  /**
   * Creates the statistics from a decoded hakuvahti response.
   */
  public static function fromObject(\stdClass $data): self {
    return new self(
      (int) ($data->subscriptionsChecked ?? 0),
      (int) ($data->emailsQueued ?? 0),
      (int) ($data->smsQueued ?? 0),
      (int) ($data->missingContacts ?? 0),
    );
  }

}
