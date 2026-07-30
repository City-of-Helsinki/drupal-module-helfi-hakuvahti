<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

/**
 * Status of a single broadcast.
 */
final readonly class BroadcastStatus {

  public const string STATUS_PROCESSING = 'processing';
  public const string STATUS_COMPLETED = 'completed';
  public const string STATUS_FAILED = 'failed';

  /**
   * Constructs a BroadcastStatus.
   *
   * @param string $id
   *   The broadcast id.
   * @param string $siteId
   *   The site the broadcast was sent to.
   * @param string $status
   *   The status. Kept as a string so that a status added later to hakuvahti
   *   does not break the status page.
   * @param bool $test
   *   Whether this was a test broadcast.
   * @param \DateTimeImmutable $created
   *   When the broadcast was created.
   * @param \Drupal\helfi_hakuvahti\BroadcastStats|null $stats
   *   Delivery statistics, or NULL while the broadcast is still processing.
   */
  public function __construct(
    public string $id,
    public string $siteId,
    public string $status,
    public bool $test,
    public \DateTimeImmutable $created,
    public ?BroadcastStats $stats,
  ) {
  }

  /**
   * Whether the broadcast is still being processed.
   */
  public function isProcessing(): bool {
    return $this->status === self::STATUS_PROCESSING;
  }

  /**
   * Creates the status from a decoded hakuvahti response.
   *
   * @throws \InvalidArgumentException
   *   If the response does not contain the fields hakuvahti promises.
   */
  public static function fromObject(\stdClass $data): self {
    foreach (['id', 'status', 'created'] as $fieldName) {
      if (empty($data->{$fieldName}) || !is_string($data->{$fieldName})) {
        throw new \InvalidArgumentException("Response is missing field: $fieldName");
      }
    }

    try {
      $created = new \DateTimeImmutable($data->created);
    }
    catch (\Exception $exception) {
      throw new \InvalidArgumentException("Response has an unparseable created date: {$data->created}", previous: $exception);
    }

    $stats = $data->stats ?? NULL;

    return new self(
      $data->id,
      isset($data->site_id) && is_string($data->site_id) ? $data->site_id : '',
      $data->status,
      (bool) ($data->test ?? FALSE),
      $created,
      $stats instanceof \stdClass ? BroadcastStats::fromObject($stats) : NULL,
    );
  }

}
