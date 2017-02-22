# Paystack Gateway for WHMCS

A plugin that allows WHMCS users accept payments using Paystack

## Requirements

- Existing WHMCS installation on your web server.
- Supported Web Servers: Apache and Nginx
- PHP (5.5.19 or more recent) and extensions, MySQL and web browser
- cURL (7.34.0 or more recent)
- OpenSSL v1.0.1 or more recent

## Prepare

- Before you can start taking payments through Paystack, you will first need to sign up at: 
[https://dashboard.paystack.co/#/signup][link-signup]. To receive live payments, you should request a Go-live after
you are done with configuration and have successfully made a test payment.

## Install
1. Copy [paystack.php](modules/gateways/paystack.php?raw=true) in [modules/gateways](modules/gateways) to the `/modules/gateways/` folder of your WHMCS installation.

2. Copy [paystack.php](modules/gateways/callback/paystack.php?raw=true) in [modules/gateways/callback](modules/gateways/callback) to the `/modules/gateways/callback` folder of your WHMCS installation.

## I'm ready!

- Request `Go-live` on the Paystack Dashboard.

## Note

- Paystack currently only accepts `NGN` for now.

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email `support@paystack.com` instead of using the issue tracker.

## Credits

- [Ibrahim Lawal][link-author2]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[link-author]: https://github.com/paystackhq
[link-signup]: https://dashboard.paystack.co/#/signup
[link-author2]: https://github.com/ibrahimlawal
[link-contributors]: ../../contributors
