<?php

namespace Drupal\helfi_hakuvahti;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;

/**
 * Get Hakuvahti settings for frontend.
 */
readonly class DrupalSettings {

  /**
   * Settings that are exposed to JavaScript.
   */
  public const array EXPOSED_SETTINGS = [
    'hakuvahti_tos_checkbox_label',
    'hakuvahti_tos_link_text',
    'hakuvahti_tos_link_url',
    'hakuvahti_instructions_link_url',
  ];

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * Get settings as they should be exposed to JavaScript.
   */
  public function applyTo(array &$build): void {
    $langcode = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    // Attempt to get the translated configuration.
    // @todo Why is this needed?
    $language = $this->languageManager->getLanguage($langcode);
    $originalLanguage = $this->languageManager->getConfigOverrideLanguage();
    $this->languageManager->setConfigOverrideLanguage($language);

    try {
      $cache = new CacheableMetadata();

      $settings = $this->configFactory->get('helfi_hakuvahti.settings');

      // Do not expose settings if base_url is not configured.
      if (empty($settings->get('base_url'))) {
        return;
      }

      $drupalSettings = [];
      foreach (self::EXPOSED_SETTINGS as $exposed_setting) {
        $drupalSettings['texts'][$exposed_setting] = $settings->get($exposed_setting) ?: NULL;
      }

      $drupalSettings['apiUrl'] = Url::fromRoute('helfi_hakuvahti.subscribe')->toString();

      $build['#attached']['drupalSettings']['hakuvahti'] = $drupalSettings;

      $cache->addCacheableDependency($settings);
      $cache->addCacheContexts(['languages:language_content']);
      $cache->applyTo($build);
    }
    finally {
      // Set the config back to the original language.
      $this->languageManager->setConfigOverrideLanguage($originalLanguage);
    }
  }

}
