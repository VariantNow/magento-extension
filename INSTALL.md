# Installing Variant on Magento

`variant/magento-extension` adds two modules to a Magento Open Source or Adobe Commerce 2.4 store (PHP 8.1–8.4):

- **Variant_Connector** loads Variant's storefront script on every page and adds the "Variant" API integration.
- **Variant_Pricing** applies Variant's experiment discounts, price tests and bundles in Magento's own cart and checkout.

## 1. Get access

The package is private. On the server that runs Composer for the store (your build or deploy server), create an SSH key for it and send Variant the **public** half:

```bash
ssh-keygen -t ed25519 -N "" -C "variant-magento-<store>" -f ~/.ssh/variant_magento
```

```bash
cat ~/.ssh/variant_magento.pub
```

Variant adds it as a read-only key on `github.com/VariantNow/magento-extension`. Then let Composer's git use it, and trust GitHub's host key (Composer cannot answer SSH's first-connection prompt):

```bash
git config --global core.sshCommand "ssh -i ~/.ssh/variant_magento -o IdentitiesOnly=yes"
```

```bash
ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts
```

## 2. Install

From the Magento root:

```bash
composer config repositories.variant vcs git@github.com:VariantNow/magento-extension.git
```

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

## 3. Connect the store

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
