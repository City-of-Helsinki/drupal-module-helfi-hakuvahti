<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hooks for config_ignore module.
 */
class ConfigIgnoreHook {

  /**
   * Implements hook_config_ignore_settings_alter().
   */
  #[Hook('config_ignore_settings_alter')]
  public static function alter(array &$settings): void {
    // If config ignore module is enabled, these
    // values are managed from the SettingsForm only.
    $settings[] = 'helfi_hakuvahti.settings:hakuvahti_tos_checkbox_label';
    $settings[] = 'helfi_hakuvahti.settings:hakuvahti_tos_link_text';
    $settings[] = 'helfi_hakuvahti.settings:hakuvahti_tos_link_url';
    $settings[] = 'helfi_hakuvahti.settings:hakuvahti_instructions_link_url';
  }

}
