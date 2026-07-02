<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Invoice;

use RuntimeException;
use WC_Order;

/**
 * Stores a generated invoice next to its order and serves it back for download.
 * The bytes live in a protected subdirectory of the WordPress uploads folder;
 * the stored file name, download name, content type, and generation time are
 * recorded in order meta through the CRUD API (HPOS-safe). Downloads always go
 * through the capability-checked PHP endpoint, and the directory carries a deny
 * rule as defense in depth against direct web access.
 */
final class DocumentStore
{
    private const SUBDIR = 'beliq-invoices';

    private const META_FILE = '_beliq_invoice_file';
    private const META_DOWNLOAD_NAME = '_beliq_invoice_download_name';
    private const META_CONTENT_TYPE = '_beliq_invoice_content_type';
    private const META_GENERATED_AT = '_beliq_invoice_generated_at';
    private const META_SCHEMATRON = '_beliq_invoice_schematron_version';

    /**
     * Write the document, replacing any earlier one for the order, and record its
     * location in order meta. Returns the stored file name.
     *
     * @param array<string, string> $meta header metadata from the beliq API
     */
    public function store(WC_Order $order, string $bytes, string $contentType, string $extension, array $meta): string
    {
        $dir = $this->ensureDir();
        $this->deleteStoredFile($order);

        $fileName = sanitize_file_name(sprintf(
            'beliq-invoice-%d-%s.%s',
            $order->get_id(),
            wp_generate_password(8, false),
            $extension === 'pdf' ? 'pdf' : 'xml',
        ));
        $path = $dir . '/' . $fileName;

        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Could not write the generated invoice to the uploads directory.');
        }

        $order->update_meta_data(self::META_FILE, $fileName);
        $order->update_meta_data(self::META_DOWNLOAD_NAME, 'invoice-' . $this->safeNumber($order) . '.' . ($extension === 'pdf' ? 'pdf' : 'xml'));
        $order->update_meta_data(self::META_CONTENT_TYPE, $contentType === '' ? 'application/octet-stream' : $contentType);
        $order->update_meta_data(self::META_GENERATED_AT, gmdate('c'));
        if (isset($meta['schematronVersion']) && $meta['schematronVersion'] !== '') {
            $order->update_meta_data(self::META_SCHEMATRON, $meta['schematronVersion']);
        }
        $order->save();

        return $fileName;
    }

    public function has(WC_Order $order): bool
    {
        return $this->resolvePath($order) !== null;
    }

    /**
     * The absolute path of the stored file, validated to sit inside the invoices
     * directory, or null when nothing is stored or the recorded name is unsafe.
     */
    public function resolvePath(WC_Order $order): ?string
    {
        $fileName = (string) $order->get_meta(self::META_FILE);
        if ($fileName === '' || $fileName !== basename($fileName)) {
            return null;
        }

        $base = realpath($this->dir());
        $path = realpath($this->dir() . '/' . $fileName);
        if ($base === false || $path === false) {
            return null;
        }

        return str_starts_with($path, $base . DIRECTORY_SEPARATOR) ? $path : null;
    }

    public function downloadName(WC_Order $order): string
    {
        $name = (string) $order->get_meta(self::META_DOWNLOAD_NAME);

        return $name !== '' ? sanitize_file_name($name) : 'invoice.xml';
    }

    /** A safe content type for the download header (PDF or XML only). */
    public function contentType(WC_Order $order): string
    {
        $stored = (string) $order->get_meta(self::META_CONTENT_TYPE);

        return str_contains($stored, 'pdf') ? 'application/pdf' : 'application/xml';
    }

    public function generatedAt(WC_Order $order): ?string
    {
        $value = (string) $order->get_meta(self::META_GENERATED_AT);

        return $value !== '' ? $value : null;
    }

    private function deleteStoredFile(WC_Order $order): void
    {
        $path = $this->resolvePath($order);
        if ($path !== null && is_file($path)) {
            wp_delete_file($path);
        }
    }

    private function dir(): string
    {
        $uploads = wp_upload_dir();

        return untrailingslashit($uploads['basedir']) . '/' . self::SUBDIR;
    }

    private function ensureDir(): string
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            throw new RuntimeException('WordPress uploads directory is not writable: ' . $uploads['error']);
        }

        $dir = $this->dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }

        return $dir;
    }

    private function safeNumber(WC_Order $order): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $order->get_order_number());

        return $number !== '' && $number !== null ? $number : (string) $order->get_id();
    }
}
