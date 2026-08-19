<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\HakuvahtiException;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form for reading hakuvahti subscription statistics.
 */
final class StatsForm extends FormBase {

  private const string BUTTON_SHOW = 'show';
  private const string BUTTON_DOWNLOAD = 'download';

  public function __construct(
    private readonly HakuvahtiInterface $hakuvahti,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'logger.channel.helfi_hakuvahti')]
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'helfi_hakuvahti_stats_form';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;

    // Without a base url every request throws before it is sent, and the
    // exception carries no status code to turn into a useful message.
    if (!$this->config('helfi_hakuvahti.settings')->get('base_url')) {
      $form['not_configured'] = [
        '#type' => 'item',
        '#markup' => $this->t('Hakuvahti is not configured on this site, so there are no statistics to read.', options: ['context' => 'Hakuvahti statistics']),
      ];
      return $form;
    }

    $sites = $this->sites();

    if (!$sites) {
      $form['no_sites'] = [
        '#type' => 'item',
        '#markup' => $this->t('No Hakuvahti configuration has a site ID, so there are no statistics to read.', options: ['context' => 'Hakuvahti statistics']),
      ];
      return $form;
    }

    $query = $this->getRequest()->query;

    if (count($sites) === 1) {
      $siteId = (string) array_key_first($sites);

      $form['site_id'] = [
        '#type' => 'value',
        '#value' => $siteId,
      ];
      $form['site'] = [
        '#type' => 'item',
        '#title' => $this->t('Hakuvahti', options: ['context' => 'Hakuvahti statistics']),
        '#plain_text' => array_first($sites)->label(),
      ];
    }
    else {
      $requested = (string) $query->get('site_id');
      $siteId = isset($sites[$requested]) ? $requested : '';

      $form['site_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Site', options: ['context' => 'Hakuvahti statistics']),
        '#options' => array_map(static fn (HakuvahtiConfig $config) => $config->label(), $sites),
        '#default_value' => $siteId,
        '#required' => TRUE,
        '#empty_value' => '',
        '#empty_option' => $this->t('- Select a site -', options: ['context' => 'Hakuvahti statistics']),
      ];
    }

    $interval = $query->get('interval') === 'day' ? 'day' : 'month';
    $from = $this->date($query->get('from'));
    $to = $this->date($query->get('to'));

    $form['interval'] = [
      '#type' => 'select',
      '#title' => $this->t('One row per', options: ['context' => 'Hakuvahti statistics']),
      '#options' => [
        'month' => $this->t('Month', options: ['context' => 'Hakuvahti statistics']),
        'day' => $this->t('Day', options: ['context' => 'Hakuvahti statistics']),
      ],
      '#default_value' => $interval,
    ];

    // Hakuvahti records days in Europe/Helsinki, so that month boundaries match
    // the ones a product owner reads. These dates are passed through as written
    // and must not be converted to another zone.
    $form['from'] = [
      '#type' => 'date',
      '#date_timezone' => 'Europe/Helsinki',
      '#title' => $this->t('Start date', options: ['context' => 'Hakuvahti statistics']),
      '#default_value' => $from,
      '#description' => $this->t('Leave both dates empty for the default range: this month and the 12 before it, or the last 31 days.', options: ['context' => 'Hakuvahti statistics']),
    ];

    $form['to'] = [
      '#type' => 'date',
      '#date_timezone' => 'Europe/Helsinki',
      '#title' => $this->t('End date', options: ['context' => 'Hakuvahti statistics']),
      '#default_value' => $to,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      // The actions element defaults to a weight of 100, which would leave the
      // buttons below the table and a long scroll away from the dates.
      '#weight' => 5,
    ];

    $form['actions'][self::BUTTON_SHOW] = [
      '#type' => 'submit',
      '#name' => self::BUTTON_SHOW,
      '#value' => $this->t('Show', options: ['context' => 'Hakuvahti statistics']),
      '#button_type' => 'primary',
    ];

    $form['actions'][self::BUTTON_DOWNLOAD] = [
      '#type' => 'submit',
      '#name' => self::BUTTON_DOWNLOAD,
      '#value' => $this->t('Download CSV', options: ['context' => 'Hakuvahti statistics']),
      '#weight' => 10,
    ];

    // Drupal rebuilds the form while processing a submission, which ends in
    // either a redirect or the csv, so reading here would ask hakuvahti the
    // same question twice per click.
    if (!$siteId || !$this->getRequest()->isMethod('GET')) {
      return $form;
    }

    if (!$report = $this->read($siteId, $interval, $from, $to)) {
      return $form;
    }

    $form['report'] = $this->report($report);

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['from', 'to'] as $field) {
      $value = (string) $form_state->getValue($field);

      if ($value !== '' && !$this->date($value)) {
        $form_state->setErrorByName($field, $this->t('Enter the date as YYYY-MM-DD.', options: ['context' => 'Hakuvahti statistics']));
      }
    }

    $from = $this->date($form_state->getValue('from'));
    $to = $this->date($form_state->getValue('to'));

    // Both are plain YYYY-MM-DD, so they compare as strings. Checking here
    // saves a request hakuvahti would only answer with a 400.
    if ($from && $to && $to < $from) {
      $form_state->setErrorByName('to', $this->t('End date cannot be before start date.', options: ['context' => 'Hakuvahti statistics']));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $siteId = (string) $form_state->getValue('site_id');
    $interval = (string) $form_state->getValue('interval');
    $from = $this->date($form_state->getValue('from'));
    $to = $this->date($form_state->getValue('to'));

    if (($form_state->getTriggeringElement()['#name'] ?? NULL) !== self::BUTTON_DOWNLOAD) {
      // Rendering from the url keeps the page bookmarkable and refreshable.
      $form_state->setRedirect('helfi_hakuvahti.statistics', [], [
        'query' => array_filter([
          'site_id' => $siteId,
          'interval' => $interval,
          'from' => $from,
          'to' => $to,
        ]),
      ]);
      return;
    }

    if (!$report = $this->read($siteId, $interval, $from, $to)) {
      return;
    }

    // The range hakuvahti used, which is not necessarily the one asked for.
    $range = $report['range'] ?? [];
    $filename = sprintf(
      'hakuvahti-stats-%s-%s_%s.csv',
      $siteId,
      $range['from'] ?? 'start',
      $range['to'] ?? 'end',
    );

    $response = new Response($this->csv($report));
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

    $form_state->setResponse($response);
  }

  /**
   * Reads the figures, turning a failure into a message.
   *
   * @return array<string, mixed>|null
   *   The report, or NULL if it could not be read.
   */
  private function read(string $siteId, string $interval, ?string $from, ?string $to): ?array {
    try {
      return $this->hakuvahti->stats($siteId, $interval, $from, $to);
    }
    catch (HakuvahtiException $exception) {
      $this->logger->warning(sprintf(
        'Reading hakuvahti statistics for site %s failed (status %s): %s',
        $siteId,
        $exception->getCode(),
        $exception->getMessage(),
      ));
      $this->messenger()->addError($this->error($exception));

      return NULL;
    }
  }

  /**
   * The message for a failed read.
   */
  private function error(HakuvahtiException $exception): TranslatableMarkup {
    return match ($exception->getCode()) {
      400 => $this->t('Hakuvahti did not accept the request. Check the site and the dates.', options: ['context' => 'Hakuvahti statistics']),
      403 => $this->t("Hakuvahti did not accept this site's API key.", options: ['context' => 'Hakuvahti statistics']),
      // Statistics arrived later than the rest of the integration, so a site
      // running an older hakuvahti has the endpoint missing rather than broken.
      404 => $this->t("This site's Hakuvahti does not report statistics yet.", options: ['context' => 'Hakuvahti statistics']),
      default => $this->t('Reading the statistics failed.', options: ['context' => 'Hakuvahti statistics']),
    };
  }

  /**
   * Hakuvahti configurations that have a site id, keyed by it.
   *
   * @return array<string, \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig>
   *   The configurations.
   */
  private function sites(): array {
    /** @var \Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig[] $configs */
    $configs = $this->entityTypeManager->getStorage('hakuvahti_config')->loadMultiple();

    $sites = array_reduce(
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
    ksort($sites);

    return $sites;
  }

  /**
   * Accepts a date only if it is one the calendar has.
   *
   * @return string|null
   *   The date as YYYY-MM-DD, or NULL for anything else.
   */
  private function date(mixed $value): ?string {
    $value = is_string($value) ? trim($value) : '';

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
      return NULL;
    }

    // The shape alone is not enough: 2026-02-31 matches it.
    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : NULL;
  }

  /**
   * The report as a summary and a table.
   *
   * @param array<string, mixed> $report
   *   The decoded response.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function report(array $report): array {
    $range = $report['range'] ?? [];

    $items = [
      $this->t('Active subscriptions: @count', [
        '@count' => $report['current']['active'] ?? 0,
      ], ['context' => 'Hakuvahti statistics']),
      $this->t('Waiting for confirmation: @count', [
        '@count' => $report['current']['unconfirmed'] ?? 0,
      ], ['context' => 'Hakuvahti statistics']),
    ];

    $arguments = [
      '@from' => $range['from'] ?? '',
      '@to' => $range['to'] ?? '',
    ];
    // Hakuvahti snaps the requested range out to whole periods and caps its
    // length, so this is not necessarily what was asked for.
    $items[] = ($range['interval'] ?? 'month') === 'day'
      ? $this->t('Showing @from to @to, one row per day.', $arguments, ['context' => 'Hakuvahti statistics'])
      : $this->t('Showing @from to @to, one row per month.', $arguments, ['context' => 'Hakuvahti statistics']);

    if (!($report['collecting_since'] ?? NULL)) {
      $items[] = $this->t('Nothing has been recorded for this site yet.', options: ['context' => 'Hakuvahti statistics']);
    }

    // "Active subscriptions" is counted when the request is made, so it only
    // matches the last measured "Active at end" just after a cron run.
    if ($generated = strtotime((string) ($report['generated_at'] ?? ''))) {
      $items[] = $this->t('Read at @time.', [
        // 'long' rather than 'short', which carries no time of day.
        '@time' => $this->dateFormatter->format($generated, 'long'),
      ], ['context' => 'Hakuvahti statistics']);
    }

    $columns = $this->columns($report);

    return [
      '#type' => 'container',
      '#weight' => 10,
      '#attributes' => ['class' => ['hakuvahti-stats']],
      '#attached' => ['library' => ['helfi_hakuvahti/stats']],
      'summary' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
      'table' => [
        '#theme' => 'table',
        '#header' => array_values($columns),
        '#rows' => array_map(
          static fn (array $row) => array_values(array_map(
            static fn (int|string|null $value) => (string) ($value ?? ''),
            $row,
          )),
          $this->rows($report),
        ),
        '#empty' => $this->t('No periods in range.', options: ['context' => 'Hakuvahti statistics']),
      ],
    ];
  }

  /**
   * The columns, in order, mapped to their labels.
   *
   * The one source of truth for both the table and the csv.
   *
   * @param array<string, mixed> $report
   *   The decoded response.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by period field.
   */
  private function columns(array $report): array {
    $columns = [
      'period' => $this->t('Period', options: ['context' => 'Hakuvahti statistics']),
      'created' => $this->t('Created', options: ['context' => 'Hakuvahti statistics']),
      'confirmed' => $this->t('Confirmed', options: ['context' => 'Hakuvahti statistics']),
      'cancelled' => $this->t('Cancelled', options: ['context' => 'Hakuvahti statistics']),
      'cancelled_unconfirmed' => $this->t('Cancelled before confirming', options: ['context' => 'Hakuvahti statistics']),
      'expired' => $this->t('Expired', options: ['context' => 'Hakuvahti statistics']),
      'expired_unconfirmed' => $this->t('Expired before confirming', options: ['context' => 'Hakuvahti statistics']),
      'net_change' => $this->t('Net change', options: ['context' => 'Hakuvahti statistics']),
      'active_end' => $this->t('Active at end', options: ['context' => 'Hakuvahti statistics']),
    ];

    // Read off the response rather than hardcoded, so a language hakuvahti
    // adds shows up without a release here.
    foreach ($this->languages($report) as $langcode) {
      $columns["confirmed_$langcode"] = $this->t('Confirmed (@language)', [
        '@language' => strtoupper($langcode),
      ], ['context' => 'Hakuvahti statistics']);
    }

    return $columns;
  }

  /**
   * Subscription languages the response reports.
   *
   * @param array<string, mixed> $report
   *   The decoded response.
   *
   * @return array<int, string>
   *   Langcodes.
   */
  private function languages(array $report): array {
    $languages = array_map(strval(...), array_keys($report['periods'][0]['confirmed_by_lang'] ?? []));

    return $languages ?: ['fi', 'sv', 'en'];
  }

  /**
   * The periods as flat rows, keyed by column.
   *
   * @param array<string, mixed> $report
   *   The decoded response.
   *
   * @return array<int, array<string, int|string|null>>
   *   One row per period.
   */
  private function rows(array $report): array {
    $keys = array_keys($this->columns($report));
    $rows = [];

    foreach ($report['periods'] ?? [] as $period) {
      $row = [];

      foreach ($keys as $key) {
        $row[$key] = $this->value($period, $key);
      }

      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * One period field.
   *
   * @param array<string, mixed> $period
   *   One period of the response.
   * @param string $key
   *   The column to read.
   *
   * @return int|string|null
   *   The value. NULL only for the two figures that are genuinely nullable.
   */
  private function value(array $period, string $key): int|string|null {
    if (str_starts_with($key, 'confirmed_')) {
      return $period['confirmed_by_lang'][substr($key, strlen('confirmed_'))] ?? 0;
    }

    return match ($key) {
      'period' => (string) ($period['period'] ?? ''),
      // Null is not zero here: it means the period holds no stored data at all,
      // where a zero means it holds data and nothing happened.
      'net_change', 'active_end' => $period[$key] ?? NULL,
      default => $period[$key] ?? 0,
    };
  }

  /**
   * Renders the report as csv.
   *
   * The byte order mark is what makes Excel read the file as utf-8.
   *
   * @param array<string, mixed> $report
   *   The decoded response.
   */
  private function csv(array $report): string {
    $columns = $this->columns($report);

    if (!$handle = fopen('php://temp', 'w+')) {
      throw new \RuntimeException('Could not open a temporary stream for the csv.');
    }

    try {
      $this->csvRow($handle, array_map(strval(...), $columns));

      foreach ($this->rows($report) as $row) {
        $this->csvRow($handle, array_map(static fn ($value) => $value ?? '', $row));
      }

      rewind($handle);
      $csv = (string) stream_get_contents($handle);
    }
    finally {
      fclose($handle);
    }

    return "\u{FEFF}$csv";
  }

  /**
   * Writes one csv row.
   *
   * @param resource $handle
   *   The stream to write to.
   * @param array<string, int|string> $fields
   *   The fields of one row.
   */
  private function csvRow($handle, array $fields): void {
    fputcsv($handle, $fields, ';', escape: '', eol: "\r\n");
  }

}
