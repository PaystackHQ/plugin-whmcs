
# Paystack WHMCS Plugin

Welcome to the Paystack WHMCS plugin repository on GitHub. 

Here you can browse the source code, look at open issues and keep track of development.

## Installation 

### Requirements

- Existing WHMCS installation on your web server.
- Supported Web Servers: Apache and Nginx
- PHP with the 21 Oct 2026 security fixes for critical remote code execution flaws (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp; [php.net advisory](https://www.php.net/releases/8_2_35.php)): **8.2.35, 8.3.36, 8.4.27, 8.5.12 or later**. Pick a version your WHMCS release supports. PHP 8.1 and older no longer receive security fixes, so if your WHMCS release can't run a patched PHP, upgrade WHMCS first.
- PHP extensions required by WHMCS, MySQL and a web browser
- cURL (7.34.0 or more recent)
- OpenSSL v1.0.1 or more recent

### Prepare

- Before you can start taking payments through Paystack, you will first need to sign up at: 
[https://dashboard.paystack.co/#/signup][link-signup]. To receive live payments, you should request a Go-live after
you are done with configuration and have successfully made a test payment.

### Install
1. Copy [paystack.php](modules/gateways/paystack.php?raw=true) in [modules/gateways](modules/gateways) to the `/modules/gateways/` folder of your WHMCS installation.

2. Copy the [paystack](modules/gateways/paystack) folder in [modules/gateways](modules/gateways) to the `/modules/gateways/` folder of your WHMCS installation. When upgrading, copy this folder too, otherwise the restricted-key checks are skipped.

3. Copy [paystack.php](modules/gateways/callback/paystack.php?raw=true) in [modules/gateways/callback](modules/gateways/callback) to the `/modules/gateways/callback` folder of your WHMCS installation.

### Using a restricted key

You can use a restricted key (`rk_`) instead of your secret key (`sk_`):

- On the Paystack Dashboard, go to **Developers > API configuration** and click **Create restricted key**. Give it only **Transactions: read**. The listed money-moving permissions (Transactions: write, refunds, transfers, settlements, etc.) are refused, because the plugin never needs them.
- Use the public key from the same integration and mode: `rk_test_` with `pk_test_` in the Test fields, and `rk_live_` with `pk_live_` in the Live fields.
- On the same page, click **Generate signing secret** and paste the `whsec_` value into the matching Webhook Signing Secret field. Without it, Paystack webhooks are ignored and payments are confirmed only when the customer returns to your site.
- Roll your secret key afterwards.

The key is checked with Paystack when you save, on WHMCS versions that support save-time validation. If Paystack can't be reached to check the key, you can save with your `sk_` secret key instead. Problems at payment time are written to the Gateway Log, even when Gateway logs is off.

## Documentation

* [Paystack Documentation](https://developers.paystack.co/v2.0/docs/)
* [Paystack Helpdesk](https://paystack.com/help)

## Support

For bug reports and feature requests directly related to this plugin, please use the [issue tracker](https://github.com/PaystackHQ/plugin-whmcs/issues). 

For general support or questions about your Paystack account, you can reach out by sending a message from [our website](https://paystack.com/contact).

## Community

If you are a developer, please join our Developer Community on [Slack](https://slack.paystack.com).

## Contributing to the WHMCS plugin

If you have a patch or have stumbled upon an issue with the WHMCS plugin, you can contribute this back to the code. Please read our [contributor guidelines](https://github.com/PaystackHQ/plugin-whmcs/blob/master/CONTRIBUTING.md) for more information how you can do this.
