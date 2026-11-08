# Installing Variant on Magento

`variant/magento-extension` adds two modules to a Magento Open Source or Adobe Commerce 2.4 store (PHP 8.1–8.4):

- **Variant_Connector** loads Variant's storefront script on every page and adds the "Variant" API integration.
- **Variant_Pricing** applies Variant's experiment discounts, price tests and bundles in Magento's own cart and checkout.

The package is on [Packagist](https://packagist.org/packages/variant/magento-extension), Composer's default registry, so no access setup is needed.

## 1. Install

From the Magento root:

```bash
composer require variant/magento-extension:^0.1
```

```bash
bin/magento module:enable Variant_Connector Variant_Pricing
```

```bash
bin/magento setup:upgrade
```

In production mode, also:

```bash
bin/magento setup:di:compile && bin/magento setup:static-content:deploy
```

```bash
bin/magento cache:flush
```

## 2. Connect the store

1. **Stores › Configuration › Variant**: set **Enable Variant** to Yes and paste the **Shop ID** Variant gave you.
2. **System › Extensions › Integrations › Variant › Activate**, approve the access, and in the Variant window that opens pick your shop and click **Connect store**. Finish within about 5 minutes of clicking Activate; otherwise click **Reauthorize** and connect again.

## Updating

```bash
composer update variant/magento-extension
```

Then run `setup:upgrade` (and, in production mode, `setup:di:compile`, `setup:static-content:deploy`), then `cache:flush`. If a release notes that the integration changed, click **Reauthorize** on the Variant integration afterwards.

## Removing

```bash
bin/magento module:disable Variant_Pricing Variant_Connector
```

```bash
composer remove variant/magento-extension
```

Then `setup:upgrade` and `cache:flush`, and delete the Variant integration under System › Extensions › Integrations.

## License

Proprietary; see [LICENSE](LICENSE). Installing and running it on a store connected to a Variant account is permitted.
