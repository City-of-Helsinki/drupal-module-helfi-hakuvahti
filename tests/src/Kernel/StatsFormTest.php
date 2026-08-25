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
use Drupal\Tests\helfi_hakuvahti\Traits\StatsResponseTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
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
  use StatsResponseTrait;
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
  }

  /**
   * Tests that a single site is read without asking for it first.
   */
  public function testRendersReport(): void {
    $this->mockResponse();

    $form = $this->container->get(FormBuilderInterface::class)->getForm(StatsForm::class);

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
   * Tests that the figures are read for the range the query string names.
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

    $this->container->get(FormBuilderInterface::class)->getForm(StatsForm::class);

    $this->assertSame(
      'interval=day&from=2026-07-01&to=2026-08-31',
      $history[0]['request']->getUri()->getQuery(),
    );
  }

  /**
   * Tests that a range hakuvahti would reject is caught before it is sent.
   */
  #[TestWith(['2026-08-31', '2026-07-01', 'to'])]
  #[TestWith(['2026-02-31', '', 'from'])]
  public function testRejectsUnusableRange(string $from, string $to, string $field): void {
    $formState = $this->submit([
      'site_id' => 'rekry',
      'interval' => 'month',
      'from' => $from,
      'to' => $to,
    ], 'show');

    $this->assertArrayHasKey($field, $formState->getErrors());
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

    $form = $this->container->get(FormBuilderInterface::class)->getForm(StatsForm::class);

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

    $this->setUpCurrentUser(permissions: ['view hakuvahti statistics']);
    $this->assertSame(200, $this->requestStatusCode());
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
   * Answers the next request with the fixture.
   *
   * @param array<mixed> $history
   *   Collects the requests that were made.
   */
  private function mockResponse(array &$history = []): void {
    $client = $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(200, body: $this->statsResponseBody()),
    ]);

    $this->container->set('http_client', $client);
    $this->container->set(ClientInterface::class, $client);
  }

}
