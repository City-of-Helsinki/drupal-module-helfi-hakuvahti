# drupal-module-helfi-hakuvahti
A module which handles the communication between Drupal and helfi-hakuvahti service

## Local development

Add to your `local.settings.php`:

```php
$config['helfi_hakuvahti.settings']['base_url'] = 'http://hakuvahti:3000';
$config['helfi_hakuvahti.settings']['api_key'] = '123';
```

These are default credentials for [locally running hakuvahti](https://github.com/City-of-Helsinki/helfi-hakuvahti/blob/main/.env.dist#L28).
