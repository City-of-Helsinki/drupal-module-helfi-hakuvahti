<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti;

/**
 * Hakuvahti broadcast request data object.
 */
final readonly class BroadcastRequest {

  /**
   * Languages hakuvahti requires for every broadcast.
   */
  public const array LANGUAGES = ['fi', 'sv', 'en'];

  public const int MAX_SUBJECT_LENGTH = 255;
  public const int MAX_BODY_LENGTH = 10000;
  public const int MAX_SUBSCRIPTION_IDS = 10;

  /**
   * Subscription ids are MongoDB object ids.
   */
  private const string SUBSCRIPTION_ID_PATTERN = '/^[0-9a-f]{24}$/i';

  /**
   * The site id.
   */
  public string $siteId;

  /**
   * The messages, keyed by langcode.
   *
   * @phpstan-var array<string, array<string, string>>
   */
  public array $messages;

  /**
   * Subscription ids to send to. Empty when sending to all subscribers.
   *
   * @var array<int, string>
   */
  public array $subscriptionIds;

  /**
   * Constructs a BroadcastRequest.
   *
   * @param array<string, mixed> $requestData
   *   Broadcast payload, keyed by siteId, messages and subscriptionIds.
   */
  public function __construct(array $requestData) {
    if (!isset($requestData['siteId'])) {
      throw new \InvalidArgumentException("Request is missing field: siteId");
    }

    $this->siteId = trim((string) $requestData['siteId']);
    if ($this->siteId === '') {
      throw new \InvalidArgumentException("Required field value is empty: siteId");
    }

    if (!isset($requestData['messages']) || !is_array($requestData['messages'])) {
      throw new \InvalidArgumentException("Request is missing field: messages");
    }

    $this->messages = $this->buildMessages($requestData['messages']);
    $this->subscriptionIds = $this->buildSubscriptionIds($requestData['subscriptionIds'] ?? []);
  }

  /**
   * Whether this is a test broadcast targeting individual subscriptions.
   */
  public function isTest(): bool {
    return $this->subscriptionIds !== [];
  }

  /**
   * Return the data to be sent to hakuvahti's broadcast endpoint.
   *
   * @return array<string, mixed>
   *   Broadcast payload.
   */
  public function getServiceRequestData(): array {
    $messages = [];
    foreach (self::LANGUAGES as $langcode) {
      $messages[$langcode] = [
        'subject' => $this->messages[$langcode]['subject'],
        'body' => $this->messages[$langcode]['body'],
      ];
    }

    $data = [
      'site_id' => $this->siteId,
      'messages' => $messages,
    ];

    if ($this->subscriptionIds !== []) {
      $data['subscription_ids'] = $this->subscriptionIds;
    }

    return $data;
  }

  /**
   * Validates and normalizes the messages.
   *
   * @param array<mixed> $messages
   *   Messages keyed by langcode.
   *
   * @phpstan-return array<string, array<string, string>>
   */
  private function buildMessages(array $messages): array {
    $normalized = [];

    foreach (self::LANGUAGES as $langcode) {
      if (!isset($messages[$langcode]) || !is_array($messages[$langcode])) {
        throw new \InvalidArgumentException("Request is missing field: messages.$langcode");
      }

      $subject = $this->normalize($messages[$langcode]['subject'] ?? '');
      $body = $this->normalize($messages[$langcode]['body'] ?? '');

      if ($subject === '') {
        throw new \InvalidArgumentException("Required field value is empty: messages.$langcode.subject");
      }
      if (\mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
        throw new \InvalidArgumentException("Message subject is too long for language: $langcode");
      }

      if ($body === '') {
        throw new \InvalidArgumentException("Required field value is empty: messages.$langcode.body");
      }
      if (\mb_strlen($body) > self::MAX_BODY_LENGTH) {
        throw new \InvalidArgumentException("Message body is too long for language: $langcode");
      }

      $normalized[$langcode] = [
        'subject' => $subject,
        'body' => $body,
      ];
    }

    return $normalized;
  }

  /**
   * Validates and normalizes the subscription ids.
   *
   * @param mixed $subscriptionIds
   *   Subscription ids.
   *
   * @return array<int, string>
   *   Normalized subscription ids.
   */
  private function buildSubscriptionIds(mixed $subscriptionIds): array {
    if (!is_array($subscriptionIds)) {
      throw new \InvalidArgumentException('Subscription ids must be an array.');
    }

    $ids = array_values(array_unique(array_filter(array_map(
      static fn (mixed $id): string => trim((string) $id),
      $subscriptionIds,
    ))));

    foreach ($ids as $id) {
      if (!preg_match(self::SUBSCRIPTION_ID_PATTERN, $id)) {
        throw new \InvalidArgumentException("Invalid subscription id: $id");
      }
    }

    if (count($ids) > self::MAX_SUBSCRIPTION_IDS) {
      throw new \InvalidArgumentException('A test broadcast accepts at most ' . self::MAX_SUBSCRIPTION_IDS . ' subscription ids.');
    }

    return $ids;
  }

  /**
   * Normalizes a submitted text value.
   *
   * @param mixed $value
   *   Submitted value.
   */
  private function normalize(mixed $value): string {
    return trim(str_replace("\r\n", "\n", (string) $value));
  }

}
