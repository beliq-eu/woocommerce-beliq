<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Config;

use Beliq\Core\Invoice\Address;
use Beliq\Core\Invoice\Party;
use Beliq\Core\Invoice\PaymentMeans;
use Beliq\Core\Invoice\SourceOrder;

/**
 * The merchant's plugin settings as a typed value object. The seller is
 * pre-assembled into a Party from the seller-legal fields (name, address,
 * VAT/tax ids, and the BG-6 contact); the seller's bank details become the
 * paymentMeans template. profile is null when the merchant leaves it blank,
 * letting the engine pick its default. triggerEvent holds the WooCommerce order
 * status that generation runs on.
 */
final readonly class PluginConfig
{
    /**
     * Standards whose profile is fixed by the standard itself. Sending a profile
     * for these is a hard 422 from the API, so it must be omitted.
     */
    private const PROFILE_FIXED_STANDARDS = ['xrechnung', 'peppol-bis'];

    private const DEFAULT_BASE_URL = 'https://api.beliq.eu';
    private const DEFAULT_STANDARD = 'zugferd';
    private const DEFAULT_PROFILE = 'en16931';
    private const DEFAULT_OUTPUT = 'pdf';
    private const DEFAULT_ZERO_RATE_CATEGORY = 'Z';

    /** WooCommerce order statuses generation can run on. */
    private const TRIGGER_PROCESSING = 'processing';
    private const TRIGGER_COMPLETED = 'completed';

    /** UNTDID 4461 credit-transfer payment means. 58 is SEPA credit transfer. */
    private const PAYMENT_MEANS_CODES = ['58', '30'];
    private const DEFAULT_PAYMENT_MEANS_CODE = '58';

    public function __construct(
        public bool $enabled,
        public string $apiKey,
        public string $baseUrl,
        public string $standard,
        public ?string $profile,
        public string $output,
        public bool $businessOnly,
        public string $triggerEvent,
        public string $zeroRateCategory,
        public Party $seller,
        public ?PaymentMeans $paymentMeans = null,
    ) {
    }

    /**
     * Map raw setting values (short keys) to a typed PluginConfig, applying
     * defaults and coercion. Static and free of instance state, so it maps a
     * plain array of settings without a WordPress runtime and can be asserted
     * directly.
     *
     * @param array<string, mixed> $values
     */
    public static function fromValues(array $values): self
    {
        $str = static function (string $key, string $default = '') use ($values): string {
            $value = $values[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };
        $nullable = static function (string $key) use ($str): ?string {
            $value = $str($key);

            return $value === '' ? null : $value;
        };
        $bool = static function (string $key, bool $default) use ($values): bool {
            return array_key_exists($key, $values) && $values[$key] !== null
                ? (bool) $values[$key]
                : $default;
        };

        $output = $str('output', self::DEFAULT_OUTPUT);
        $output = $output === 'xml' ? 'xml' : self::DEFAULT_OUTPUT;

        $trigger = $str('triggerEvent', self::TRIGGER_PROCESSING);
        if (!in_array($trigger, [self::TRIGGER_PROCESSING, self::TRIGGER_COMPLETED], true)) {
            $trigger = self::TRIGGER_PROCESSING;
        }

        $seller = new Party(
            name: $str('sellerName'),
            address: new Address(
                city: $str('sellerCity'),
                postalCode: $str('sellerPostalCode'),
                countryCode: strtoupper($str('sellerCountryCode')),
                street: $nullable('sellerStreet'),
            ),
            vatId: $nullable('sellerVatId'),
            taxId: $nullable('sellerTaxId'),
            registrationId: $nullable('sellerRegistrationId'),
            email: $nullable('sellerEmail'),
            phone: $nullable('sellerPhone'),
            contactName: $nullable('sellerContactName'),
        );

        // The payment means (BG-16) needs an account to be payable: without an
        // IBAN there is nothing to emit, and XRechnung's BR-DE-23-a rejects a
        // credit transfer (code 58) that carries no IBAN. So it is assembled only
        // when the merchant has configured a bank account.
        $iban = $nullable('sellerIban');
        $paymentMeansCode = $str('paymentMeansCode', self::DEFAULT_PAYMENT_MEANS_CODE);
        if (!in_array($paymentMeansCode, self::PAYMENT_MEANS_CODES, true)) {
            $paymentMeansCode = self::DEFAULT_PAYMENT_MEANS_CODE;
        }
        $paymentMeans = $iban === null ? null : new PaymentMeans(
            typeCode: $paymentMeansCode,
            iban: $iban,
            bic: $nullable('sellerBic'),
            bankName: $nullable('sellerBankName'),
        );

        return new self(
            enabled: $bool('enabled', false),
            apiKey: $str('apiKey'),
            baseUrl: $str('baseUrl', self::DEFAULT_BASE_URL),
            standard: $str('standard', self::DEFAULT_STANDARD),
            profile: $nullable('profile') ?? self::DEFAULT_PROFILE,
            output: $output,
            businessOnly: $bool('businessOnly', true),
            triggerEvent: $trigger,
            zeroRateCategory: $str('zeroRateCategory', self::DEFAULT_ZERO_RATE_CATEGORY),
            seller: $seller,
            paymentMeans: $paymentMeans,
        );
    }

    /**
     * Whether the plugin should generate for this order. With the business-only
     * scope (the default) a private-consumer order is skipped; see ROADMAP.md on
     * why the safe default is narrow.
     */
    public function allowsOrder(SourceOrder $order): bool
    {
        return !$this->businessOnly || $order->buyerIsBusiness();
    }

    /**
     * The profile to send for the configured standard. XRechnung and Peppol BIS
     * pin their own profile, so it is omitted (null) for those; the profile option
     * only applies to the ZUGFeRD / Factur-X family.
     */
    public function effectiveProfile(): ?string
    {
        return in_array($this->standard, self::PROFILE_FIXED_STANDARDS, true)
            ? null
            : $this->profile;
    }

    /**
     * The file extension the generated document will carry. XRechnung and Peppol
     * BIS are always XML; the ZUGFeRD / Factur-X family follows the output setting
     * (hybrid PDF or XML).
     */
    public function expectedFileType(): string
    {
        if (in_array($this->standard, self::PROFILE_FIXED_STANDARDS, true)) {
            return 'xml';
        }

        return $this->output === 'xml' ? 'xml' : 'pdf';
    }
}
