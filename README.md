
# Paystack WHMCS Plugin

Welcome to the Paystack WHMCS plugin repository on GitHub. 

Here you can browse the source code, look at open issues and keep track of development.

## Installation 

### Requirements

- Existing WHMCS installation on your web server.
- Supported Web Servers: Apache and Nginx
- PHP (5.5.19 or more recent) and extensions, MySQL and web browser
- cURL (7.34.0 or more recent)
- OpenSSL v1.0.1 or more recent

### Prepare

- Before you can start taking payments through Paystack, you will first need to sign up at: 
[https://dashboard.paystack.co/#/signup][link-signup]. To receive live payments, you should request a Go-live after
you are done with configuration and have successfully made a test payment.

### Install
1. Copy [paystack.php](modules/gateways/paystack.php?raw=true) in [modules/gateways](modules/gateways) to the `/modules/gateways/` folder of your WHMCS installation.

2. Copy [paystack.php](modules/gateways/callback/paystack.php?raw=true) in [modules/gateways/callback](modules/gateways/callback) to the `/modules/gateways/callback` folder of your WHMCS installation.

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
