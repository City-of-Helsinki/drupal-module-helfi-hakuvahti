<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Unit;

use Drupal\helfi_hakuvahti\HakuvahtiRequest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests rekry specific hakuvahti features.
 */
#[Group('helfi_hakuvahti')]
class HakuvahtiRequestClassTest extends UnitTestCase {

  /**
   * Test the request class.
   */
  #[DataProvider('data')]
  public function testRequestClass(array $request, ?array $expected = NULL): void {
    if (!$expected) {
      $this->expectException(\InvalidArgumentException::class);
    }

    $hakuvahtiRequest = new HakuvahtiRequest($request);

    if ($expected) {
      $serviceRequestData = $hakuvahtiRequest->getServiceRequestData();
      $this->assertEquals($serviceRequestData, $expected);
    }
  }

  /**
   * Get tests data.
   */
  public static function data(): array {
    return [
      // Email only request.
      [
        'request' => [
          'email' => 'valid@email.fi',
          'lang' => 'fi',
          'siteId' => 'rekry',
          'query' => '?query=123&parameters=4567',
          'elasticQuery' => 'this-is_the_base64_encoded_elasticsearch_query',
          'searchDescription' => 'This, is the query filters string, separated, by comma',
        ],
        'expected' => [
          'email' => 'valid@email.fi',
          'sms' => NULL,
          'lang' => 'fi',
          'site_id' => 'rekry',
          'query' => '?query=123&parameters=4567',
          'elastic_query' => 'this-is_the_base64_encoded_elasticsearch_query',
          'elastic_query_atv' => FALSE,
          'search_description' => 'This, is the query filters string, separated, by comma',
        ],
      ],
      // Phone-number-only request.
      [
        'request' => [
          'sms' => '044 123 4567',
          'lang' => 'fi',
          'siteId' => 'rekry',
          'query' => '?query=123&parameters=4567',
          'elasticQuery' => 'this-is_the_base64_encoded_elasticsearch_query',
          'elasticQueryAtv' => TRUE,
          'searchDescription' => 'This, is the query filters string, separated, by comma',
        ],
        'expected' => [
          'email' => NULL,
          'sms' => '044 123 4567',
          'lang' => 'fi',
          'site_id' => 'rekry',
          'query' => '?query=123&parameters=4567',
          'elastic_query' => 'this-is_the_base64_encoded_elasticsearch_query',
          'elastic_query_atv' => TRUE,
          'search_description' => 'This, is the query filters string, separated, by comma',
        ],
      ],
      // Test that an exception is thrown if the request has missing parameters.
      [
        'request' => [],
      ],
      // Test that an exception is thrown if parameters fail validation.
      [
        'request' => [
          'email' => 'invalid@email',
          'lang' => 'fi',
          'siteId' => 'rekry',
          'query' => '?query=123&parameters=4567',
          'elasticQuery' => 'this-is_the_base64_encoded_elasticsearch_query',
          'searchDescription' => 'This, is the query filters string, separated, by comma',
        ],
      ],
    ];
  }

}
