<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\helfi_hakuvahti\BroadcastRequest;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\helfi_tunnistamo\TokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Form for sending a broadcast message to hakuvahti subscribers.
 */
final class BroadcastForm extends FormBase {

  private const string BUTTON_SEND_TEST = 'send_test';
  private const string BUTTON_SEND_ALL = 'send_all';

  public function __construct(
    private readonly HakuvahtiInterface $hakuvahti,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TokenManagerInterface $tokenManager,
    #[Autowire(service: 'logger.channel.helfi_hakuvahti')]
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'helfi_hakuvahti_broadcast_form';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig[] $configs */
    $configs = $this->entityTypeManager->getStorage('hakuvahti_config')->loadMultiple();
    $siteIds = array_reduce(
      $configs,
      static function (array $result, HakuvahtiConfig $config) {
        // Some hakuvahti_config entities are just broken.
        if ($config->getSiteId()) {
          $result[$config->getSiteId()] = $config;
        }
        return $result;
      },
      []
    );
    ksort($siteIds);

    $hasSmsSites = array_any($siteIds, static fn (HakuvahtiConfig $config) => $config->isSmsEnabled());

    $cache = new CacheableMetadata();
    $cache->addCacheTags($this->entityTypeManager->getDefinition('hakuvahti_config')->getListCacheTags());
    // Whether the form can be used at all depends on how the user logged in.
    $cache->addCacheContexts(['user']);
    $cache->applyTo($form);

    if (!$this->tokenManager->hasSession()) {
      $form['no_session'] = [
        '#type' => 'item',
        '#markup' => $this->t('Broadcast messages are sent on behalf of the user sending them, which requires a Helsinki AD login. Log out and log back in using the Helsinki AD button to send a broadcast.', options: ['context' => 'Hakuvahti broadcast']),
      ];
      return $form;
    }

    if (!$siteIds) {
      $form['no_sites'] = [
        '#type' => 'item',
        '#markup' => $this->t('No Hakuvahti configuration has a site ID, so there is nothing to broadcast to.', options: ['context' => 'Hakuvahti broadcast']),
      ];
      return $form;
    }

    $form['description'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        '<p>The message is sent to every subscriber of the selected site. Sending cannot be undone.</p><p>Sending uses your Helsinki AD login, which expires sooner than your Drupal session. If sending fails, log out and log back in using the Helsinki AD button to get a new login, then try again. Sending also requires that your Helsinki AD account belongs to a group that is allowed to broadcast for the selected site.</p>',
        options: ['context' => 'Hakuvahti broadcast']
      ),
    ];

