# woocommerce-gateway (Idealogic fork)

This is Idealogic's fork of [Payfast/woocommerce-gateway](https://github.com/Payfast/woocommerce-gateway),
the PayGate PayWeb gateway for WooCommerce. It carries fixes that order-status changes need on the
sites Idealogic hosts. Site images build the plugin from a pinned tag of this fork, never from a branch.

| Branch or tag | Meaning |
|---|---|
| `master` | Mirrors the vendor's `master`; no fork changes |
| `develop` | The fork's line: vendor release plus fixes |
| `v<vendor>-idealogic.<n>` | A fork release, for example `v1.7.1-idealogic.1` |

### What the fork changes

- A paid order is never moved to Pending, Failed or Cancelled by the redirect, notify, cron or
  admin query.
- A message that fails its checksum changes no order. It adds a note and logs the raw payload.
- The notify checksum is validated over the values exactly as PayGate sent them, in PayGate's
  documented field order.
- The redirect handler enforces its checksum for every order and acts on the status PayWeb
  returns to the server-side query, never the one in the browser POST.
- Order notes name the path actually taken: Redirect or Notify.

### Tests

```bash
composer install
vendor/bin/phpunit
```

The tests run the real handlers against an in-memory WordPress and WooCommerce (`tests/Support/`).
The harness lives at the repository root, outside the plugin folder, so it never reaches a site.

---

## Payfast Gateway module v1.7.1 for WooCommerce v10.6.2

This is the Payfast Gateway module for WooCommerce. Please feel free to contact the Payfast support team at
support@payfast.io should you require any assistance.

## Installation

1. **Download the Plugin**

    - Visit the [releases page](https://github.com/Payfast/woocommerce-gateway/releases) and
      download [paygate-payweb-for-woocommerce.zip](https://github.com/Payfast/woocommerce-gateway/releases/download/v1.7.1/paygate-payweb-for-woocommerce.zip).

2. **Install the Plugin**

    - Log in to your WordPress Admin panel.
    - Navigate to **Plugins > Add New > Upload Plugin**.
    - Click **Choose File** and select `paygate-payweb-for-woocommerce.zip`.
    - Click **Install Now**.
    - Click **Activate Plugin**.

3. **Configure the Plugin**

    - Navigate to **WooCommerce > Settings**.
        - Go to the **Payments** tab.
        - Select **Payfast Gateway** from the list of payment methods.
        - Tick the **Enable Payfast Gateway** checkbox.
        - Configure the plugin by entering your Payfast Gateway credentials and preferences.
        - Click **Save changes**.

    - Activate **Pay Vault**:
        - Tick the **Enable Pay Vault** checkbox in the Payfast Gateway settings under **WooCommerce > Settings**.
        - Click **Save changes**.
        - Ensure Pay Vault is enabled on your Payfast Gateway account before using this feature.
          Your customers' stored cards can be managed under their **WordPress User Account > Payment Methods**.

## Collaboration

Please submit pull requests with any tweaks, features or fixes you would like to share.
