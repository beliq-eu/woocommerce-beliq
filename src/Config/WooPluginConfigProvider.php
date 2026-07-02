<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Config;

/**
 * Reads the plugin settings stored by the WC_Integration into a typed
 * PluginConfig. The WordPress touch (reading the option) lives in get(); the
 * value-to-config mapping is the static fromRawSettings(), so the checkbox
 * coercion is asserted without a running WordPress.
 *
 * WooCommerce persists checkbox fields as the strings 'yes' / 'no'. Feeding
 * 'no' straight into PluginConfig::fromValues would read as boolean true (a
 * non-empty string), so the two checkbox keys are normalized to real booleans
 * here before the platform-neutral coercion runs.
 */
final class WooPluginConfigProvider
{
    /** Option key WooCommerce stores this integration's settings under (id "beliq"). */
    public const OPTION = 'woocommerce_beliq_settings';

    /** Settings backed by a WooCommerce checkbox (stored as 'yes' / 'no'). */
    private const CHECKBOX_KEYS = ['enabled', 'businessOnly'];

    public function get(): PluginConfig
    {
        $raw = get_option(self::OPTION, []);

        return self::fromRawSettings(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromRawSettings(array $raw): PluginConfig
    {
        foreach (self::CHECKBOX_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $raw[$key] = self::isYes($raw[$key]);
            }
        }

        return PluginConfig::fromValues($raw);
    }

    private static function isYes(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || (is_string($value) && strtolower($value) === 'yes');
    }
}
