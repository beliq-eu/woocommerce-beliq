=== beliq e-invoicing ===
Contributors: beliq
Tags: e-invoicing, xrechnung, zugferd, peppol, factur-x
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Generate compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) from WooCommerce orders through the beliq API.

== Description ==

beliq e-invoicing turns a WooCommerce order into a structured, EN 16931 compliant electronic invoice using the beliq API. When an order reaches the status you choose, the plugin maps the order onto invoice semantics, calls beliq to produce the document, validates it against the business rules, and stores it with the order for download.

The plugin generates and validates the document. It does not send, file, transmit, or archive it: Peppol transmission, e-mail delivery, and tax-authority reporting stay with you.

Supported output formats:

* XRechnung (XML)
* ZUGFeRD / Factur-X (hybrid PDF)
* Peppol BIS Billing (XML)

Features:

* Automatic generation when an order becomes processing or completed.
* A business-only default: generate only when the buyer looks like a business (VAT ID or company), or widen it to every order.
* Seller legal details, payment account, and VAT category for zero-rated lines, all configurable.
* Reads a buyer VAT ID and a buyer reference (for example a Leitweg-ID) from order meta keys you name, so data another plugin stores can flow onto the invoice.
* Manual generate and regenerate from the order screen, with a capability-checked download.
* High-Performance Order Storage (HPOS) compatible.

A beliq API key is required. The free tier is enough to evaluate the plugin.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to wp-content/plugins and activate it, or install it from the plugin directory.
3. Go to WooCommerce > Settings > Integrations > beliq e-invoicing.
4. Enter your beliq API key and your seller legal details.
5. Choose the document format and the order status that generation runs on, then enable automatic generation.

== Frequently Asked Questions ==

= Does this send my invoices anywhere? =

No. The plugin generates and validates the document and stores it with the order. Sending, filing, and archiving stay with you.

= Do I need a beliq account? =

Yes, you need a beliq API key. The free tier is enough to evaluate the plugin.

= Which orders get an invoice? =

By default, only orders that look like a business purchase (a VAT ID or a company on the billing details). You can widen this to every order in the settings.

= Where is the document stored? =

In a protected subdirectory of your WordPress uploads folder. It is served only through a capability-checked download on the order screen, never linked publicly.

== Changelog ==

= 0.1.0 =
* Initial release: order-to-EN 16931 mapping, the beliq API client, the settings screen, automatic generation on the configured order status, manual generate/regenerate, protected download, and HPOS compatibility.
