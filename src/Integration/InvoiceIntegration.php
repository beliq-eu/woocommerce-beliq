<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Integration;

use WC_Integration;

/**
 * The beliq settings screen, shown under WooCommerce > Settings > Integrations.
 * It collects the beliq connection, the seller legal details, the payment
 * account, the generation options, and the two WooCommerce-only meta-key
 * mappings (buyer VAT id, buyer reference). WooCommerce persists the values under
 * the option woocommerce_beliq_settings; WooPluginConfigProvider reads them back.
 *
 * The field keys match the keys PluginConfig::fromValues expects, so the settings
 * map straight through without a translation layer.
 */
class InvoiceIntegration extends WC_Integration
{
    public function __construct()
    {
        $this->id = 'beliq';
        $this->method_title = __('beliq e-invoicing', 'woocommerce-beliq');
        $this->method_description = __(
            'Generate compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) from your orders through the beliq API. beliq generates and validates the document; sending, archiving, and filing stay with you.',
            'woocommerce-beliq',
        );

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'connection_title' => [
                'title' => __('beliq connection', 'woocommerce-beliq'),
                'type' => 'title',
            ],
            'apiKey' => [
                'title' => __('API key', 'woocommerce-beliq'),
                'type' => 'password',
                'description' => __('Your beliq API key. The free tier is enough to evaluate the plugin.', 'woocommerce-beliq'),
                'default' => '',
            ],
            'baseUrl' => [
                'title' => __('API base URL', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => 'https://api.beliq.eu',
            ],

            'seller_title' => [
                'title' => __('Seller details', 'woocommerce-beliq'),
                'type' => 'title',
            ],
            'sellerName' => [
                'title' => __('Legal name', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerVatId' => [
                'title' => __('VAT ID', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerTaxId' => [
                'title' => __('Tax number', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerRegistrationId' => [
                'title' => __('Company registration number', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerEmail' => [
                'title' => __('Contact e-mail', 'woocommerce-beliq'),
                'type' => 'text',
                'description' => __('Also used as the seller electronic address (BT-34). XRechnung requires it (BR-DE-7).', 'woocommerce-beliq'),
                'default' => '',
            ],
            'sellerContactName' => [
                'title' => __('Contact person', 'woocommerce-beliq'),
                'type' => 'text',
                'description' => __('Seller contact name (BG-6 / BT-41). Required by XRechnung (BR-DE-5).', 'woocommerce-beliq'),
                'default' => '',
            ],
            'sellerPhone' => [
                'title' => __('Contact phone', 'woocommerce-beliq'),
                'type' => 'text',
                'description' => __('Seller contact telephone (BT-42). Required by XRechnung (BR-DE-6).', 'woocommerce-beliq'),
                'default' => '',
            ],
            'sellerStreet' => [
                'title' => __('Street and number', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerPostalCode' => [
                'title' => __('Postal code', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerCity' => [
                'title' => __('City', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerCountryCode' => [
                'title' => __('Country code (2 letters)', 'woocommerce-beliq'),
                'type' => 'text',
                'placeholder' => 'DE',
                'default' => '',
            ],

            'payment_title' => [
                'title' => __('Payment details', 'woocommerce-beliq'),
                'type' => 'title',
            ],
            'paymentMeansCode' => [
                'title' => __('Payment means', 'woocommerce-beliq'),
                'type' => 'select',
                'description' => __('How the buyer pays (BG-16). An IBAN is required for either option; XRechnung rejects a SEPA credit transfer without one (BR-DE-23-a).', 'woocommerce-beliq'),
                'default' => '58',
                'options' => [
                    '58' => __('SEPA credit transfer', 'woocommerce-beliq'),
                    '30' => __('Credit transfer', 'woocommerce-beliq'),
                ],
            ],
            'sellerIban' => [
                'title' => __('IBAN', 'woocommerce-beliq'),
                'type' => 'text',
                'description' => __('The account the buyer pays into. Leave the payment fields empty to omit payment details.', 'woocommerce-beliq'),
                'default' => '',
            ],
            'sellerBic' => [
                'title' => __('BIC', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerBankName' => [
                'title' => __('Bank name', 'woocommerce-beliq'),
                'type' => 'text',
                'default' => '',
            ],

            'generation_title' => [
                'title' => __('Invoice generation', 'woocommerce-beliq'),
                'type' => 'title',
            ],
            'enabled' => [
                'title' => __('Automatic generation', 'woocommerce-beliq'),
                'label' => __('Generate invoices automatically', 'woocommerce-beliq'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
            'standard' => [
                'title' => __('Document format', 'woocommerce-beliq'),
                'type' => 'select',
                'default' => 'zugferd',
                'options' => [
                    'zugferd' => __('ZUGFeRD / Factur-X (hybrid PDF)', 'woocommerce-beliq'),
                    'facturx' => __('Factur-X (hybrid PDF)', 'woocommerce-beliq'),
                    'xrechnung' => __('XRechnung (XML)', 'woocommerce-beliq'),
                    'peppol-bis' => __('Peppol BIS Billing (XML)', 'woocommerce-beliq'),
                ],
            ],
            'profile' => [
                'title' => __('Profile', 'woocommerce-beliq'),
                'type' => 'select',
                'description' => __('Applies to the ZUGFeRD / Factur-X family. XRechnung and Peppol BIS pin their own profile.', 'woocommerce-beliq'),
                'default' => 'en16931',
                'options' => [
                    'en16931' => __('EN 16931 (comfort)', 'woocommerce-beliq'),
                    'basicwl' => __('Basic WL', 'woocommerce-beliq'),
                    'extended' => __('Extended', 'woocommerce-beliq'),
                ],
            ],
            'output' => [
                'title' => __('Output', 'woocommerce-beliq'),
                'type' => 'select',
                'default' => 'pdf',
                'options' => [
                    'pdf' => __('PDF (hybrid, where the format supports it)', 'woocommerce-beliq'),
                    'xml' => __('XML only', 'woocommerce-beliq'),
                ],
            ],
            'businessOnly' => [
                'title' => __('Scope', 'woocommerce-beliq'),
                'label' => __('Only generate for business orders', 'woocommerce-beliq'),
                'type' => 'checkbox',
                'description' => __('Generate only when the buyer looks like a business (VAT ID or company). Turn off to generate for every order.', 'woocommerce-beliq'),
                'default' => 'yes',
            ],
            'triggerEvent' => [
                'title' => __('Generate when', 'woocommerce-beliq'),
                'type' => 'select',
                'default' => 'processing',
                'options' => [
                    'processing' => __('Payment received (order is processing)', 'woocommerce-beliq'),
                    'completed' => __('Order is completed', 'woocommerce-beliq'),
                ],
            ],
            'zeroRateCategory' => [
                'title' => __('VAT category for 0% lines', 'woocommerce-beliq'),
                'type' => 'select',
                'description' => __('The tax treatment for zero-rated lines is your call. Reverse charge and intra-community supply are not auto-detected.', 'woocommerce-beliq'),
                'default' => 'Z',
                'options' => [
                    'Z' => __('Z - Zero rated', 'woocommerce-beliq'),
                    'E' => __('E - Exempt', 'woocommerce-beliq'),
                    'G' => __('G - Export outside the EU', 'woocommerce-beliq'),
                ],
            ],

            'meta_title' => [
                'title' => __('Order field mapping', 'woocommerce-beliq'),
                'type' => 'title',
                'description' => __('If another plugin stores the buyer VAT ID or a buyer reference (for example a Leitweg-ID) in order meta, name the meta keys here to carry them onto the invoice.', 'woocommerce-beliq'),
            ],
            'buyerVatMetaKey' => [
                'title' => __('Buyer VAT ID meta key', 'woocommerce-beliq'),
                'type' => 'text',
                'placeholder' => '_billing_vat',
                'default' => '',
            ],
            'buyerReferenceMetaKey' => [
                'title' => __('Buyer reference meta key', 'woocommerce-beliq'),
                'type' => 'text',
                'description' => __('The buyer reference (BT-10). Falls back to a customer reference, then the order number, when empty.', 'woocommerce-beliq'),
                'default' => '',
            ],
        ];
    }
}
