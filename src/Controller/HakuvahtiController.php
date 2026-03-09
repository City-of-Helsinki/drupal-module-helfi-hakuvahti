<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Link;
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
        $this->t('Confirm saved search', options: ['context' => 'Hakuvahti']),
        $this->t('Enter the confirmation code sent to your phone.', options: ['context' => 'Hakuvahti']),
        $this->t('Search saved successfully', options: ['context' => 'Hakuvahti']),
        $this->t('Search saved successfully', options: ['context' => 'Hakuvahti']),
        $this->t('Confirmation of saved search failed', options: ['context' => 'Hakuvahti']),
      );
    }

    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      return $this->handleConfirmFormSubmission($hash, $subscription);
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Enabling saved search', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('Please wait while the saved search is being enabled.', [], ['context' => 'Hakuvahti']),
      '#button_text' => $this->t('Confirm saved search', [], ['context' => 'Hakuvahti']),
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
      // Check subscription status first.
      $status = $this->hakuvahti->getStatus($hash, $subscription);

      // Already confirmed.
      if ($status === 'active') {
        return [
          '#theme' => 'hakuvahti_confirmation',
          '#title' => $this->t('Saved search already confirmed', [], ['context' => 'Hakuvahti']),
          '#message' => [
            $this->t('You have already confirmed this saved search.', [], ['context' => 'Hakuvahti']),
            $this->t('You will receive email alerts about new search results up to once a day.', [], ['context' => 'Hakuvahti']),
            $this->t('Each email contains an unsubscribe link that you can use to unsubscribe from saved search alerts. You can save a new search at any time.', [], ['context' => 'Hakuvahti']),
          ],
        ];
      }

      // Status is 'inactive' - proceed with confirmation.
      if ($status === 'inactive') {
        $this->hakuvahti->confirm($hash, $subscription);

        return [
          '#theme' => 'hakuvahti_confirmation',
          '#title' => $this->t('Search saved successfully', [], ['context' => 'Hakuvahti']),
          '#message' => [
            $this->t('You will receive email alerts about new search results up to once a day.', [], ['context' => 'Hakuvahti']),
            $this->t('Each email contains an unsubscribe link that you can use to unsubscribe from saved search alerts. You can save a new search at any time.', [], ['context' => 'Hakuvahti']),
            $this->t('Each saved search is valid for 6 months.', [], ['context' => 'Hakuvahti']),
          ],
        ];
      }
    }
    catch (HakuvahtiException $exception) {
      $logLevel = match ($exception->getCode()) {
        404 => 'info',
        default => 'error',
      };

      $this->logger?->{$logLevel}('Hakuvahti confirmation request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Confirmation of saved search failed', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('The confirmation of your saved search failed. You can try confirming your saved search again from your email.', [], ['context' => 'Hakuvahti']),
    ];
  }

  /**
   * Handles the renewal of a saved search.
   */
  public function renew(Request $request): array {
    $id = $request->query->get('id');
    $hash = $request->query->get('hash');
    $subscription = $request->query->get('subscription');

    if ($request->isMethod('POST')) {
      return $id
        ? $this->handleSmsRenewSubmission($id)
        : $this->handleRenewFormSubmission($hash, $subscription);
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Renewing saved search', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('Please wait while the saved search is being renewed.', [], ['context' => 'Hakuvahti']),
      '#button_text' => $this->t('Renew saved search', [], ['context' => 'Hakuvahti']),
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
   * Handles the renewal form submission.
   */
  private function handleRenewFormSubmission(string $hash, string $subscription): array {
    try {
      $this->hakuvahti->renew($hash, $subscription);

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Search renewed successfully', [], ['context' => 'Hakuvahti']),
        '#message' => $this->t('Your saved search has been renewed.', [], ['context' => 'Hakuvahti']),
      ];
    }
    catch (HakuvahtiException $exception) {
      // 404 error is returned if:
      // * Submission has been deleted after it expired.
      // * Submission does not exist.
      if ($exception->getCode() === 404) {
        $this->logger?->info('Hakuvahti renewal request failed: ' . $exception->getMessage());
      }
      else {
        $this->logger?->error('Hakuvahti renewal request failed: ' . $exception->getMessage());
      }
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Renewal failed', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('Renewing saved search failed. Please try again.', [], ['context' => 'Hakuvahti']),
    ];
  }

  /**
   * Handles the SMS renewal submission.
   */
  private function handleSmsRenewSubmission(string $id): array {
    try {
      $this->hakuvahti->renewSms($id);

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Search renewed successfully', [], ['context' => 'Hakuvahti']),
        '#message' => $this->t('Your saved search has been renewed.', [], ['context' => 'Hakuvahti']),
      ];
    }
    catch (HakuvahtiException $exception) {
      $this->logger?->error('Hakuvahti SMS renewal request failed: ' . $exception->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_confirmation',
      '#title' => $this->t('Renewal failed', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('Renewing saved search failed. Please try again.', [], ['context' => 'Hakuvahti']),
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
      return $id
        ? $this->handleSmsUnsubscribeSubmission($id)
        : $this->handleUnsubscribeFormSubmission($hash, $subscription);
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $this->t('Deleting saved search', [], ['context' => 'Hakuvahti']),
      '#message' => $this->t('Please wait while the saved search is being deleted. If you have other searches saved on the City website, this link will not delete them.', [], ['context' => 'Hakuvahti']),
      '#button_text' => $this->t('Delete saved search', [], ['context' => 'Hakuvahti']),
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
   * Handles the unsubscribe form submission.
   */
  private function handleUnsubscribeFormSubmission(string $hash, string $subscription): array {
    try {
      $this->hakuvahti->unsubscribe($hash, $subscription);

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Saved search deleted', [], ['context' => 'Hakuvahti']),
        '#message' => [
          $this->t('The saved search was successfully deleted.', [], ['context' => 'Hakuvahti']),
          $this->t('You can save more searches at any time.', [], ['context' => 'Hakuvahti']),
        ],
        '#link' => Link::fromTextAndUrl($this->t('Save a new search for jobs', [], ['context' => 'Hakuvahti']), Url::fromUri('internal:/')),
      ];
    }
    catch (HakuvahtiException $exception) {
      $this->logger?->error('Hakuvahti unsubscribe request failed: ' . $exception->getMessage());

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Failed to delete saved search', [], ['context' => 'Hakuvahti']),
        '#message' => $this->t('Failed to delete saved search. You can try deleting the saved search again from your email.', [], ['context' => 'Hakuvahti']),
      ];
    }
  }

  /**
   * Handles the SMS unsubscribe submission.
   */
  private function handleSmsUnsubscribeSubmission(string $id): array {
    try {
      $this->hakuvahti->deleteSms($id);

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Saved search deleted', [], ['context' => 'Hakuvahti']),
        '#message' => [
          $this->t('The saved search was successfully deleted.', [], ['context' => 'Hakuvahti']),
          $this->t('You can save more searches at any time.', [], ['context' => 'Hakuvahti']),
        ],
        '#link' => Link::fromTextAndUrl($this->t('Save a new search for jobs', [], ['context' => 'Hakuvahti']), Url::fromUri('internal:/')),
      ];
    }
    catch (HakuvahtiException $exception) {
      $this->logger?->error('Hakuvahti SMS unsubscribe request failed: ' . $exception->getMessage());

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Failed to delete saved search', [], ['context' => 'Hakuvahti']),
        '#message' => $this->t('Failed to delete saved search. You can try deleting the saved search again from your email.', [], ['context' => 'Hakuvahti']),
      ];
    }
  }

  /**
   * Handles an SMS subscription request (GET form or POST submission).
   */
  private function handleSmsRequest(
    Request $request,
    string $route,
    callable $callback,
    TranslatableMarkup $title,
    TranslatableMarkup $message,
    TranslatableMarkup $successTitle,
    TranslatableMarkup $successMessage,
    TranslatableMarkup $errorMessage,
  ): array {
    $id = $request->query->get('id', '');
    $actionUrl = Url::fromRoute($route, [], [
      'query' => ['id' => $id],
    ]);

    if ($request->isMethod('POST')) {
      return $this->handleSmsSubmission($request, $id, $callback, $message, $title, $successTitle, $successMessage, $errorMessage, $actionUrl);
    }

    if (!$id) {
      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $errorMessage,
        '#message' => $errorMessage,
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
        ['type' => 'text', 'name' => 'code', 'label' => $this->t('Code'), 'required' => TRUE],
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
    TranslatableMarkup $successTitle,
    TranslatableMarkup $successMessage,
    TranslatableMarkup $errorMessage,
    Url $actionUrl,
  ): array {
    $code = $request->request->get('code', '');

    if (
      !$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW) ||
      !$this->flood->isAllowed(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_THRESHOLD, self::SMS_FLOOD_WINDOW, $id)
    ) {
      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Too many requests'),
        '#message' => $this->t('Too many requests, please try again later.'),
      ];
    }

    $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW);
    $this->flood->register(self::SMS_FLOOD_EVENT, self::SMS_FLOOD_WINDOW, $id);

    try {
      $callback($id, $code);

      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $successTitle,
        '#message' => $successMessage,
      ];
    }
    catch (HakuvahtiAlreadyConfirmedException) {
      return [
        '#theme' => 'hakuvahti_confirmation',
        '#title' => $this->t('Saved search already confirmed', [], ['context' => 'Hakuvahti']),
        '#message' => [
          $this->t('You have already confirmed this saved search.', [], ['context' => 'Hakuvahti']),
          $this->t('You will receive email alerts about new search results up to once a day.', [], ['context' => 'Hakuvahti']),
          $this->t('Each email contains an unsubscribe link that you can use to unsubscribe from saved search alerts. You can save a new search at any time.', [], ['context' => 'Hakuvahti']),
        ],
      ];
    }
    catch (HakuvahtiException $e) {
      $this->logger?->error('Hakuvahti SMS request failed: ' . $e->getMessage());
    }

    return [
      '#theme' => 'hakuvahti_form',
      '#title' => $errorMessage,
      '#message' => $message,
      '#button_text' => $buttonText,
      '#action_url' => $actionUrl,
      '#fields' => [
        ['type' => 'hidden', 'name' => 'id', 'value' => $id],
        ['type' => 'text', 'name' => 'code', 'label' => $this->t('Code'), 'required' => TRUE, 'value' => $code],
      ],
    ];
  }

}
