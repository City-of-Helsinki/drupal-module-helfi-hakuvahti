<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\Entity\HakuvahtiConfig;
use Drupal\helfi_hakuvahti\Form\StatsForm;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests for the statistics form.
 */
#[Group('helfi_hakuvahti')]
#[RunTestsInSeparateProcesses]
class StatsFormTest extends KernelTestBase {

  use ApiTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'helfi_hakuvahti',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'helfi_hakuvahti']);

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->set('api_key', '123')
      ->save();

    // The shipped default configuration has no site id.
    HakuvahtiConfig::create([
      'id' => 'jobs',
      'label' => 'Jobs',
      'site_id' => 'rekry',
    ])->save();

    $this->setUpCurrentUser(permissions: ['view hakuvahti statistics']);

    $logger = $this->prophesize(LoggerInterface::class);
    $this->container->set('logger.channel.helfi_hakuvahti', $logger->reveal());
  }

  /**
   * Tests that the page explains itself when hakuvahti is not configured.
   *
   * Without a base url the request throws before it is sent, and carries no
   * status code the error message could be built from.
   */
  public function testWithoutBaseUrl(): void {
    $this->config('helfi_hakuvahti.settings')->set('base_url', '')->save();

    $form = $this->build();

    $this->assertArrayHasKey('not_configured', $form);
    $this->assertArrayNotHasKey('actions', $form);
    $this->assertArrayNotHasKey('report', $form);
  }

  /**
   * Tests that the page explains itself when no configuration has a site id.
   */
  public function testWithoutSiteId(): void {
    HakuvahtiConfig::load('jobs')->delete();

    $form = $this->build();

    $this->assertArrayHasKey('no_sites', $form);
    $this->assertArrayNotHasKey('actions', $form);
    $this->assertArrayNotHasKey('report', $form);
  }

  /**
   * Tests that a single site is read without asking for it first.
   */
  public function testRendersReport(): void {
    $this->mockResponse();

    $form = $this->build();

    $this->assertArrayHasKey('report', $form);
    $this->assertCount(12, $form['report']['table']['#header']);

    $rows = array_map(
      static fn (array $row) => array_map(strval(...), $row),
      $form['report']['table']['#rows'],
    );

    $this->assertSame(
      ['2026-07', '25', '22', '3', '1', '0', '0', '19', '172', '19', '2', '1'],
      $rows[0],
    );
    // A period with no stored data reports no net change and no measurement,
    // which is not the same answer as a zero.
    $this->assertSame(
      ['2026-08', '0', '0', '0', '0', '0', '0', '', '', '0', '0', '0'],
      $rows[1],
    );
  }

  /**
   * Tests that the figures are read for the site the query string names.
   */
  public function testReadsRequestedRange(): void {
    $history = [];
    $this->mockResponse($history);

    $request = Request::create('/admin/tools/hakuvahti/statistics', 'GET', [
      'interval' => 'day',
      'from' => '2026-07-01',
      'to' => '2026-08-31',
    ]);
    // The form builder reads the session off the request.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $this->build();

    $this->assertSame(
      'interval=day&from=2026-07-01&to=2026-08-31',
      $history[0]['request']->getUri()->getQuery(),
    );
  }

  /**
   * Tests that a range running backwards is caught before it is sent.
   */
  public function testValidatesDateOrder(): void {
    $formState = $this->submit([
      'site_id' => 'rekry',
      'interval' => 'month',
      'from' => '2026-08-31',
      'to' => '2026-07-01',
    ], 'show');

    $this->assertArrayHasKey('to', $formState->getErrors());
  }

  /**
   * Tests that a date the calendar does not have is caught.
   */
  public function testValidatesImpossibleDate(): void {
    $formState = $this->submit([
      'site_id' => 'rekry',
      'interval' => 'month',
      'from' => '2026-02-31',
      'to' => '',
    ], 'show');

    $this->assertArrayHasKey('from', $formState->getErrors());
  }

  /**
   * Tests that showing the figures puts them in the url.
   */
  public function testShowRedirectsToQuery(): void {
    $formState = $this->submit([
      'site_id' => 'rekry',
      'interval' => 'day',
      'from' => '2026-07-01',
      'to' => '',
    ], 'show');

    $this->assertEmpty($formState->getErrors());

    $redirect = $formState->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('helfi_hakuvahti.statistics', $redirect->getRouteName());
    // An empty date is left out rather than sent on as an empty parameter.
    $this->assertSame([
      'site_id' => 'rekry',
      'interval' => 'day',
      'from' => '2026-07-01',
    ], $redirect->getOption('query'));
  }

  /**
   * Tests the downloaded csv.
   */
  public function testCsvDownload(): void {
    $this->mockResponse();

    $formState = $this->submit([
      'site_id' => 'rekry',
      'interval' => 'month',
      'from' => '',
      'to' => '',
    ], 'download');

    $response = $formState->getResponse();
    $this->assertNotNull($response);
    $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
    // Named after the range hakuvahti used, not the one that was asked for.
    $this->assertSame(
      'attachment; filename="hakuvahti-stats-rekry-2026-07-01_2026-08-31.csv"',
      $response->headers->get('Content-Disposition'),
    );

    $csv = (string) $response->getContent();

    $this->assertStringStartsWith("\u{FEFF}", $csv);
    $this->assertStringContainsString("\r\n", $csv);

    $rows = explode("\r\n", $csv);
    $this->assertStringStartsWith("\u{FEFF}Period;Created;Confirmed;", $rows[0]);
    $this->assertSame('2026-07;25;22;3;1;0;0;19;172;19;2;1', $rows[1]);
    // The two nullable figures are an empty field, never a zero.
    $this->assertSame('2026-08;0;0;0;0;0;0;;;0;0;0', $rows[2]);
  }

  /**
   * Tests that a failed read is reported rather than rendered as zeroes.
   */
  public function testFailedRead(): void {
    $this->container->set('http_client', $this->setupMockHttpClient([
      new Response(404),
    ]));

    $form = $this->build();

    $this->assertArrayNotHasKey('report', $form);

    $errors = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_ERROR);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('does not report statistics yet', (string) reset($errors));
  }

  /**
   * Tests that the page is behind its own permission.
   */
  public function testAccess(): void {
    $this->mockResponse();

    $this->setUpCurrentUser();
    $this->assertSame(403, $this->requestStatusCode());

    // Administering the module is not the same as reading its figures.
    $this->setUpCurrentUser(permissions: ['administer site configuration']);
    $this->assertSame(403, $this->requestStatusCode());

    $this->setUpCurrentUser(permissions: ['view hakuvahti statistics']);
    $this->assertSame(200, $this->requestStatusCode());
  }

  /**
   * Builds the form.
   *
   * @return array<string, mixed>
   *   The built form.
   */
  private function build(): array {
    return $this->container->get(FormBuilderInterface::class)->getForm(StatsForm::class);
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

    // Driven straight at the form rather than through the form builder, whose
    // programmatic mode reports no redirect at all.
    $form = [];
    $stats = $this->container->get('class_resolver')->getInstanceFromDefinition(StatsForm::class);
    $stats->validateForm($form, $formState);

    if (!$formState->getErrors()) {
      $stats->submitForm($form, $formState);
    }

    return $formState;
  }

  /**
   * Requests the statistics page and returns the status code.
   */
  private function requestStatusCode(): int {
    $url = Url::fromRoute('helfi_hakuvahti.statistics');

    return $this->processRequest($this->getMockedRequest($url->toString()))->getStatusCode();
  }

  /**
   * Answers the next request with a report.
   *
   * @param array<mixed> $history
   *   Collects the requests that were made.
   */
  private function mockResponse(array &$history = []): void {
    $client = $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(200, body: json_encode($this->report(), flags: JSON_THROW_ON_ERROR)),
    ]);

    $this->container->set('http_client', $client);
    $this->container->set(ClientInterface::class, $client);
  }

  /**
   * A response in the shape hakuvahti answers with.
   *
   * @return array<string, mixed>
   *   The report.
   */
  private function report(): array {
    return [
      'site_id' => 'rekry',
      'generated_at' => '2026-08-17T09:12:33.000Z',
      'collecting_since' => '2026-03-10',
      'range' => ['from' => '2026-07-01', 'to' => '2026-08-31', 'interval' => 'month'],
      'current' => ['active' => 178, 'unconfirmed' => 4],
      'periods' => [
        [
          'period' => '2026-07',
          'created' => 25,
          'confirmed' => 22,
          'cancelled' => 3,
          'cancelled_unconfirmed' => 1,
          'expired' => 0,
          'expired_unconfirmed' => 0,
          'confirmed_by_lang' => ['fi' => 19, 'sv' => 2, 'en' => 1],
          'net_change' => 19,
          'active_end' => 172,
          'incomplete' => FALSE,
        ],
        [
          'period' => '2026-08',
          'created' => 0,
          'confirmed' => 0,
          'cancelled' => 0,
          'cancelled_unconfirmed' => 0,
          'expired' => 0,
          'expired_unconfirmed' => 0,
          'confirmed_by_lang' => ['fi' => 0, 'sv' => 0, 'en' => 0],
          'net_change' => NULL,
          'active_end' => NULL,
          'incomplete' => TRUE,
        ],
      ],
    ];
  }

}
