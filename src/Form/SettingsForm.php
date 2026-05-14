<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hakuvahti settings form.
 */
class SettingsForm extends ConfigFormBase {

  use AutowireTrait;

  private const string CONFIG_NAME = 'helfi_hakuvahti.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    #[Autowire(service: 'language_manager')] protected ConfigurableLanguageManagerInterface $languageManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'helfi_hakuvahti.settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->getLanguageConfig();

    $form['settings']['hakuvahti_tos_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hakuvahti terms of service URL'),
      '#default_value' => $config->get('hakuvahti_tos_link_url'),
      '#description' => $this->t('URL for the webpage or pdf to the Hakuvahti terms of service.'),
      '#maxlength' => 1024,
    ];

    $form['settings']['hakuvahti_instructions_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('More detailed instructions on how to use saved searches'),
      '#default_value' => $config->get('hakuvahti_instructions_link_url'),
      '#maxlength' => 1024,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $config = $this->getEditableLanguageConfig();

    $config
      ->set('hakuvahti_tos_link_url', $form_state->getValue('hakuvahti_tos_link_url'))
      ->set('hakuvahti_instructions_link_url', $form_state->getValue('hakuvahti_instructions_link_url'))
      ->save();

    Cache::invalidateTags($config->getCacheTags());
  }

  /**
   * Returns config for the current language (read-only).
   */
  private function getLanguageConfig(): ImmutableConfig|Config|LanguageConfigOverride {
    $currentLang = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);

    if ($this->languageManager->getDefaultLanguage()->getId() !== $currentLang->getId()) {
      return $this->languageManager->getLanguageConfigOverride($currentLang->getId(), self::CONFIG_NAME);
    }

    return $this->config(self::CONFIG_NAME);
  }

  /**
   * Returns editable config for the current language.
   */
  private function getEditableLanguageConfig(): Config|LanguageConfigOverride {
    $currentLang = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);

    if ($this->languageManager->getDefaultLanguage()->getId() !== $currentLang->getId()) {
      return $this->languageManager->getLanguageConfigOverride($currentLang->getId(), self::CONFIG_NAME);
    }

    return $this->configFactory->getEditable(self::CONFIG_NAME);
  }

}
