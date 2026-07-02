# woocommerce-beliq

A WooCommerce plugin that turns store orders into compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the beliq API. It generates and validates the documents; it does not send, file, or submit them anywhere.

## Status

Pass 1: framework-agnostic core + order adapter + offline tests.

## Development

```
composer test
composer scrub:check
```
