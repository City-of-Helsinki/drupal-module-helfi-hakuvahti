<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Traits;

/**
 * The statistics response both stats tests answer requests with.
 */
trait StatsResponseTrait {

  /**
   * The fixture as hakuvahti would send it.
   */
  protected function statsResponseBody(): string {
    return file_get_contents(__DIR__ . '/../../fixtures/stats-response.json');
  }

  /**
   * The fixture decoded, for asserting against.
   *
   * @return array<string, mixed>
   *   The report.
   */
  protected function statsResponse(): array {
    return json_decode($this->statsResponseBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
