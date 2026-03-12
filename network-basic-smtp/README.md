# Network Basic SMTP

**Version:** 0.1  
**Author:** 637 Digital Solutions  
**Website:** [https://637digital.com](https://637digital.com)  
**License:** MIT

A minimal WordPress Multisite SMTP plugin with:

- Network Admin only settings UI
- SMTP credentials stored encrypted at rest
- Test email to the logged-in Network Admin user
- Optional default footer appended to outgoing mail
- Lightweight JS for enabling/disabling auth and footer fields

## Install

1. Copy this folder into `wp-content/plugins/network-basic-smtp/`
2. Generate a secure encryption key. On macOS, open Terminal and run:

```bash
openssl rand -base64 32
```

This will output a random 32-character key. Copy it.

3. Add this constant to `wp-config.php`, replacing the value with your generated key:

```php
define('NBSMTP_ENCRYPTION_KEY', 'your-generated-key-here');
```

4. Network activate the plugin.
5. In Network Admin, click **Network SMTP** in the main admin menu (envelope icon)

## Notes

- If SMTP auth is disabled and settings are saved, stored username and password are cleared.
- If the footer is disabled and settings are saved, stored footer text is cleared.
- The footer applies to all mail sent through `wp_mail()` across the network.

## License

This plugin is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Author

Developed by **637 Digital Solutions**  
Website: [https://637digital.com](https://637digital.com)

Copyright (c) 2026 637 Digital Solutions
