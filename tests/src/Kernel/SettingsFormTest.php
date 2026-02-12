<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_hakuvahti\Kernel;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\helfi_hakuvahti\Form\SettingsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests for the Hakuvahti settings form.
 *
 * @group helfi_hakuvahti
 */
class SettingsFormTest extends KernelTestBase {

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
   * Tests that submitting the form saves settings to config.
   */
  public function testSubmitSavesConfig(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'hakuvahti_tos_checkbox_label' => 'I agree',
      'hakuvahti_tos_link_text' => 'Privacy policy',
      'hakuvahti_tos_link_url' => 'https://example.com/tos',
      'hakuvahti_instructions_link_url' => 'https://example.com/instructions',
    ]);

    $this->container->get(FormBuilderInterface::class)->submitForm(SettingsForm::class, $form_state);

    $this->assertEmpty($form_state->getErrors());

    $config = $this->config('helfi_hakuvahti.settings');
    $this->assertEquals('I agree', $config->get('hakuvahti_tos_checkbox_label'));
    $this->assertEquals('Privacy policy', $config->get('hakuvahti_tos_link_text'));
    $this->assertEquals('https://example.com/tos', $config->get('hakuvahti_tos_link_url'));
    $this->assertEquals('https://example.com/instructions', $config->get('hakuvahti_instructions_link_url'));
  }

}
