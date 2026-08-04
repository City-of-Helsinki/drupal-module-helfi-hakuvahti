<?php

declare(strict_types=1);

namespace Drupal\helfi_hakuvahti\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Hakuvahti configuration entity.
 *
 * @fixme I don't really see a good point in having separate
 * config entity and helfi_hakuvahti.settings. This config entity
 * just complicates other features of this module.
 *
 * @fixme Some sites have extra hakuvahti_config entities that are just empty.
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
    'sms_enabled',
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
    'confirm_page_title',
    'renew_page_title',
    'unsubscribe_page_title',
    'confirm_sms_title',
    'confirm_sms_message',
    'confirm_sms_button',
  ],
)]
class HakuvahtiConfig extends ConfigEntityBase {

  /**
   * The configuration ID.
   *
   * NOTE: ID is a Drupal identifier only and does not match site_id.
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

  /**
   * Whether SMS sending is enabled for the site in hakuvahti.
   *
   * Hakuvahti has no endpoint for reading its own site configuration, so this
   * mirrors the backend setting and has to be kept in sync by hand.
   */
  protected bool $sms_enabled = FALSE;

  /**
   * Confirm success title.
   */
  protected string $confirm_success_title = '';

  /**
   * Confirm success body.
   */
  protected string $confirm_success_body = '';

  /**
   * Confirm failure title.
   */
  protected string $confirm_failure_title = '';

  /**
   * Confirm failure body.
   */
  protected string $confirm_failure_body = '';

  /**
   * Already confirmed title.
   */
  protected string $already_confirmed_title = '';

  /**
   * Already confirmed body.
   */
  protected string $already_confirmed_body = '';

  /**
   * Renew success title.
   */
  protected string $renew_success_title = '';

  /**
   * Renew success body.
   */
  protected string $renew_success_body = '';

  /**
   * Renew failure title.
   */
  protected string $renew_failure_title = '';

  /**
   * Renew failure body.
   */
  protected string $renew_failure_body = '';

  /**
   * Unsubscribe success title.
   */
  protected string $unsubscribe_success_title = '';

  /**
   * Unsubscribe success body.
   */
  protected string $unsubscribe_success_body = '';

  /**
   * Unsubscribe not found title.
   */
  protected string $unsubscribe_not_found_title = '';

  /**
   * Unsubscribe not found body.
   */
  protected string $unsubscribe_not_found_body = '';

  /**
   * Unsubscribe failure title.
   */
  protected string $unsubscribe_failure_title = '';

  /**
   * Unsubscribe failure body.
   */
  protected string $unsubscribe_failure_body = '';

  /**
   * Confirm processing title.
   */
  protected string $confirm_processing_title = '';

  /**
   * Confirm processing message.
   */
  protected string $confirm_processing_message = '';

  /**
   * Renew processing title.
   */
  protected string $renew_processing_title = '';

  /**
   * Renew processing message.
   */
  protected string $renew_processing_message = '';

  /**
   * Unsubscribe processing title.
   */
  protected string $unsubscribe_processing_title = '';

  /**
   * Unsubscribe processing message.
   */
  protected string $unsubscribe_processing_message = '';

  /**
   * Confirm page title.
   */
  protected string $confirm_page_title = '';

  /**
   * Renew page title.
   */
  protected string $renew_page_title = '';

  /**
   * Unsubscribe page title.
   */
  protected string $unsubscribe_page_title = '';

  /**
   * Confirm SMS title.
   */
  protected string $confirm_sms_title = '';

  /**
   * Confirm SMS message.
   */
  protected string $confirm_sms_message = '';

  /**
   * Confirm SMS button label.
   */
  protected string $confirm_sms_button = '';

  /**
   * Gets the site ID.
   */
  public function getSiteId(): string {
    return $this->site_id ?? '';
  }

  /**
   * Sets the site ID.
   */
  public function setSiteId(string $site_id): static {
    $this->site_id = $site_id;
    return $this;
  }

  /**
   * Whether SMS sending is enabled for the site.
   */
  public function isSmsEnabled(): bool {
    return $this->sms_enabled;
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
