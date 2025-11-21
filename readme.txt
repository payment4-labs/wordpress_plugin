=== Payment4 Crypto Payment gateway - For WooCommerce & RCP & EDD & Gravity Forms ===
Contributors: payment4,amyrosein
Tags: woocommerce, cryptocurrency, payment-gateway, edd, gravity-forms
Requires at least: 6.0
Requires PHP: 7.0
Tested up to: 6.8
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Text Domain: payment4-gateway-pro
Domain Path: /languages
Plugin URI: https://payment4.com/plugin
Author: Payment4.com, Amirhossein Taghizadeh
Author URI: https://payment4.com

Accept secure cryptocurrency payments in WooCommerce, Restrict Content Pro, Easy Digital Downloads, and Gravity Forms with Payment4.

== Description ==
Payment4 is a cutting-edge cryptocurrency payment gateway designed to empower businesses globally with fast, secure, and borderless payment solutions.

As the digital payment ecosystem continues to evolve, Payment4 enables businesses to stay ahead of the curve by seamlessly integrating cryptocurrency payments into their operations.

Supported integrations:
- WooCommerce
- Restrict Content Pro (RCP)
- Easy Digital Downloads (EDD)
- Gravity Forms

= Features =
- High Security  
- Easy Implementation  
- Fast and Cost-Effective Settlements  
- Secure Payments (Escrow)  
- 24/7 Support  
- Merchant Dashboard with Diverse Features  
- Custom Payment Page  
- Cryptocurrency Payment Link  

= Payment Gateway Fees =
- Get the Payment Gateway and Management Panel for Free  
- A 1% transaction fee, capped at $10  

Examples:  
- $50 payment → $0.50 fee  
- $1,300 payment → $10 fee (capped)  

= Languages Supported =
- Arabic  
- English  
- Persian  
- French  
- Spanish  
- Turkish  

= How It Works =
1. Customer Selects Crypto – During checkout, the customer chooses Payment4 (e.g., Bitcoin, USDT, or other supported coins).  
2. Invoice Creation – A crypto payment invoice is generated with a unique wallet address and QR code.  
3. Customer Sends Crypto – The customer transfers the exact amount to the provided wallet address.  
4. Real-Time Verification – Payment4 monitors the blockchain and instantly verifies the transaction.  
5. Order Finalization – Once confirmed, the order is processed and funds are sent directly to your configured wallet—Payment4 never holds your funds.  

With Payment4, you can accept cryptocurrency payments across your WooCommerce store, RCP membership site, EDD digital products, and Gravity Forms checkout forms—all seamlessly integrated.

== Installation ==
1. Upload the `payment4-wp-plugin-3.0.0` directory to the `/wp-content/plugins/` directory.  
2. Activate the plugin through the 'Plugins' menu in WordPress.  
3. Go to the plugin settings page and configure Payment4 gateway.  
4. Complete the setup by linking your Payment4 account.  

== Frequently Asked Questions ==

= How do I configure Payment4? =
Once you have installed and activated the plugin, navigate to the Payment4 settings and configure your API keys.

= Which cryptocurrencies are supported? =
Payment4 supports a wide range of cryptocurrencies. For a full list of supported cryptocurrencies, visit the documentation page at [Payment4 Documentation](https://payment4.com).

== External Services ==

This plugin relies on the Payment4 API, a third-party external service, to process cryptocurrency payments. The plugin will not function without connecting to this service.

= What is the Payment4 API? =
Payment4 (https://payment4.com) is a cryptocurrency payment gateway service that processes crypto transactions, monitors blockchain confirmations, and manages payment verification.

= When does the plugin connect to Payment4 services? =
The plugin connects to Payment4 external services in the following situations:

1. **During Checkout** - When a customer selects Payment4 as their payment method and proceeds to pay
2. **Payment Verification** - When verifying that a cryptocurrency payment has been received and confirmed on the blockchain
3. **Payment Callbacks** - When Payment4 sends webhook notifications about payment status updates
4. **Language Files** - When loading translation files for the plugin interface

= What data is transmitted? =
When processing payments, the following data is sent to Payment4 services:

* Order amount and currency
* Order ID and description
* Customer email address (optional, for payment notifications)
* Return and callback URLs for your website
* API credentials (API key) for authentication

No sensitive financial information (credit card details, bank accounts, etc.) is transmitted. Cryptocurrency transactions are handled directly on the blockchain.

= Service Endpoints Used =
* **Payment API**: https://service.payment4.com/api/v1/payment
* **Verification API**: https://service.payment4.com/api/v1/payment/verify
* **Currency Data**: https://storage.payment4.com/wp/currencies.json
* **Language Files**: https://storage.payment4.com/wp/languages.json

= Legal & Privacy =
* **Terms of Service**: https://payment4.com/terms-of-service
* **Privacy Policy**: https://payment4.com/privacy-policy

By using this plugin, you acknowledge that data will be transmitted to Payment4's servers for payment processing. Please review Payment4's terms of service and privacy policy to understand how your data is handled.

== Changelog ==

= 3.0.0 =
* Added integration with Restrict Content Pro, Easy Digital Downloads, and Gravity Forms.
* Expanded supported cryptocurrencies.
* Enhanced real-time verification and payment flow.
* General performance and security improvements.

= 2.2.2 =
* Added support for more cryptocurrencies.
* Fixed compatibility issues with WooCommerce 6.0.
* Updated payment gateway security features.

= 2.2.1 =
* Bug fixes related to payment status updates.
* Minor performance improvements.

= 2.2.0 =
* Initial release for WooCommerce integration.

== Upgrade Notice ==

= 3.0.0 =
Upgrade to access new integrations (RCP, EDD, Gravity Forms), improved crypto support, and enhanced payment verification.

== License ==
This plugin is licensed under the GPLv2 (or later) License. You can view the full text of the GPLv2 license at: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

== Support ==
For support, visit our [Support Page](https://payment4.com/support).