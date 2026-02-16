<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Hakuvahti settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'helfi_hakuvahti.settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() : array {
    return ['helfi_hakuvahti.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['settings']['hakuvahti_tos_checkbox_label'] = [
      '#type' => 'textfield',
      '#config_target' => 'helfi_hakuvahti.settings:hakuvahti_tos_checkbox_label',
      '#title' => $this->t('Hakuvahti terms of service checkbox label'),
      '#description' => $this->t('Label for the terms of service checkbox.'),
    ];

    $form['settings']['hakuvahti_tos_link_text'] = [
      '#type' => 'textfield',
      '#config_target' => 'helfi_hakuvahti.settings:hakuvahti_tos_link_text',
      '#title' => $this->t('Hakuvahti terms of service link text'),
    ];

    $form['settings']['hakuvahti_tos_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hakuvahti terms of service URL'),
      '#config_target' => 'helfi_hakuvahti.settings:hakuvahti_tos_link_url',
      '#description' => $this->t('URL for the webpage or pdf to the Hakuvahti terms of service.'),
    ];

    $form['settings']['hakuvahti_instructions_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('More detailed instructions on how to use saved searches'),
      '#config_target' => 'helfi_hakuvahti.settings:hakuvahti_instructions_link_url',
    ];

    return parent::buildForm($form, $form_state);
  }

}
