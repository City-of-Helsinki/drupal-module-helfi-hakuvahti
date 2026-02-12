<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\helfi_hakuvahti\DrupalSettings;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests for the DrupalSettings service.
 *
 * @group helfi_hakuvahti
 */
class DrupalSettingsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['helfi_hakuvahti'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['helfi_hakuvahti']);
  }

  /**
   * Tests that applyTo does nothing when base_url is empty.
   */
  public function testApplyToWithoutBaseUrl(): void {
    $build = [];
    $this->container->get(DrupalSettings::class)->applyTo($build);

    $this->assertArrayNotHasKey('#attached', $build);
  }

  /**
   * Tests that applyTo exposes settings when base_url is configured.
   */
  public function testApplyToExposesSettings(): void {
    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://hakuvahti.example.com')
      ->set('hakuvahti_tos_checkbox_label', 'I agree')
      ->set('hakuvahti_tos_link_text', 'Privacy policy')
      ->set('hakuvahti_tos_link_url', 'https://example.com/tos')
      ->set('hakuvahti_instructions_link_url', 'https://example.com/instructions')
      ->save();

    $build = [];
    $this->container->get(DrupalSettings::class)->applyTo($build);

    $settings = $build['#attached']['drupalSettings']['hakuvahti'];
    $this->assertEquals('I agree', $settings['hakuvahti_tos_checkbox_label']);
    $this->assertEquals('Privacy policy', $settings['hakuvahti_tos_link_text']);
    $this->assertEquals('https://example.com/tos', $settings['hakuvahti_tos_link_url']);
    $this->assertEquals('https://example.com/instructions', $settings['hakuvahti_instructions_link_url']);
  }

  /**
   * Tests that empty settings are exposed as NULL.
   */
  public function testApplyToExposesEmptySettingsAsNull(): void {
    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://hakuvahti.example.com')
      ->set('hakuvahti_tos_link_url', '')
      ->set('hakuvahti_instructions_link_url', '')
      ->save();

    $build = [];
    $this->container->get(DrupalSettings::class)->applyTo($build);

    $settings = $build['#attached']['drupalSettings']['hakuvahti'];
    $this->assertNull($settings['hakuvahti_tos_link_url']);
    $this->assertNull($settings['hakuvahti_instructions_link_url']);
  }

}
