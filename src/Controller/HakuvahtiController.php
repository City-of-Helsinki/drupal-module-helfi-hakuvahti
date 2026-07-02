<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\HakuvahtiAlreadyConfirmedException;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for handling Hakuvahti confirmations and unsubscriptions.
 */
final class HakuvahtiController extends ControllerBase {

  use StringTranslationTrait;

  private const string SMS_FLOOD_EVENT = 'helfi_hakuvahti.sms_form';
  private const int SMS_FLOOD_THRESHOLD = 10;
  private const int SMS_FLOOD_WINDOW = 3600;

  public function __construct(
    protected readonly HakuvahtiInterface $hakuvahti,
    protected readonly FloodInterface $flood,
    #[Autowire(service: 'logger.channel.helfi_hakuvahti')]
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Handles the confirmation of a saved search.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function confirm(Request $request): array {
    $config = $this->loadConfigBySiteId($request->query->get('site_id'));

    if ($request->query->has('id')) {
      return $this->handleSmsRequest(
        $request,
        $config,
        'helfi_hakuvahti.confirm',
        $this->hakuvahti->confirmSms(...),
        $this->resolveTitle($config, 'confirm_sms_title',
          $this->t('Confirm saved search', options: ['context' => 'Hakuvahti confirm']),
        ),
        $this->resolveBody($config, 'confirm_sms_message',
          $this->t('Please enter the confirmation code that you received by SMS.', options: ['context' => 'Hakuvahti confirm']),
        ),
      );
    }

    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if (!$hash || !$subscription) {
      return $this->confirmErrorResponse($config) + ['#cache' => ['contexts' => ['url']]];
    }

    if ($request->isMethod('POST')) {
      return $this->handleConfirmFormSubmission($hash, $subscription, $config);
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->resolveTitle($config, 'confirm_processing_title',
        $this->t('Activating search alert', options: ['context' => 'Hakuvahti confirm']),
      ),
      '#message' => $this->resolveBody($config, 'confirm_processing_message',
        $this->t('Activating search alert subscription…', options: ['context' => 'Hakuvahti confirm']),
      ),
      '#button_text' => $this->t('Confirm saved search', options: ['context' => 'Hakuvahti confirm']),
      '#autosubmit' => TRUE,
      '#action_url' => Url::fromRoute('helfi_hakuvahti.confirm', [], [
        'query' => array_filter([
          'hash' => $hash,
          'subscription' => $subscription,
          'site_id' => $request->query->get('site_id'),
        ]),
      ]),
      '#cache' => [
        'contexts' => [
          'url',
        ],
      ],
    ];
  }

  /**
   * Handles the activation form submission.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function handleConfirmFormSubmission(string $hash, string $subscription, ?HakuvahtiConfig $config): array {
    try {
      $this->hakuvahti->confirm($hash, $subscription);

      return $this->confirmSuccessResponse($config);
    }
    catch (HakuvahtiAlreadyConfirmedException) {
      return $this->alreadyConfirmedResponse($config);
    }
    catch (HakuvahtiException $exception) {
      $logLevel = match ($exception->getCode()) {
        404 => 'info',
        default => 'error',
      };

      $this->logger->{$logLevel}('Hakuvahti confirmation request failed: ' . $exception->getMessage());
    }

    return $this->confirmErrorResponse($config);
  }

  /**
   * Handles the renewal of a saved search.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function renew(Request $request): array {
    $config = $this->loadConfigBySiteId($request->query->get('site_id'));
    $id = $request->query->get('id');
    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      return $this->handleRenewSubmission(
        $config,
        $id
          ? fn() => $this->hakuvahti->renewSms($id)
          : fn() => $this->hakuvahti->renew($hash, $subscription),
      );
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->resolveTitle($config, 'renew_processing_title',
        $this->t('Renewing saved search', options: ['context' => 'Hakuvahti renew']),
      ),
      '#message' => $this->resolveBody($config, 'renew_processing_message',
        $this->t('Please wait while the saved search is being renewed.', options: ['context' => 'Hakuvahti renew']),
      ),
      '#button_text' => $this->t('Renew saved search', options: ['context' => 'Hakuvahti renew']),
      '#autosubmit' => TRUE,
      '#action_url' => $id
        ? Url::fromRoute('helfi_hakuvahti.renew', [], [
          'query' => array_filter([
            'id' => $id,
            'site_id' => $request->query->get('site_id'),
          ]),
        ])
        : Url::fromRoute('helfi_hakuvahti.renew', [], [
          'query' => array_filter([
            'hash' => $hash,
            'subscription' => $subscription,
            'site_id' => $request->query->get('site_id'),
          ]),
        ]),
      '#cache' => [
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Handles the renewal submission for both email and SMS.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function handleRenewSubmission(?HakuvahtiConfig $config, callable $action): array {
    try {
      $action();

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->resolveTitle($config, 'renew_success_title',
          $this->t('Search renewed successfully', options: ['context' => 'Hakuvahti renew success']),
        ),
        '#message' => $this->resolveBody($config, 'renew_success_body',
          $this->t('Your saved search has been renewed.', options: ['context' => 'Hakuvahti renew success']),
        ),
      ];
    }
    catch (HakuvahtiException $exception) {
      // 404 error is returned if:
      // * Submission has been deleted after it expired.
      // * Submission does not exist.
      $logLevel = $exception->getCode() === 404 ? 'info' : 'error';
      $this->logger->{$logLevel}('Hakuvahti renewal request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->resolveTitle($config, 'renew_failure_title',
        $this->t('Renewal failed', options: ['context' => 'Hakuvahti renew failure']),
      ),
      '#message' => $this->resolveBody($config, 'renew_failure_body',
        $this->t('Renewing saved search failed. Please try again.', options: ['context' => 'Hakuvahti renew failure']),
      ),
    ];
  }

  /**
   * Handles the unsubscription from a saved search.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function unsubscribe(Request $request): array {
    $config = $this->loadConfigBySiteId($request->query->get('site_id'));
    $id = $request->query->get('id');
    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      if (!$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW)) {
        return $this->tooManyRequestsResponse();
      }

      $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW);

      return $this->handleUnsubscribeSubmission(
        $config,
        $id
          ? fn() => $this->hakuvahti->deleteSms($id)
          : fn() => $this->hakuvahti->unsubscribe($hash, $subscription),
      );
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->resolveTitle($config, 'unsubscribe_processing_title',
        $this->t('Removing search alert', options: ['context' => 'Hakuvahti unsubscribe']),
      ),
      '#message' => $this->resolveBody($config, 'unsubscribe_processing_message',
        $this->t('Removing search alert subscription…', options: ['context' => 'Hakuvahti unsubscribe']),
      ),
      '#button_text' => $this->t('Delete saved search', options: ['context' => 'Hakuvahti unsubscribe']),
      '#autosubmit' => TRUE,
      '#action_url' => $id
        ? Url::fromRoute('helfi_hakuvahti.unsubscribe', [], [
          'query' => array_filter([
            'id' => $id,
            'site_id' => $request->query->get('site_id'),
          ]),
        ])
        : Url::fromRoute('helfi_hakuvahti.unsubscribe', [], [
          'query' => array_filter([
            'hash' => $hash,
            'subscription' => $subscription,
            'site_id' => $request->query->get('site_id'),
          ]),
        ]),
      '#cache' => [
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Handles the unsubscribe submission for both email and SMS.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function handleUnsubscribeSubmission(?HakuvahtiConfig $config, callable $action): array {
    try {
      $action();

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->resolveTitle($config, 'unsubscribe_success_title',
          $this->t('The search alert has been removed', options: ['context' => 'Hakuvahti unsubscribe success']),
        ),
        '#message' => $this->resolveBody($config, 'unsubscribe_success_body', [
          $this->t('The search alert has now been removed.', options: ['context' => 'Hakuvahti unsubscribe success']),
          $this->t('You can subscribe to new search alerts at any time.', options: ['context' => 'Hakuvahti unsubscribe success']),
        ]),
      ];
    }
    catch (HakuvahtiException $exception) {
      if ($exception->getCode() === 404) {
        return [
          '#theme' => 'hakuvahti_confirmation',
          '#title' => $this->resolveTitle($config, 'unsubscribe_not_found_title',
            $this->t('Saved search not found', options: ['context' => 'Hakuvahti unsubscribe not found']),
          ),
          '#message' => $this->resolveBody($config, 'unsubscribe_not_found_body',
            $this->t('Saved search was not found. It might be already removed.', options: ['context' => 'Hakuvahti unsubscribe not found']),
          ),
        ];
      }

      $this->logger->error('Hakuvahti unsubscribe request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->resolveTitle($config, 'unsubscribe_failure_title',
        $this->t('Search alert removal failed', options: ['context' => 'Hakuvahti unsubscribe failure']),
      ),
      '#message' => $this->resolveBody($config, 'unsubscribe_failure_body',
        $this->t('Search alert removal failed You can try removing the search alert again.', options: ['context' => 'Hakuvahti unsubscribe failure']),
      ),
    ];
  }

  /**
   * Handles an SMS subscription request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig|null $config
   *   The config entity.
   * @param string $route
   *   The route name.
   * @param callable $callback
   *   The action callback.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The form title.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup|array<mixed> $message
   *   The form message.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function handleSmsRequest(
    Request $request,
    ?HakuvahtiConfig $config,
    string $route,
    callable $callback,
    string|TranslatableMarkup $title,
    string|TranslatableMarkup|array $message,
  ): array {
    $id = $request->query->get('id', '');
    $actionUrl = Url::fromRoute($route, [], [
      'query' => array_filter(['id' => $id, 'site_id' => $request->query->get('site_id')]),
    ]);

    $buttonText = $this->resolveTitle($config, 'confirm_sms_button',
      $this->t('Confirm saved search', options: ['context' => 'Hakuvahti confirm']),
    );

    if ($request->isMethod('POST')) {
      return $this->handleSmsSubmission($request, $config, $id, $callback, $message, $buttonText, $actionUrl);
    }

    if (!$id) {
      return $this->confirmErrorResponse($config) + [
        '#cache' => [
          'contexts' => ['url'],
        ],
      ];
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $title,
      '#message' => $message,
      '#button_text' => $buttonText,
      '#action_url' => $actionUrl,
      '#fields' => [
        ['type' => 'hidden', 'name' => 'id', 'value' => $id],
        [
          'type' => 'text',
          'name' => 'code',
          'label' => $this->t('Confirmation code', options: ['context' => 'Hakuvahti confirm']),
          'required' => TRUE,
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

  /**
   * Handles SMS form submission with flood protection.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig|null $config
   *   The config entity.
   * @param string $id
   *   The subscription ID.
   * @param callable $callback
   *   The action callback.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup|array<mixed> $message
   *   The form message.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $buttonText
   *   The submit button label.
   * @param \Drupal\Core\Url $actionUrl
   *   The form action URL.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function handleSmsSubmission(
    Request $request,
    ?HakuvahtiConfig $config,
    string $id,
    callable $callback,
    string|TranslatableMarkup|array $message,
    string|TranslatableMarkup $buttonText,
    Url $actionUrl,
  ): array {
    $code = $request->request->get('code', '');

    if (
      !$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW) ||
      !$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW, $id)
    ) {
      return $this->tooManyRequestsResponse();
    }

    $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW);
    $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW, $id);

    try {
      $callback($id, $code);

      return $this->confirmSuccessResponse($config);
    }
    catch (HakuvahtiAlreadyConfirmedException) {
      return $this->alreadyConfirmedResponse($config);
    }
    catch (HakuvahtiException $e) {
      $this->logger->error('Hakuvahti SMS request failed: ' . $e->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->confirmErrorTitle($config),
      '#message' => $message,
      '#button_text' => $buttonText,
      '#action_url' => $actionUrl,
      '#fields' => [
        ['type' => 'hidden', 'name' => 'id', 'value' => $id],
        [
          'type' => 'text',
          'name' => 'code',
          'label' => $this->t('Confirmation code', options: ['context' => 'Hakuvahti confirm']),
          'required' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Returns the render array for a flood-limited response.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function tooManyRequestsResponse(): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Too many requests', options: ['context' => 'Hakuvahti flood']),
      '#message' => $this->t('Too many requests, please try again later.', options: ['context' => 'Hakuvahti flood']),
    ];
  }

  /**
   * Returns the error title for a failed confirmation.
   */
  private function confirmErrorTitle(?HakuvahtiConfig $config): TranslatableMarkup|string {
    return $this->resolveTitle($config, 'confirm_failure_title',
      $this->t('Search alert confirmation failed', options: ['context' => 'Hakuvahti confirm failure']),
    );
  }

  /**
   * Returns the render array for a successful confirmation.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function confirmSuccessResponse(?HakuvahtiConfig $config): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->resolveTitle($config, 'confirm_success_title',
        $this->t('Search alert subscription successful', options: ['context' => 'Hakuvahti confirm success']),
      ),
      '#message' => $this->resolveBody($config, 'confirm_success_body', [
        $this->t('You will be notified of new search matches no more than once a day.', options: ['context' => 'Hakuvahti confirm success']),
        $this->t('You can cancel your subscription using the link sent with each notification.', options: ['context' => 'Hakuvahti confirm success']),
        // @todo the backend should return how long the search alert is valid.
        // We have no idea here and it is controlled by the config file.
        $this->t('You can subscribe to new search alerts at any time. The alerts are valid for six months.', options: ['context' => 'Hakuvahti confirm success']),
      ]),
    ];
  }

  /**
   * Returns the render array for a failed confirmation.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function confirmErrorResponse(?HakuvahtiConfig $config): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->confirmErrorTitle($config),
      '#message' => $this->resolveBody($config, 'confirm_failure_body',
        $this->t('Your search alert could not be confirmed. You can try confirming the search alert again.', options: ['context' => 'Hakuvahti confirm failure']),
      ),
    ];
  }

  /**
   * Returns the render array for an already confirmed subscription.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function alreadyConfirmedResponse(?HakuvahtiConfig $config): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->resolveTitle($config, 'already_confirmed_title',
        $this->t('This search alert has already been confirmed', options: ['context' => 'Hakuvahti already confirmed']),
      ),
      '#message' => $this->resolveBody($config, 'already_confirmed_body', [
        $this->t('You have already confirmed this search alert.', options: ['context' => 'Hakuvahti already confirmed']),
        $this->t('You will receive email alerts about new search results up to once a day.', options: ['context' => 'Hakuvahti already confirmed']),
        $this->t('Each email contains an unsubscribe link that you can use to unsubscribe from saved search alerts. You can save a new search at any time.', options: ['context' => 'Hakuvahti already confirmed']),
      ]),
    ];
  }

  /**
   * Title callback for the confirm route.
   */
  public function confirmTitle(Request $request): TranslatableMarkup|string {
    return $this->resolveTitle(
      $this->loadConfigBySiteId($request->query->get('site_id')),
      'confirm_page_title',
      $this->t('Saved search confirmation'),
    );
  }

  /**
   * Title callback for the renew route.
   */
  public function renewTitle(Request $request): TranslatableMarkup|string {
    return $this->resolveTitle(
      $this->loadConfigBySiteId($request->query->get('site_id')),
      'renew_page_title',
      $this->t('Renew saved search'),
    );
  }

  /**
   * Title callback for the unsubscribe route.
   */
  public function unsubscribeTitle(Request $request): TranslatableMarkup|string {
    return $this->resolveTitle(
      $this->loadConfigBySiteId($request->query->get('site_id')),
      'unsubscribe_page_title',
      $this->t('Saved search deletion'),
    );
  }

  /**
   * Loads a HakuvahtiConfig entity matching the given site_id.
   */
  private function loadConfigBySiteId(?string $siteId): ?HakuvahtiConfig {
    if (!$siteId) {
      return NULL;
    }
    /** @var \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig[] $entities */
    $entities = $this->entityTypeManager()
      ->getStorage('hakuvahti_config')
      ->loadByProperties(['site_id' => $siteId]);
    return $entities ? reset($entities) : NULL;
  }

  /**
   * Returns the custom title if set, otherwise the translated default.
   */
  private function resolveTitle(?HakuvahtiConfig $config, string $key, TranslatableMarkup $default): TranslatableMarkup|string {
    $custom = $config?->getConfirmationText($key) ?? '';
    return $custom !== '' ? $custom : $default;
  }

  /**
   * Returns the custom body if set, otherwise the translated default.
   *
   * A non-empty body string is split on newlines into paragraphs.
   *
   * @param \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig|null $config
   *   Hakuvahti configuration entity.
   * @param string $key
   *   Configuration key.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|array<mixed> $default
   *   Default body value.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|array<mixed>
   *   Body text or array of paragraphs.
   */
  private function resolveBody(?HakuvahtiConfig $config, string $key, TranslatableMarkup|array $default): TranslatableMarkup|string|array {
    $custom = $config?->getConfirmationText($key) ?? '';
    if ($custom === '') {
      return $default;
    }
    $paragraphs = array_values(array_filter(array_map('trim', explode("\n", $custom))));
    if (empty($paragraphs)) {
      return $default;
    }
    return \count($paragraphs) === 1 ? $paragraphs[0] : $paragraphs;
  }

}
