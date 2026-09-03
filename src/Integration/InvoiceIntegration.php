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
        $this->method_title = __('beliq e-invoicing', 'beliq-e-invoicing');
        $this->method_description = __(
            'Generate compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) from your orders through the beliq API. beliq generates and validates the document; sending, archiving, and filing stay with you.',
            'beliq-e-invoicing',
        );

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'connection_title' => [
                'title' => __('beliq connection', 'beliq-e-invoicing'),
                'type' => 'title',
            ],
            'apiKey' => [
                'title' => __('API key', 'beliq-e-invoicing'),
                'type' => 'password',
                'description' => __('Your beliq API key. The free tier is enough to evaluate the plugin.', 'beliq-e-invoicing'),
                'default' => '',
            ],

            'seller_title' => [
                'title' => __('Seller details', 'beliq-e-invoicing'),
                'type' => 'title',
            ],
            'sellerName' => [
                'title' => __('Legal name', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerVatId' => [
                'title' => __('VAT ID', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerTaxId' => [
                'title' => __('Tax number', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerRegistrationId' => [
                'title' => __('Company registration number', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerEmail' => [
                'title' => __('Contact e-mail', 'beliq-e-invoicing'),
                'type' => 'text',
                'description' => __('Also used as the seller electronic address (BT-34). XRechnung requires it (BR-DE-7).', 'beliq-e-invoicing'),
                'default' => '',
            ],
            'sellerContactName' => [
                'title' => __('Contact person', 'beliq-e-invoicing'),
                'type' => 'text',
                'description' => __('Seller contact name (BG-6 / BT-41). Required by XRechnung (BR-DE-5).', 'beliq-e-invoicing'),
                'default' => '',
            ],
            'sellerPhone' => [
                'title' => __('Contact phone', 'beliq-e-invoicing'),
                'type' => 'text',
                'description' => __('Seller contact telephone (BT-42). Required by XRechnung (BR-DE-6).', 'beliq-e-invoicing'),
                'default' => '',
            ],
            'sellerStreet' => [
                'title' => __('Street and number', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerPostalCode' => [
                'title' => __('Postal code', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerCity' => [
                'title' => __('City', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerCountryCode' => [
                'title' => __('Country code (2 letters)', 'beliq-e-invoicing'),
                'type' => 'text',
                'placeholder' => 'DE',
                'default' => '',
            ],

            'payment_title' => [
                'title' => __('Payment details', 'beliq-e-invoicing'),
                'type' => 'title',
            ],
            'paymentMeansCode' => [
                'title' => __('Payment means', 'beliq-e-invoicing'),
                'type' => 'select',
                'description' => __('How the buyer pays (BG-16). An IBAN is required for either option; XRechnung rejects a SEPA credit transfer without one (BR-DE-23-a).', 'beliq-e-invoicing'),
                'default' => '58',
                'options' => [
                    '58' => __('SEPA credit transfer', 'beliq-e-invoicing'),
                    '30' => __('Credit transfer', 'beliq-e-invoicing'),
                ],
            ],
            'sellerIban' => [
                'title' => __('IBAN', 'beliq-e-invoicing'),
                'type' => 'text',
                'description' => __('The account the buyer pays into. Leave the payment fields empty to omit payment details.', 'beliq-e-invoicing'),
                'default' => '',
            ],
            'sellerBic' => [
                'title' => __('BIC', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],
            'sellerBankName' => [
                'title' => __('Bank name', 'beliq-e-invoicing'),
                'type' => 'text',
                'default' => '',
            ],

            'generation_title' => [
                'title' => __('Invoice generation', 'beliq-e-invoicing'),
                'type' => 'title',
            ],
            'enabled' => [
                'title' => __('Automatic generation', 'beliq-e-invoicing'),
                'label' => __('Generate invoices automatically', 'beliq-e-invoicing'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
            'standard' => [
                'title' => __('Document format', 'beliq-e-invoicing'),
                'type' => 'select',
                'default' => 'zugferd',
                'options' => [
                    'zugferd' => __('ZUGFeRD / Factur-X (hybrid PDF)', 'beliq-e-invoicing'),
                    'facturx' => __('Factur-X (hybrid PDF)', 'beliq-e-invoicing'),
                    'xrechnung' => __('XRechnung (XML)', 'beliq-e-invoicing'),
                    'peppol-bis' => __('Peppol BIS Billing (XML)', 'beliq-e-invoicing'),
                ],
            ],
            'profile' => [
                'title' => __('Profile', 'beliq-e-invoicing'),
                'type' => 'select',
                'description' => __('Applies to the ZUGFeRD / Factur-X family. XRechnung and Peppol BIS pin their own profile.', 'beliq-e-invoicing'),
                'default' => 'en16931',
                'options' => [
                    'en16931' => __('EN 16931 (comfort)', 'beliq-e-invoicing'),
                    'basicwl' => __('Basic WL', 'beliq-e-invoicing'),
                    'extended' => __('Extended', 'beliq-e-invoicing'),
                ],
            ],
            'output' => [
                'title' => __('Output', 'beliq-e-invoicing'),
                'type' => 'select',
                'default' => 'pdf',
                'options' => [
                    'pdf' => __('PDF (hybrid, where the format supports it)', 'beliq-e-invoicing'),
                    'xml' => __('XML only', 'beliq-e-invoicing'),
                ],
            ],
            'businessOnly' => [
                'title' => __('Scope', 'beliq-e-invoicing'),
                'label' => __('Only generate for business orders', 'beliq-e-invoicing'),
                'type' => 'checkbox',
                'description' => __('Generate only when the buyer looks like a business (VAT ID or company). Turn off to generate for every order.', 'beliq-e-invoicing'),
                'default' => 'yes',
            ],
            'triggerEvent' => [
                'title' => __('Generate when', 'beliq-e-invoicing'),
                'type' => 'select',
                'default' => 'processing',
                'options' => [
                    'processing' => __('Payment received (order is processing)', 'beliq-e-invoicing'),
                    'completed' => __('Order is completed', 'beliq-e-invoicing'),
                ],
            ],
            'zeroRateCategory' => [
                'title' => __('VAT category for 0% lines', 'beliq-e-invoicing'),
                'type' => 'select',
                'description' => __('The tax treatment for zero-rated lines is your call. Reverse charge and intra-community supply are not auto-detected.', 'beliq-e-invoicing'),
                'default' => 'Z',
                'options' => [
                    'Z' => __('Z - Zero rated', 'beliq-e-invoicing'),
                    'E' => __('E - Exempt', 'beliq-e-invoicing'),
                    'G' => __('G - Export outside the EU', 'beliq-e-invoicing'),
                ],
            ],

            'meta_title' => [
                'title' => __('Order field mapping', 'beliq-e-invoicing'),
                'type' => 'title',
                'description' => __('If another plugin stores the buyer VAT ID or a buyer reference (for example a Leitweg-ID) in order meta, name the meta keys here to carry them onto the invoice.', 'beliq-e-invoicing'),
            ],
            'buyerVatMetaKey' => [
                'title' => __('Buyer VAT ID meta key', 'beliq-e-invoicing'),
                'type' => 'text',
                'placeholder' => '_billing_vat',
                'default' => '',
            ],
            'buyerReferenceMetaKey' => [
                'title' => __('Buyer reference meta key', 'beliq-e-invoicing'),
                'type' => 'text',
                'description' => __('The buyer reference (BT-10). Falls back to a customer reference, then the order number, when empty.', 'beliq-e-invoicing'),
                'default' => '',
            ],
        ];
    }
}