    if (count($siteIds) === 1) {
      $form['site_id'] = [
        '#type' => 'value',
        '#value' => array_key_first($siteIds),
      ];
      $form['site'] = [
        '#type' => 'item',
        '#title' => $this->t('Hakuvahti', options: ['context' => 'Hakuvahti broadcast']),
        '#plain_text' => array_first($siteIds)->label(),
      ];
    }
    else {
      $form['site_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Site', options: ['context' => 'Hakuvahti broadcast']),
        '#options' => array_map(static fn (HakuvahtiConfig $config) => $config->label(), $siteIds),
        '#required' => TRUE,
        '#empty_value' => '',
        '#empty_option' => $this->t('- Select a site -', options: ['context' => 'Hakuvahti broadcast']),
      ];
    }

    // Hakuvahti composes the sms of a site that sends them from the same
    // subject and body as the email. It has no endpoint for asking which
    // sites those are, so the notice is based on Drupal side configuration.
    if ($hasSmsSites) {
      $form['sms_notice'] = [
        '#type' => 'item',
        '#markup' => $this->t('On a site that sends text messages, subscribers who have confirmed a phone number also receive the message as a text message, composed of the same subject and message. A long message is delivered as several text messages.', options: ['context' => 'Hakuvahti broadcast']),
      ];
    }

    $form['messages'] = [
      '#tree' => TRUE,
    ];

    foreach (BroadcastRequest::LANGUAGES as $langcode) {
      $language = strtoupper($langcode);

      $form['messages'][$langcode] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('Message in @language', [
          '@language' => $language,
        ], options: ['context' => 'Hakuvahti broadcast']),
      ];

      $form['messages'][$langcode]['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject (@language)', [
          '@language' => $language,
        ], options: ['context' => 'Hakuvahti broadcast']),
        '#required' => TRUE,
        '#maxlength' => BroadcastRequest::MAX_SUBJECT_LENGTH,
      ];

      $form['messages'][$langcode]['body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Message (@language)', [
          '@language' => $language,
        ], options: ['context' => 'Hakuvahti broadcast']),
        '#required' => TRUE,
        '#rows' => 12,
        '#maxlength' => BroadcastRequest::MAX_BODY_LENGTH,
      ];
    }

    $form['subscription_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Test recipients: subscription IDs', options: ['context' => 'Hakuvahti broadcast']),
      '#rows' => 3,
      '#description' => $this->t('One subscription ID per line. Only used by the "Send test message" button, and must be empty when sending to all subscribers. Get this value from your subscription\'s unsubscribe link.', options: ['context' => 'Hakuvahti broadcast']),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions'][self::BUTTON_SEND_TEST] = [
      '#type' => 'submit',
      '#name' => self::BUTTON_SEND_TEST,
      '#value' => $this->t('Send test message', options: ['context' => 'Hakuvahti broadcast']),
      '#weight' => 0,
    ];

    $form['actions'][self::BUTTON_SEND_ALL] = [
      '#type' => 'submit',
      '#name' => self::BUTTON_SEND_ALL,
      '#value' => $this->t('Send to all subscribers', options: ['context' => 'Hakuvahti broadcast']),
      '#button_type' => 'primary',
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $isTest = $this->isTestSend($form_state);

    if ($isTest === NULL) {
      // Never fall back to sending to everyone.
      $this->messenger()->addError($this->t('Failed to send broadcast.', options: ['context' => 'Hakuvahti broadcast']));
      return;
    }

    $siteId = (string) $form_state->getValue('site_id');
    $messages = (array) $form_state->getValue('messages');

    // Resolve the token before anything is logged as attempted: without one
    // there is nothing to send.
    if (!$accessToken = $this->tokenManager->getAccessToken()) {
      $this->logger->warning(sprintf(
        'Hakuvahti broadcast by uid %s was not attempted because the session has no valid access token.',
        $this->currentUser()->id(),
      ));
      $this->messenger()->addError($this->t('Your login session has expired. Nothing was sent. Log out and log back in using the Helsinki AD button, then try again.', options: ['context' => 'Hakuvahti broadcast']));
      return;
    }

    try {
      $request = new BroadcastRequest([
        'siteId' => $siteId,
        'messages' => $messages,
        'subscriptionIds' => $isTest ? $this->parseSubscriptionIds((string) $form_state->getValue('subscription_ids')) : [],
      ]);
    }
    catch (\InvalidArgumentException $exception) {
      // Validation should have caught this already.
      $this->logger->error('Hakuvahti broadcast could not be built: ' . $exception->getMessage());
      $this->messenger()->addError($this->t('The message could not be sent: @message', [
        '@message' => $exception->getMessage(),
      ], options: ['context' => 'Hakuvahti broadcast']));
      return;
    }

    // Log the attempt before sending. If the request times out this is the
    // only record that the broadcast was attempted.
    // @todo this should send audit logging event (requires UHF-13284).
    $this->logger->notice(sprintf(
      'Hakuvahti broadcast requested by uid %s for site %s. Test: %s. Subjects: %s.',
      $this->currentUser()->id(),
      $request->siteId,
      $request->isTest() ? 'yes' : 'no',
      implode(' | ', array_map(
        static fn (string $langcode, array $message): string => "$langcode: {$message['subject']}",
        array_keys($request->messages),
        $request->messages,
      )),
    ));

    try {
      $this->hakuvahti->broadcast($request, $accessToken);
    }
    catch (HakuvahtiException $exception) {
      // Hakuvahti reports the reason as the HTTP status code.
      $error = match ($exception->getCode()) {
        400 => $this->t('Hakuvahti rejected the message. Nothing was sent. Check the message and try again.', options: ['context' => 'Hakuvahti broadcast']),
        // Hakuvahti answers 403 both when the access token is no longer valid
        // and when the user is not in an AD group allowed to broadcast for the
        // selected site.
        403 => $this->t('Hakuvahti did not accept the request. Nothing was sent. Your login session may have expired, in which case logging out and back in using the Helsinki AD button helps. Otherwise your account does not have permission to broadcast for this site.', options: ['context' => 'Hakuvahti broadcast']),
        default => $this->t('Sending the broadcast message failed. The message may still have been sent, check Hakuvahti before trying again.', options: ['context' => 'Hakuvahti broadcast']),
      };

      $this->logger->warning(sprintf(
        'Hakuvahti broadcast request failed (status %s): %s',
        $exception->getCode(),
        $exception->getMessage(),
      ));
      $this->messenger()->addError($error);
      return;
    }

    $this->logger->notice(sprintf('Hakuvahti accepted a broadcast for site %s.', $request->siteId));
    $this->messenger()->addStatus($this->t('The message was accepted for sending. Delivery runs in the background and may take several minutes.', options: ['context' => 'Hakuvahti broadcast']));
  }

  /**
   * Resolves which button was used to submit the form.
   *
   * @return bool|null
   *   TRUE for a test send, FALSE for a send to all subscribers, or NULL if
   *   the triggering element was not recognized.
   */
  private function isTestSend(FormStateInterface $form_state): ?bool {
    return match ($form_state->getTriggeringElement()['#name'] ?? NULL) {
      self::BUTTON_SEND_TEST => TRUE,
      self::BUTTON_SEND_ALL => FALSE,
      default => NULL,
    };
  }

  /**
   * Parses the submitted subscription ids.
   *
   * @phpstan-return array<int, string>
   */
  private function parseSubscriptionIds(string $value): array {
    return preg_split('/[\s,]+/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];
  }

}
