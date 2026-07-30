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
  public const int MAX_SMS_LENGTH = 500;
  public const int MAX_SUBSCRIPTION_IDS = 10;

  /**
   * Length of the verification code.
   */
  public const int TOTP_CODE_LENGTH = 6;

  /**
   * Subscription ids are MongoDB object ids.
   */
  private const string SUBSCRIPTION_ID_PATTERN = '/^[0-9a-f]{24}$/i';

  /**
   * The verification code is a six digit TOTP code.
   */
  private const string TOTP_CODE_PATTERN = '/^[0-9]{6}$/';

  /**
   * The site id.
   */
  public string $siteId;

  /**
   * Current code from the broadcast authenticator.
   */
  public string $totpCode;

  /**
   * The messages, keyed by langcode.
   *
   * Each message has a subject and a body, and optionally an sms text.
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

    if (!isset($requestData['totpCode'])) {
      throw new \InvalidArgumentException("Request is missing field: totpCode");
    }

    $this->totpCode = trim((string) $requestData['totpCode']);
    if (!preg_match(self::TOTP_CODE_PATTERN, $this->totpCode)) {
      throw new \InvalidArgumentException('The verification code must be ' . self::TOTP_CODE_LENGTH . ' digits.');
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
   * Whether the broadcast contains sms texts.
   */
  public function hasSms(): bool {
    foreach (self::LANGUAGES as $langcode) {
      if (!isset($this->messages[$langcode]['sms'])) {
        return FALSE;
      }
    }
    return TRUE;
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

      if (isset($this->messages[$langcode]['sms'])) {
        $messages[$langcode]['sms'] = $this->messages[$langcode]['sms'];
      }
    }

    $data = [
      'site_id' => $this->siteId,
      'totp_code' => $this->totpCode,
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
      $sms = $this->normalize($messages[$langcode]['sms'] ?? '');

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

      // An empty sms text means the language has no sms message at all.
      if ($sms !== '') {
        if (\mb_strlen($sms) > self::MAX_SMS_LENGTH) {
          throw new \InvalidArgumentException("Sms text is too long for language: $langcode");
        }
        $normalized[$langcode]['sms'] = $sms;
      }
    }

    // Subscribers must not be excluded from an sms broadcast based on their
    // language, so sms texts are all-or-none.
    $smsCount = count(array_filter($normalized, static fn (array $message): bool => isset($message['sms'])));
    if ($smsCount !== 0 && $smsCount !== count(self::LANGUAGES)) {
      throw new \InvalidArgumentException('SMS text must be provided for either all languages or none.');
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
