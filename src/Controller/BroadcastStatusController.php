<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\helfi_hakuvahti\BroadcastStatus;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for showing the status of a broadcast.
 */
final class BroadcastStatusController extends ControllerBase {

  use StringTranslationTrait;

  public function __construct(
    protected readonly HakuvahtiInterface $hakuvahti,
    protected readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'logger.channel.helfi_hakuvahti')]
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Shows the status of a broadcast.
   *
   * @phpstan-return array<string, mixed>
   */
  public function view(string $broadcast_id): array {
    try {
      $status = $this->hakuvahti->getBroadcastStatus($broadcast_id);
    }
    catch (HakuvahtiException $exception) {
      // Broadcast records are removed after few days.
      if ($exception->getCode() === 404) {
        throw new NotFoundHttpException();
      }

      $this->logger->error('Hakuvahti broadcast status request failed: ' . $exception->getMessage());

      return [
        'error' => [
          '#type' => 'item',
          '#markup' => $this->t('The status of this broadcast could not be loaded. This does not mean that the broadcast failed.', options: ['context' => 'Hakuvahti broadcast']),
        ],
        'back' => $this->backLink()->toRenderable(),
        '#cache' => ['max-age' => 0],
      ];
    }

    $build = [
      'summary' => [
        '#type' => 'container',
        'id' => [
          '#type' => 'item',
          '#title' => $this->t('Broadcast ID', options: ['context' => 'Hakuvahti broadcast']),
          '#plain_text' => $status->id,
        ],
        'site_id' => [
          '#type' => 'item',
          '#title' => $this->t('Site', options: ['context' => 'Hakuvahti broadcast']),
          '#plain_text' => $status->siteId,
        ],
        'status' => [
          '#type' => 'item',
          '#title' => $this->t('Status', options: ['context' => 'Hakuvahti broadcast']),
          '#plain_text' => (string) match ($status->status) {
            BroadcastStatus::STATUS_PROCESSING => $this->t('Processing', options: ['context' => 'Hakuvahti broadcast']),
            BroadcastStatus::STATUS_COMPLETED => $this->t('Completed', options: ['context' => 'Hakuvahti broadcast']),
            BroadcastStatus::STATUS_FAILED => $this->t('Failed', options: ['context' => 'Hakuvahti broadcast']),
            default => $status->status,
          },
        ],
        'created' => [
          '#type' => 'item',
          '#title' => $this->t('Created', options: ['context' => 'Hakuvahti broadcast']),
          '#plain_text' => $this->dateFormatter->format($status->created->getTimestamp(), 'short'),
        ],
      ],
    ];

    if ($status->test) {
      $build['test'] = [
        '#type' => 'item',
        '#markup' => $this->t('This was a test broadcast that was only sent to the given subscription IDs.', options: ['context' => 'Hakuvahti broadcast']),
      ];
    }

    $build['back'] = $this->backLink()->toRenderable();

    if ($status->stats === NULL) {
      $build['processing'] = [
        '#type' => 'item',
        '#markup' => $this->t('The broadcast is still being processed.', options: ['context' => 'Hakuvahti broadcast']),
      ];
      $build['refresh'] = Link::createFromRoute(
        $this->t('Refresh', options: ['context' => 'Hakuvahti broadcast']),
        'helfi_hakuvahti.broadcast_status',
        ['broadcast_id' => $status->id],
      )->toRenderable();
    }
    else {
      $build['stats'] = [
        '#type' => 'details',
        '#title' => $this->t('Statistics', options: ['context' => 'Hakuvahti broadcast']),
      ];
      $build['stats']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Metric', options: ['context' => 'Hakuvahti broadcast']),
          $this->t('Count', options: ['context' => 'Hakuvahti broadcast']),
        ],
        '#rows' => [
          [
            $this->t('Subscriptions checked', options: ['context' => 'Hakuvahti broadcast']),
            $status->stats->subscriptionsChecked,
          ],
          [
            $this->t('Emails added to the sending queue', options: ['context' => 'Hakuvahti broadcast']),
            $status->stats->emailsQueued,
          ],
          [
            $this->t('SMS messages added to the sending queue', options: ['context' => 'Hakuvahti broadcast']),
            $status->stats->smsQueued,
          ],
          [
            $this->t('Subscriptions without contact details', options: ['context' => 'Hakuvahti broadcast']),
            $status->stats->missingContacts,
          ],
        ],
      ];
    }

    // The status changes in the background, so the page must not be cached.
    $build['#cache'] = ['max-age' => 0];

    return $build;
  }

  /**
   * Gets a link back to the broadcast form.
   */
  private function backLink(): Link {
    return Link::createFromRoute(
      $this->t('Send another broadcast message', options: ['context' => 'Hakuvahti broadcast']),
      'helfi_hakuvahti.broadcast',
    );
  }

}
