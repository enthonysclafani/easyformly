# Included sources

The combined plugin is distributed under GPL-3.0-or-later. The
upstream files below retain their own copyright and license notices.

| Component | Version / revision | License | Local files |
| --- | --- | --- | --- |
| [libphonenumber for PHP, lite](https://github.com/giggsey/libphonenumber-for-php-lite) | 9.0.41, `6ffb41a259c950d6f4df5596e32917f631052910` | Apache-2.0 | `includes/vendor/phone`, including `LICENSE.txt` |
| [Symfony mbstring polyfill](https://github.com/symfony/polyfill-mbstring) | 1.43.0, `4d5d331bf731e0aedbc6b2f5e61135b7b7996a72` | MIT | `includes/vendor/mbstring`, including `LICENSE` |
| [Disposable email domains](https://github.com/disposable-email-domains/disposable-email-domains) | snapshot `2a79805ed6caf893921e3d0f0f85ac18a6fea99c`, 2026-10-08, 9,221 domains | CC0-1.0 | Combined in `includes/data/disposable-domains.txt`; `disposable-domains-LICENSE.txt` |
| [disposable/disposable](https://github.com/disposable/disposable), [generated dataset](https://github.com/disposable/disposable-email-domains) | snapshot `60708bec04eec0865ca2c7e950cd26476ad83a80`, 2026-10-08, 96,306 domains, normal list | MIT | Combined in `includes/data/disposable-domains.txt`; `disposable-aggregate-LICENSE.txt` |

The phone library contains Google's numbering metadata and works locally. Its
namespace has been changed to `EasyFormly\Vendor\Phone` to avoid conflicts
with other plugins. The polyfill's classes are similarly isolated. It provides
missing mbstring functions only on installations without the extension.
`countries.json` is generated from the library's supported regions and calling
codes; English fallback names are generated with PHP Intl. No other changes have
been made to the bundled upstream PHP source. Updates ship with plugin releases;
there are no automatic downloads on visitors' requests.

## Reproduce the phone bundle

PHP 8.1+, Composer and PHP Intl are needed at build time. They are not required
as separate installations on the WordPress server.

```sh
composer install --working-dir=tools/phone-build --no-dev --no-interaction
php tools/bundle-phone.php tools/phone-build/vendor/giggsey/libphonenumber-for-php-lite
```

`tools/phone-build/composer.lock` pins the exact versions and source revisions.
Do not ship its development `vendor` directory; the isolated runtime bundle is
already included under `includes/vendor`. Review upstream metadata changes and
run the integration tests when updating the lock file.

## Disposable-domain snapshots and manual synchronization

The bundled list is the sorted, deduplicated union of these two upstream files:

- `https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/2a79805ed6caf893921e3d0f0f85ac18a6fea99c/disposable_email_blocklist.conf`
- `https://raw.githubusercontent.com/disposable/disposable-email-domains/60708bec04eec0865ca2c7e950cd26476ad83a80/domains.txt`

`includes/data/disposable-domains.json` records source counts, revisions and the
latest source timestamp. The combined snapshot contains 96,316 domains. The
normal list excludes disposable/disposable's separate greylist; the strict list
is never fetched.

Administrators can manually synchronize from the corresponding moving
`main`/`master` URLs in Settings. Only those fixed HTTPS sources are contacted;
no form fields or submitted email addresses are sent. Both responses must pass
validation before the complete combined snapshot is saved in a non-autoloaded
WordPress option, `easyformly_disposable_domains`. The latest successful snapshot stays
active without expiring; failures retain it, and upstream removals are respected
when both current sources stop listing a domain. There is no synchronization
cron, automatic check on activation or settings visits, or visitor-time HTTP.
Uninstall removes this reproducible cache, including on multisite.
