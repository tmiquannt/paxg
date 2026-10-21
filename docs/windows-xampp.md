# Windows and XAMPP local setup

This setup runs the Mini ERP WordPress site on XAMPP's Apache, with compatible PHP and MySQL components. Keep it on the same Windows computer and use fictitious data only.

## Check the bundled versions first

Mini ERP currently requires **PHP 8.3 or later**, including `mysqli`, `bcmath`, `dom`, and `mbstring`, plus **Oracle MySQL 8.0.16 or later** with enforced `CHECK` constraints. The plugin's current master-schema migration explicitly rejects MariaDB. The XAMPP Windows download listed by Apache Friends currently bundles PHP 8.2.12 and MariaDB 10.4.32, so that stock package does not meet the application's requirements. XAMPP has shipped MariaDB rather than MySQL for many years. [Apache Friends downloads](https://www.apachefriends.org/download.html), [Apache Friends Windows FAQ](https://www.apachefriends.org/faq_windows)

Use XAMPP for Apache and upgrade the PHP module to PHP 8.3+ Thread Safe (TS), x64, built for the matching Visual Studio toolchain. Install Oracle MySQL 8 separately as a Windows service on port `3307`; leave XAMPP's bundled MariaDB stopped. PHP's Windows/Apache manual requires a TS build for `apache2handler` and documents the Apache module directives. [PHP for Windows downloads](https://www.php.net/downloads.php?os=windows&version=8.3), [PHP Apache on Windows](https://www.php.net/manual/en/install.windows.apache2.php), [MySQL Installer for Windows](https://dev.mysql.com/doc/mysql-windows-excerpt/8.0/en/windows-installation.html)

If you do not want to replace XAMPP's PHP module and install a second database server, use the project's Docker setup instead.

## Install the local site

1. Back up XAMPP's `apache/conf/extra/httpd-xampp.conf` and `php` directory.
2. Install PHP 8.3+ TS x64 into a separate directory, for example `C:\xampp\php83`. Copy `php.ini-development` to `php.ini`, set `extension_dir` to `C:\xampp\php83\ext`, and enable `mysqli`, `bcmath`, `dom`, `mbstring`, `fileinfo`, and `openssl`.
3. In XAMPP's `apache/conf/extra/httpd-xampp.conf`, update the existing PHP `LoadFile`, `LoadModule`, and `PHPIniDir` entries to point to the PHP 8.3 directory. Keep one PHP `LoadModule`; do not add a second PHP module alongside the bundled one. The Apache handler should load `php8apache2_4.dll` from the TS build. Before starting Apache, check its configuration:

   ```powershell
   C:\xampp\apache\bin\httpd.exe -t
   ```

4. Install Oracle MySQL 8 and configure it on port `3307`. Create a local database and dedicated account (do not use the MySQL root account for WordPress):

   ```sql
   CREATE DATABASE paxg_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'paxg_local'@'127.0.0.1' IDENTIFIED BY 'choose-a-local-password';
   GRANT ALL PRIVILEGES ON paxg_local.* TO 'paxg_local'@'127.0.0.1';
   ```

5. Download WordPress into a new XAMPP web directory such as `C:\xampp\htdocs\mini-erp-site`. In `wp-config.php`, set `DB_NAME` to `paxg_local`, `DB_USER` to `paxg_local`, `DB_PASSWORD` to the password you chose, `DB_HOST` to `127.0.0.1:3307`, and `$table_prefix` to `paxg_`. Add:

   ```php
   define('WP_ENVIRONMENT_TYPE', 'local');
   ```

6. Copy this repository's `wp-content/plugins/mini-erp` directory, including `vendor`, to `C:\xampp\htdocs\mini-erp-site\wp-content\plugins\mini-erp`. Start Apache in the XAMPP Control Panel and start the separate MySQL 8 Windows service. Do not start XAMPP's MariaDB. Complete the WordPress installer, then activate **Mini ERP** in WordPress Plugins. Activation installs the ERP tables.
7. Create the initial ERP System Admin once through the documented WordPress technical-admin bootstrap. After that, employees and ERP roles are managed inside the ERP app.

## Preflight and sample data

From PowerShell, run the PHP CLI that belongs to the upgraded XAMPP PHP directory. First use `--dry-run`; it checks the PHP extensions, local WordPress site, active plugin, and MySQL version without writing demo rows.

```powershell
$repo = 'C:\path\to\paxg'
$php = 'C:\xampp\php83\php.exe'
$wordpress = 'C:\xampp\htdocs\mini-erp-site'
& $php "$repo\scripts\seed-demo.php" --wordpress-root $wordpress --dry-run
& $php "$repo\scripts\seed-demo.php" --wordpress-root $wordpress
```

The seed command refuses non-local WordPress environments, non-loopback site URLs, MariaDB, and MySQL versions below 8.0.16. It creates generated passwords for new demo accounts and prints them once. Store them locally; the seed does not send account emails or reset passwords. Open `http://localhost/mini-erp-site/` and sign in with one of the printed ERP demo accounts.

## Troubleshooting

- **Apache fails after PHP upgrade:** restore the saved Apache config; confirm the PHP TS build's architecture and compiler match XAMPP's Apache, confirm `php8apache2_4.dll` exists, then run `httpd.exe -t` again.
- **Plugin activation reports unsupported database:** stop XAMPP MariaDB and connect WordPress to the separate Oracle MySQL 8 service on `127.0.0.1:3307`.
- **The CLI passes but the site fails:** confirm Apache is loading the same PHP 8.3 module and `php.ini`; restarting Apache is required after changing either.
- **Database port is busy:** keep XAMPP MariaDB stopped and confirm MySQL 8 is the service listening on `3307`.

This repository has not been run under Windows/XAMPP in this workspace. The instructions are based on the app's enforced runtime checks and the linked vendor documentation; verify the versions shown by the preflight on the target Windows machine.
