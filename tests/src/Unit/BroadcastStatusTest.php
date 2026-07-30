<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Unit;

use Drupal\helfi_hakuvahti\BroadcastStatus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the broadcast status data object.
 */
#[Group('helfi_hakuvahti')]
class BroadcastStatusTest extends UnitTestCase {

  /**
   * A broadcast id in the format hakuvahti returns.
   */
  const string ID = '0123456789abcdef01234567';

  /**
   * A timestamp in the format hakuvahti returns.
   */
  const string CREATED = '2026-07-30T12:00:00.000Z';

  /**
   * Tests a broadcast that is still processing.
   */
  public function testProcessing(): void {
    $status = BroadcastStatus::fromObject((object) [
      'id' => '0123456789abcdef01234567',
      'site_id' => 'etusivu',
      'status' => 'processing',
      'test' => FALSE,
      'created' => '2026-07-30T12:00:00.000Z',
      'stats' => NULL,
    ]);

    $this->assertSame('0123456789abcdef01234567', $status->id);
    $this->assertSame('etusivu', $status->siteId);
    $this->assertSame('processing', $status->status);
    $this->assertTrue($status->isProcessing());
    $this->assertFalse($status->test);
    $this->assertNull($status->stats);
    $this->assertSame('2026-07-30 12:00', $status->created->format('Y-m-d H:i'));
  }

  /**
   * Tests a completed test broadcast.
   */
  public function testCompleted(): void {
    $status = BroadcastStatus::fromObject((object) [
      'id' => '0123456789abcdef01234567',
      'site_id' => 'etusivu',
      'status' => 'completed',
      'test' => TRUE,
      'created' => '2026-07-30T12:00:00.000Z',
      'stats' => (object) [
        'subscriptionsChecked' => 5,
        'emailsQueued' => 4,
        'smsQueued' => 0,
        'missingContacts' => 1,
      ],
    ]);

    $this->assertFalse($status->isProcessing());
    $this->assertTrue($status->test);
    $this->assertNotNull($status->stats);
    $this->assertSame(5, $status->stats->subscriptionsChecked);
    $this->assertSame(4, $status->stats->emailsQueued);
    $this->assertSame(0, $status->stats->smsQueued);
    $this->assertSame(1, $status->stats->missingContacts);
  }

  /**
   * Tests that an unknown status does not break the data object.
   */
  public function testUnknownStatus(): void {
    $status = BroadcastStatus::fromObject((object) [
      'id' => self::ID,
      'status' => 'queued',
      'created' => self::CREATED,
    ]);

    $this->assertSame('queued', $status->status);
    $this->assertSame('', $status->siteId);
    $this->assertFalse($status->test);
    $this->assertNull($status->stats);
  }

  /**
   * Tests that an unparseable timestamp is rejected.
   */
  public function testInvalidCreated(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Response has an unparseable created date: not a date');

    BroadcastStatus::fromObject((object) [
      'id' => self::ID,
      'status' => 'completed',
      'created' => 'not a date',
    ]);
  }

  /**
   * Tests that required fields are validated.
   *
   * @param \stdClass $data
   *   Response data.
   */
  #[DataProvider('invalidData')]
  public function testMissingFields(\stdClass $data): void {
    $this->expectException(\InvalidArgumentException::class);
    BroadcastStatus::fromObject($data);
  }

  /**
   * Gets invalid response data.
   *
   * @return array<string, array<int, \stdClass>>
   *   Test data.
   */
  public static function invalidData(): array {
    return [
      'missing id' => [(object) ['status' => 'completed', 'created' => self::CREATED]],
      'empty id' => [(object) ['id' => '', 'status' => 'completed', 'created' => self::CREATED]],
      'missing status' => [(object) ['id' => self::ID, 'created' => self::CREATED]],
      'non-string status' => [(object) ['id' => self::ID, 'status' => 1, 'created' => self::CREATED]],
      'missing created' => [(object) ['id' => self::ID, 'status' => 'completed']],
      'non-string created' => [(object) ['id' => self::ID, 'status' => 'completed', 'created' => 1]],
    ];
  }

}
