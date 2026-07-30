<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Unit;

use Drupal\helfi_hakuvahti\BroadcastRequest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the broadcast request data object.
 */
#[Group('helfi_hakuvahti')]
class BroadcastRequestTest extends UnitTestCase {

  /**
   * Tests the request class.
   *
   * @param array<string, mixed> $request
   *   The request data.
   * @param array<string, mixed>|null $expected
   *   Expected service request data, or NULL if the request is invalid.
   */
  #[DataProvider('data')]
  public function testRequestClass(array $request, ?array $expected = NULL): void {
    if (!$expected) {
      $this->expectException(\InvalidArgumentException::class);
    }

    $broadcastRequest = new BroadcastRequest($request);

    if ($expected) {
      $this->assertSame($expected, $broadcastRequest->getServiceRequestData());
    }
  }

  /**
   * Gets test data.
   *
   * @return array<string, mixed>
   *   Test data.
   */
  public static function data(): array {
    return [
      'email only' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages(),
        ],
        'expected' => [
          'site_id' => 'etusivu',
          'messages' => [
            'fi' => ['subject' => 'FI subject', 'body' => 'FI body'],
            'sv' => ['subject' => 'SV subject', 'body' => 'SV body'],
            'en' => ['subject' => 'EN subject', 'body' => 'EN body'],
          ],
        ],
      ],
      'with sms in every language' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['sms' => 'FI sms'],
            'sv' => ['sms' => 'SV sms'],
            'en' => ['sms' => 'EN sms'],
          ]),
        ],
        'expected' => [
          'site_id' => 'etusivu',
          'messages' => [
            'fi' => ['subject' => 'FI subject', 'body' => 'FI body', 'sms' => 'FI sms'],
            'sv' => ['subject' => 'SV subject', 'body' => 'SV body', 'sms' => 'SV sms'],
            'en' => ['subject' => 'EN subject', 'body' => 'EN body', 'sms' => 'EN sms'],
          ],
        ],
      ],
      'empty sms texts are omitted' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['sms' => ''],
            'sv' => ['sms' => '   '],
            'en' => ['sms' => "\r\n"],
          ]),
        ],
        'expected' => [
          'site_id' => 'etusivu',
          'messages' => [
            'fi' => ['subject' => 'FI subject', 'body' => 'FI body'],
            'sv' => ['subject' => 'SV subject', 'body' => 'SV body'],
            'en' => ['subject' => 'EN subject', 'body' => 'EN body'],
          ],
        ],
      ],
      'test send' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages(),
          'subscriptionIds' => [
            ' 0123456789abcdef01234567 ',
            'FEDCBA9876543210FEDCBA98',
            '0123456789abcdef01234567',
          ],
        ],
        'expected' => [
          'site_id' => 'etusivu',
          'messages' => [
            'fi' => ['subject' => 'FI subject', 'body' => 'FI body'],
            'sv' => ['subject' => 'SV subject', 'body' => 'SV body'],
            'en' => ['subject' => 'EN subject', 'body' => 'EN body'],
          ],
          // Duplicates are collapsed and the values are trimmed.
          'subscription_ids' => [
            '0123456789abcdef01234567',
            'FEDCBA9876543210FEDCBA98',
          ],
        ],
      ],
      'line endings are normalized' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['body' => "first\r\nsecond\r\n"],
          ]),
        ],
        'expected' => [
          'site_id' => 'etusivu',
          'messages' => [
            'fi' => ['subject' => 'FI subject', 'body' => "first\nsecond"],
            'sv' => ['subject' => 'SV subject', 'body' => 'SV body'],
            'en' => ['subject' => 'EN subject', 'body' => 'EN body'],
          ],
        ],
      ],
      'missing site id' => [
        'request' => [
          'messages' => self::messages(),
        ],
      ],
      'empty site id' => [
        'request' => [
          'siteId' => '  ',
          'messages' => self::messages(),
        ],
      ],
      'missing messages' => [
        'request' => [
          'siteId' => 'etusivu',
        ],
      ],
      'missing language' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => array_diff_key(self::messages(), ['sv' => NULL]),
        ],
      ],
      'empty subject' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages(['sv' => ['subject' => '']]),
        ],
      ],
      'whitespace only body' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages(['en' => ['body' => " \n "]]),
        ],
      ],
      'subject too long' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['subject' => str_repeat('ä', BroadcastRequest::MAX_SUBJECT_LENGTH + 1)],
          ]),
        ],
      ],
      'body too long' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['body' => str_repeat('ä', BroadcastRequest::MAX_BODY_LENGTH + 1)],
          ]),
        ],
      ],
      'sms too long' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['sms' => str_repeat('ä', BroadcastRequest::MAX_SMS_LENGTH + 1)],
            'sv' => ['sms' => 'SV sms'],
            'en' => ['sms' => 'EN sms'],
          ]),
        ],
      ],
      'sms in two languages only' => [
        'request' => [
          'siteId' => 'etusivu',
          'messages' => self::messages([
            'fi' => ['sms' => 'FI sms'],
            'sv' => ['sms' => 'SV sms'],
          ]),
        ],
      ],
    ];
  }

  /**
   * Builds a valid set of messages.
   *
   * @param array<string, array<string, string>> $overrides
   *   Per-language overrides.
   *
   * @return array<string, array<string, string>>
   *   The messages.
   */
  private static function messages(array $overrides = []): array {
    $messages = [];

    foreach (BroadcastRequest::LANGUAGES as $langcode) {
      $prefix = strtoupper($langcode);
      $messages[$langcode] = ($overrides[$langcode] ?? []) + [
        'subject' => "$prefix subject",
        'body' => "$prefix body",
      ];
    }

    return $messages;
  }

}
