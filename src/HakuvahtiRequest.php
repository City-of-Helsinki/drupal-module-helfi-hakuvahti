<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

/**
 * Hakuvahti request data object.
 *
 * Contains required fields & data for hakuvahti service request.
 */
final readonly class HakuvahtiRequest {

  private const int MAX_SEARCH_DESCRIPTION_LENGTH = 999;
  private const array REQUIRED_FIELDS = [
    'lang',
    'siteId',
    'query',
    'elasticQuery',
    'searchDescription',
  ];

  /**
   * The email address.
   */
  public ?string $email;

  /**
   * User phone number.
   */
  public ?string $sms;

  /**
   * Language id.
   */
  public string $lang;

  /**
   * The site id.
   */
  public string $siteId;

  /**
   * The request parameters from the request uri.
   */
  public string $query;

  /**
   * The elastic query as base64-encoded string.
   *
   * The query that is used to find out if there are new hits in elasticsearch.
   */
  public string $elasticQuery;

  /**
   * If true, the elastic query is stored in ATV.
   *
   * Use this if the query can contain user data.
   */
  public bool $elasticQueryAtv;

  /**
   * The search description.
   *
   * Search description is a string required by hakuvahti. According to
   * the initial spec, it's a comma-separated string of the selected search
   * filters, but it could be any other string as well.
   */
  public string $searchDescription;

  public function __construct(array $requestData) {
    foreach (self::REQUIRED_FIELDS as $fieldName) {
      if (!isset($requestData[$fieldName])) {
        throw new \InvalidArgumentException("Request is missing field: $fieldName");
      }
    }

    $this->lang = $requestData['lang'];
    $this->siteId = $requestData['siteId'];
    $this->query = $requestData['query'];
    $this->elasticQuery = $requestData['elasticQuery'];
    $this->elasticQueryAtv = $requestData['elasticQueryAtv'] ?? FALSE;
    $this->searchDescription = $requestData['searchDescription'];
    $this->email = $requestData['email'] ?? NULL;
    $this->sms = $requestData['sms'] ?? NULL;

    // User chooses which notification type they get. Either field can be NULL.
    if ($this->email && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException("Email must be a valid email address");
    }

    if (strlen($this->searchDescription) > self::MAX_SEARCH_DESCRIPTION_LENGTH) {
      throw new \InvalidArgumentException("Search description is too long.");
    }
  }

  /**
   * Return the data to be sent for hakuvahti services subscription endpoint.
   */
  public function getServiceRequestData(): array {
    return [
      'email' => $this->email,
      'sms' => $this->sms,
      'lang' => $this->lang,
      'site_id' => $this->siteId,
      'query' => $this->query,
      'elastic_query' => $this->elasticQuery,
      'elastic_query_atv' => $this->elasticQueryAtv,
      'search_description' => $this->searchDescription,
    ];
  }

}
