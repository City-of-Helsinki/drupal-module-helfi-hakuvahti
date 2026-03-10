<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\HakuvahtiAlreadyConfirmedException;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for handling Hakuvahti confirmations and unsubscriptions.
 */
final class HakuvahtiController extends ControllerBase implements LoggerAwareInterface {

  use StringTranslationTrait;
  use LoggerAwareTrait;

  private const string SMS_FLOOD_EVENT = 'helfi_hakuvahti.sms_form';
  private const int SMS_FLOOD_THRESHOLD = 10;
  private const int SMS_FLOOD_WINDOW = 3600;

  public function __construct(
    protected readonly HakuvahtiInterface $hakuvahti,
    protected readonly FloodInterface $flood,
  ) {
  }

  /**
   * Handles the confirmation of a saved search.
   */
  public function confirm(Request $request): array {
    if ($request->query->has('id')) {
      return $this->handleSmsRequest(
        $request,
        'helfi_hakuvahti.confirm',
        fn(string $id, string $code) => $this->hakuvahti->confirmSms($id, $code),
        $this->t('Confirm saved search', options: ['context' => 'Hakuvahti confirm']),
        $this->t('Please enter the confirmation code that you received by SMS.', options: ['context' => 'Hakuvahti confirm']),
      );
    }

    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      return $this->handleConfirmFormSubmission($hash, $subscription);
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Enabling saved search', options: ['context' => 'Hakuvahti confirm']),
      '#message' => $this->t('Please wait while the saved search is being enabled.', options: ['context' => 'Hakuvahti confirm']),
      '#button_text' => $this->t('Confirm saved search', options: ['context' => 'Hakuvahti confirm']),
      '#autosubmit' => TRUE,
      '#action_url' => Url::fromRoute('helfi_hakuvahti.confirm', [], [
        'query' => [
          'hash' => $hash,
          'subscription' => $subscription,
        ],
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
   */
  private function handleConfirmFormSubmission(string $hash, string $subscription): array {
    try {
      $this->hakuvahti->confirm($hash, $subscription);

      return $this->confirmSuccessResponse();
    }
    catch (HakuvahtiAlreadyConfirmedException) {
      return $this->alreadyConfirmedResponse();
    }
    catch (HakuvahtiException $exception) {
      $logLevel = match ($exception->getCode()) {
        404 => 'info',
        default => 'error',
      };

      $this->logger?->{$logLevel}('Hakuvahti confirmation request failed: ' . $exception->getMessage());
    }

    return $this->confirmErrorResponse();
  }

  /**
   * Handles the renewal of a saved search.
   */
  public function renew(Request $request): array {
    $id = $request->query->get('id');
    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      return $this->handleRenewSubmission(
        $id
          ? fn() => $this->hakuvahti->renewSms($id)
          : fn() => $this->hakuvahti->renew($hash, $subscription),
      );
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Renewing saved search', options: ['context' => 'Hakuvahti renew']),
      '#message' => $this->t('Please wait while the saved search is being renewed.', options: ['context' => 'Hakuvahti renew']),
      '#button_text' => $this->t('Renew saved search', options: ['context' => 'Hakuvahti renew']),
      '#autosubmit' => TRUE,
      '#action_url' => $id
        ? Url::fromRoute('helfi_hakuvahti.renew', [], ['query' => ['id' => $id]])
        : Url::fromRoute('helfi_hakuvahti.renew', [], ['query' => ['hash' => $hash, 'subscription' => $subscription]]),
      '#cache' => [
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Handles the renewal submission for both email and SMS.
   */
  private function handleRenewSubmission(callable $action): array {
    try {
      $action();

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Search renewed successfully', options: ['context' => 'Hakuvahti renew success']),
        '#message' => $this->t('Your saved search has been renewed.', options: ['context' => 'Hakuvahti renew success']),
      ];
    }
    catch (HakuvahtiException $exception) {
      // 404 error is returned if:
      // * Submission has been deleted after it expired.
      // * Submission does not exist.
      $logLevel = $exception->getCode() === 404 ? 'info' : 'error';
      $this->logger?->{$logLevel}('Hakuvahti renewal request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Renewal failed', options: ['context' => 'Hakuvahti renew failure']),
      '#message' => $this->t('Renewing saved search failed. Please try again.', options: ['context' => 'Hakuvahti renew failure']),
    ];
  }

  /**
   * Handles the unsubscription from a saved search.
   */
  public function unsubscribe(Request $request): array {
    $id = $request->query->get('id');
    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      if (!$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW)) {
        return $this->tooManyRequestsResponse();
      }

      $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW);

      return $this->handleUnsubscribeSubmission(
        $id
          ? fn() => $this->hakuvahti->deleteSms($id)
          : fn() => $this->hakuvahti->unsubscribe($hash, $subscription),
      );
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Deleting saved search', options: ['context' => 'Hakuvahti unsubscribe']),
      '#message' => $this->t('Please wait while the saved search is being deleted. If you have other searches saved on the City website, this link will not delete them.', options: ['context' => 'Hakuvahti unsubscribe']),
      '#button_text' => $this->t('Delete saved search', options: ['context' => 'Hakuvahti unsubscribe']),
      '#autosubmit' => TRUE,
      '#action_url' => $id
        ? Url::fromRoute('helfi_hakuvahti.unsubscribe', [], ['query' => ['id' => $id]])
        : new Url('helfi_hakuvahti.unsubscribe', [], ['query' => ['hash' => $hash, 'subscription' => $subscription]]),
      '#cache' => [
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Handles the unsubscribe submission for both email and SMS.
   */
  private function handleUnsubscribeSubmission(callable $action): array {
    try {
      $action();

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('The search alert has been removed', options: ['context' => 'Hakuvahti unsubscribe success']),
        '#message' => [
          $this->t('The search alert has now been removed.', options: ['context' => 'Hakuvahti unsubscribe success']),
          $this->t('You can subscribe to new search alerts at any time.', options: ['context' => 'Hakuvahti unsubscribe success']),
        ],
      ];
    }
    catch (HakuvahtiException $exception) {
      if ($exception->getCode() === 404) {
        return [
          '#theme' => 'hakuvahti_confirmation',
          '#title' => $this->t('Saved search not found', options: ['context' => 'Hakuvahti unsubscribe not found']),
          '#message' => $this->t('Saved search was not found. It might be already removed.', options: ['context' => 'Hakuvahti unsubscribe not found']),
        ];
      }

      $this->logger?->error('Hakuvahti unsubscribe request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Search alert removal failed', options: ['context' => 'Hakuvahti unsubscribe failure']),
      '#message' => $this->t('Search alert removal failed You can try removing the search alert again.', options: ['context' => 'Hakuvahti unsubscribe failure']),
    ];
  }

  /**
   * Handles an SMS subscription request.
   */
  private function handleSmsRequest(
    Request $request,
    string $route,
    callable $callback,
    TranslatableMarkup $title,
    TranslatableMarkup $message,
  ): array {
    $id = $request->query->get('id', '');
    $actionUrl = Url::fromRoute($route, [], [
      'query' => ['id' => $id],
    ]);

    if ($request->isMethod('POST')) {
      return $this->handleSmsSubmission($request, $id, $callback, $message, $title, $actionUrl);
    }

    if (!$id) {
      return $this->confirmErrorResponse() + [
        '#cache' => [
          'contexts' => ['url'],
        ],
      ];
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $title,
      '#message' => $message,
      '#button_text' => $title,
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
   */
  private function handleSmsSubmission(
    Request $request,
    string $id,
    callable $callback,
    TranslatableMarkup $message,
    TranslatableMarkup $buttonText,
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

      return $this->confirmSuccessResponse();
    }
    catch (HakuvahtiAlreadyConfirmedException) {
      return $this->alreadyConfirmedResponse();
    }
    catch (HakuvahtiException $e) {
      $this->logger?->error('Hakuvahti SMS request failed: ' . $e->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->confirmErrorTitle(),
      '#message' => $message,
      '#button_text' => $buttonText,
      '#action_url' => $actionUrl,
      '#fields' => [
        ['type' => 'hidden', 'name' => 'id', 'value' => $id],
        [
          'type' => 'text',
          'name' => 'code',
          'label' => $this->t('Code', options: ['context' => 'Hakuvahti confirm']),
          'required' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Returns the render array for a flood-limited response.
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
  private function confirmErrorTitle(): TranslatableMarkup {
    return $this->t('Search alert confirmation failed', options: ['context' => 'Hakuvahti confirm failure']);
  }

  /**
   * Returns the render array for a successful confirmation.
   */
  private function confirmSuccessResponse(): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Search alert subscription successful', options: ['context' => 'Hakuvahti confirm success']),
      '#message' => [
        $this->t('You will be notified of new search matches no more than once a day.', options: ['context' => 'Hakuvahti confirm success']),
        $this->t('You can cancel your subscription using the link sent with each notification.', options: ['context' => 'Hakuvahti confirm success']),
        // @todo the backend should return how long the search alert is valid.
        // We have no idea here and it is controlled by the config file.
        $this->t('You can subscribe to new search alerts at any time. The alerts are valid for 12 months.', options: ['context' => 'Hakuvahti confirm success']),
      ],
    ];
  }

  /**
   * Returns the render array for a failed confirmation.
   */
  private function confirmErrorResponse(): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->confirmErrorTitle(),
      '#message' => $this->t('Your search alert could not be confirmed. You can try confirming the search alert again.', options: ['context' => 'Hakuvahti confirm failure']),
    ];
  }

  /**
   * Returns the render array for an already confirmed subscription.
   */
  private function alreadyConfirmedResponse(): array {
    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('You have already confirmed this search alert.', options: ['context' => 'Hakuvahti already confirmed']),
      '#message' => [
        $this->t('You have already confirmed this search alert.', options: ['context' => 'Hakuvahti already confirmed']),
        $this->t('You will receive email alerts about new search results up to once a day.', options: ['context' => 'Hakuvahti already confirmed']),
        $this->t('Each email contains an unsubscribe link that you can use to unsubscribe from saved search alerts. You can save a new search at any time.', options: ['context' => 'Hakuvahti already confirmed']),
      ],
    ];
  }

}
