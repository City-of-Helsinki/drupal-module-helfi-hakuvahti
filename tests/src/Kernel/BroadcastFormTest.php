<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\BroadcastRequest;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\Form\BroadcastForm;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests for the broadcast form.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class BroadcastFormTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;

  /**
   * Requests made through the mocked HTTP client.
   *
   * @var array<mixed>
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'language',
    'helfi_hakuvahti',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['helfi_hakuvahti']);

    // The form labels the message fields with the language names, so every
    // broadcast language has to be installed.
    foreach (BroadcastRequest::LANGUAGES as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->set('api_key', '123')
      ->save();

    // The shipped default configuration has no site id.
    HakuvahtiConfig::create([
      'id' => 'news',
      'label' => 'News',
      'site_id' => 'etusivu',
    ])->save();

    $this->setUpCurrentUser(permissions: ['send hakuvahti broadcast']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());
  }

  /**
   * Tests that a broadcast redirects to the status page.
   */
  public function testSendToAllSubscribers(): void {
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($this->history, [
      new Response(202, body: '{"id":"0123456789abcdef01234567"}'),
    ]));

    $formState = $this->submit($this->values(), 'send_all');

    $this->assertEmpty($formState->getErrors());

    // FormState::getRedirect() returns FALSE for programmed submissions, so
    // the flag has to be reset before the redirect can be inspected.
    $formState->setProgrammed(FALSE);
    $redirect = $formState->getRedirect();

    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('helfi_hakuvahti.broadcast_status', $redirect->getRouteName());
    $this->assertSame(['broadcast_id' => '0123456789abcdef01234567'], $redirect->getRouteParameters());

    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertSame('etusivu', $payload['site_id']);
    $this->assertSame('FI subject', $payload['messages']['fi']['subject']);
    // Subscription ids are only sent for test broadcasts.
    $this->assertArrayNotHasKey('subscription_ids', $payload);
  }

  /**
   * Tests that a test broadcast targets the given subscriptions.
   */
  public function testSendTestMessage(): void {
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($this->history, [
      new Response(202, body: '{"id":"0123456789abcdef01234567"}'),
    ]));

    $formState = $this->submit([
      'subscription_ids' => "0123456789abcdef01234567\nfedcba9876543210fedcba98",
    ] + $this->values(), 'send_test');

    $this->assertEmpty($formState->getErrors());

    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertSame([
      '0123456789abcdef01234567',
      'fedcba9876543210fedcba98',
    ], $payload['subscription_ids']);
  }

  /**
   * Submits the form.
   *
   * @param array<string, mixed> $values
   *   The values to submit.
   * @param string $button
   *   Name of the button that triggered the submission.
   */
  private function submit(array $values, string $button): FormState {
    $formState = new FormState();
    $formState->setValues($values);
    $formState->setTriggeringElement([
      '#name' => $button,
      '#parents' => [],
      '#array_parents' => [],
    ]);

    $this->container->get(FormBuilderInterface::class)->submitForm(BroadcastForm::class, $formState);

    return $formState;
  }

  /**
   * Gets a valid set of form values.
   *
   * @phpstan-return array<string, mixed>
   */
  private function values(): array {
    $messages = [];
    foreach (BroadcastRequest::LANGUAGES as $langcode) {
      $prefix = strtoupper($langcode);
      $messages[$langcode] = [
        'subject' => "$prefix subject",
        'body' => "$prefix body",
        'sms' => '',
      ];
    }

    return [
      'site_id' => 'etusivu',
      'totp_code' => '123456',
      'messages' => $messages,
      'subscription_ids' => '',
    ];
  }

}
