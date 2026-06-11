<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Hakuvahti configuration entity.
 */
#[ConfigEntityType(
  id: 'hakuvahti_config',
  label: new TranslatableMarkup('Hakuvahti Configuration'),
  config_prefix: 'config',
  entity_keys: ['id' => 'id', 'label' => 'label'],
  config_export: [
    'id',
    'label',
    'site_id',
    'confirm_success_title',
    'confirm_success_body',
    'confirm_failure_title',
    'confirm_failure_body',
    'already_confirmed_title',
    'already_confirmed_body',
    'renew_success_title',
    'renew_success_body',
    'renew_failure_title',
    'renew_failure_body',
    'unsubscribe_success_title',
    'unsubscribe_success_body',
    'unsubscribe_not_found_title',
    'unsubscribe_not_found_body',
    'unsubscribe_failure_title',
    'unsubscribe_failure_body',
    'confirm_processing_title',
    'confirm_processing_message',
    'renew_processing_title',
    'renew_processing_message',
    'unsubscribe_processing_title',
    'unsubscribe_processing_message',
  ],
)]
class HakuvahtiConfig extends ConfigEntityBase {

  /**
   * The configuration ID.
   */
  protected string $id;

  /**
   * The configuration label.
   */
  protected string $label;

  /**
   * The site ID sent to the backend server.
   */
  protected string $site_id = '';

  protected string $confirm_success_title = '';
  protected string $confirm_success_body = '';
  protected string $confirm_failure_title = '';
  protected string $confirm_failure_body = '';
  protected string $already_confirmed_title = '';
  protected string $already_confirmed_body = '';
  protected string $renew_success_title = '';
  protected string $renew_success_body = '';
  protected string $renew_failure_title = '';
  protected string $renew_failure_body = '';
  protected string $unsubscribe_success_title = '';
  protected string $unsubscribe_success_body = '';
  protected string $unsubscribe_not_found_title = '';
  protected string $unsubscribe_not_found_body = '';
  protected string $unsubscribe_failure_title = '';
  protected string $unsubscribe_failure_body = '';
  protected string $confirm_processing_title = '';
  protected string $confirm_processing_message = '';
  protected string $renew_processing_title = '';
  protected string $renew_processing_message = '';
  protected string $unsubscribe_processing_title = '';
  protected string $unsubscribe_processing_message = '';

  public function getSiteId(): string {
    return $this->site_id ?? '';
  }

  public function setSiteId(string $site_id): static {
    $this->site_id = $site_id;
    return $this;
  }

  /**
   * Gets a confirmation page text value by key.
   */
  public function getConfirmationText(string $key): string {
    return $this->{$key} ?? '';
  }

  /**
   * Sets a confirmation page text value by key.
   */
  public function setConfirmationText(string $key, string $value): static {
    $this->{$key} = $value;
    return $this;
  }

}
