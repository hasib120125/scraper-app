<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ShopifyCsvExporter
 *
 * Outputs CSV in the exact Shopify product import format.
 * - One row per variant
 * - Product-level fields (Title, Description, Vendor, etc.) only on the FIRST variant row
 * - Subsequent variant rows have empty product-level columns
 * - Matches the column order from the official Shopify product template
 */
class ShopifyCsvExporter
{
    // ── Exact Shopify column headers (order matters) ──────────────────────────
    public static array $COLUMNS = [
        'Title',
        'URL handle',
        'Description',
        'Vendor',
        'Product category',
        'Type',
        'Tags',
        'Published on online store',
        'Status',
        'SKU',
        'Barcode',
        'Option1 name',
        'Option1 value',
        'Option1 Linked To',
        'Option2 name',
        'Option2 value',
        'Option2 Linked To',
        'Option3 name',
        'Option3 value',
        'Option3 Linked To',
        'Price',
        'Compare-at price',
        'Cost per item',
        'Charge tax',
        'Tax code',
        'Unit price total measure',
        'Unit price total measure unit',
        'Unit price base measure',
        'Unit price base measure unit',
        'Inventory tracker',
        'Inventory quantity',
        'Continue selling when out of stock',
        'Weight value (grams)',
        'Weight unit for display',
        'Requires shipping',
        'Fulfillment service',
        'Product image URL',
        'Image position',
        'Image alt text',
        'Variant image URL',
        'Gift card',
        'SEO title',
        'SEO description',
        'Color (product.metafields.shopify.color-pattern)',
        'Google Shopping / Google product category',
        'Google Shopping / Gender',
        'Google Shopping / Age group',
        'Google Shopping / Manufacturer part number (MPN)',
        'Google Shopping / Ad group name',
        'Google Shopping / Ads labels',
        'Google Shopping / Condition',
        'Google Shopping / Custom product',
        'Google Shopping / Custom label 0',
        'Google Shopping / Custom label 1',
        'Google Shopping / Custom label 2',
        'Google Shopping / Custom label 3',
        'Google Shopping / Custom label 4',
    ];

    // These columns are ONLY populated on the first variant row of a product
    private static array $PRODUCT_LEVEL_COLUMNS = [
        'Title', 'URL handle', 'Description', 'Vendor', 'Product category',
        'Type', 'Tags', 'Published on online store', 'Status',
        'Gift card', 'SEO title', 'SEO description',
        'Color (product.metafields.shopify.color-pattern)',
        'Google Shopping / Google product category',
        'Google Shopping / Gender',
        'Google Shopping / Age group',
        'Google Shopping / Ad group name',
        'Google Shopping / Ads labels',
        'Google Shopping / Condition',
        'Google Shopping / Custom product',
    ];

    private ?string $outputDir;
    private mixed   $handle   = null;
    private string  $filePath = '';

    public function __construct(?string $outputDir = null)
    {
        $this->outputDir = $outputDir ?? storage_path('exports');
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    public function open(string $filename): void
    {
        $this->filePath = $this->outputDir . DIRECTORY_SEPARATOR . $filename;
        $this->handle   = fopen($this->filePath, 'w');

        // UTF-8 BOM — ensures Excel opens without encoding issues
        fwrite($this->handle, "\xEF\xBB\xBF");

        fputcsv($this->handle, self::$COLUMNS);

        Log::info("[ShopifyCsvExporter] Opened: {$this->filePath}");
    }

    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Write one product (which may produce multiple rows — one per variant).
     * The normalized $product array comes from ScraperEngine::normalizeProduct().
     */
    public function writeProduct(array $product): void
    {
        $variants = $this->buildVariantRows($product);

        foreach ($variants as $index => $variant) {
            $isFirst = $index === 0;

            // Image: first row gets position 1 + first image URL, additional images get their own rows
            $imageUrl = '';
            $imagePos = '';
            $imageAlt = '';

            $imageUrls = array_filter(explode(' | ', $product['image_urls'] ?? ''));
            $imageAlts = array_filter(explode(' | ', $product['image_alt_texts'] ?? ''));

            if ($isFirst && !empty($imageUrls)) {
                $imageUrl = $imageUrls[0];
                $imagePos = '1';
                $imageAlt = $imageAlts[0] ?? '';
            }

            $row = $this->buildRow($product, $variant, $isFirst, $imageUrl, $imagePos, $imageAlt);
            $this->writeRow($row);
        }

        // Write additional image rows (image position 2, 3, ... with empty variant data)
        $imageUrls = array_values(array_filter(explode(' | ', $product['image_urls'] ?? '')));
        $imageAlts = array_values(array_filter(explode(' | ', $product['image_alt_texts'] ?? '')));
        $handle    = $product['url_handle'] ?? Str::slug($product['product_title'] ?? '');

        for ($i = 1; $i < count($imageUrls); $i++) {
            $imageRow = array_fill_keys(self::$COLUMNS, '');
            $imageRow['URL handle']    = $handle;
            $imageRow['Product image URL'] = $imageUrls[$i];
            $imageRow['Image position']    = (string) ($i + 1);
            $imageRow['Image alt text']    = $imageAlts[$i] ?? '';
            $this->writeRow($imageRow);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildVariantRows(array $product): array
    {
        $variantString = $product['variants'] ?? '';

        // Parse variants: "Size: S | Size: M | Color: Red | Color: Blue"
        // Group by option name
        $options = [];
        if (!empty($variantString)) {
            $parts = array_map('trim', explode(' | ', $variantString));
            foreach ($parts as $part) {
                if (str_contains($part, ':')) {
                    [$name, $value] = array_map('trim', explode(':', $part, 2));
                    $options[$name][] = $value;
                }
            }
        }

        // Deduplicate option values
        foreach ($options as $k => $v) {
            $options[$k] = array_unique($v);
        }

        $optionNames  = array_keys($options);
        $optionValues = array_values($options);

        // No variants → single row
        if (empty($optionNames)) {
            return [['option1_name' => '', 'option1_value' => '', 'option2_name' => '', 'option2_value' => '', 'option3_name' => '', 'option3_value' => '']];
        }

        // Build cartesian product of up to 3 option dimensions
        $rows        = [];
        $dim1Values  = $optionValues[0] ?? [''];
        $dim2Values  = $optionValues[1] ?? [''];
        $dim3Values  = $optionValues[2] ?? [''];

        foreach ($dim1Values as $v1) {
            if (count($optionNames) === 1) {
                $rows[] = [
                    'option1_name'  => $optionNames[0],
                    'option1_value' => $v1,
                    'option2_name'  => '',
                    'option2_value' => '',
                    'option3_name'  => '',
                    'option3_value' => '',
                ];
            } else {
                foreach ($dim2Values as $v2) {
                    if (count($optionNames) === 2) {
                        $rows[] = [
                            'option1_name'  => $optionNames[0],
                            'option1_value' => $v1,
                            'option2_name'  => $optionNames[1],
                            'option2_value' => $v2,
                            'option3_name'  => '',
                            'option3_value' => '',
                        ];
                    } else {
                        foreach ($dim3Values as $v3) {
                            $rows[] = [
                                'option1_name'  => $optionNames[0],
                                'option1_value' => $v1,
                                'option2_name'  => $optionNames[1],
                                'option2_value' => $v2,
                                'option3_name'  => $optionNames[2] ?? '',
                                'option3_value' => $v3,
                            ];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    private function buildRow(array $product, array $variant, bool $isFirst, string $imageUrl, string $imagePos, string $imageAlt): array
    {
        $handle = $product['url_handle']
            ?? Str::slug($product['product_title'] ?? 'product');

        $row = array_fill_keys(self::$COLUMNS, '');

        // ── Variant-level fields (every row) ──────────────────────────────────
        $row['URL handle']                  = $handle;
        $row['SKU']                         = $product['sku']         ?? '';
        $row['Barcode']                     = $product['barcode']     ?? '';
        $row['Price']                       = $product['price']       ?? '';
        $row['Compare-at price']            = $product['compare_at_price'] ?? '';
        $row['Cost per item']               = $product['cost_per_item']    ?? '';
        $row['Charge tax']                  = 'TRUE';
        $row['Inventory tracker']           = 'shopify';
        $row['Inventory quantity']          = $product['stock_qty']   ?? '';
        $row['Continue selling when out of stock'] = 'DENY';
        $row['Weight value (grams)']        = $product['weight_grams'] ?? '';
        $row['Weight unit for display']     = 'g';
        $row['Requires shipping']           = 'TRUE';
        $row['Fulfillment service']         = 'manual';
        $row['Product image URL']           = $imageUrl;
        $row['Image position']              = $imagePos;
        $row['Image alt text']              = $imageAlt;
        $row['Variant image URL']           = '';

        // Options
        $row['Option1 name']   = $variant['option1_name']  ?? '';
        $row['Option1 value']  = $variant['option1_value'] ?? '';
        $row['Option1 Linked To'] = '';
        $row['Option2 name']   = $variant['option2_name']  ?? '';
        $row['Option2 value']  = $variant['option2_value'] ?? '';
        $row['Option2 Linked To'] = '';
        $row['Option3 name']   = $variant['option3_name']  ?? '';
        $row['Option3 value']  = $variant['option3_value'] ?? '';
        $row['Option3 Linked To'] = '';

        // ── Product-level fields (first row only) ─────────────────────────────
        if ($isFirst) {
            $row['Title']                   = $product['product_title']  ?? '';
            $row['Description']             = $product['full_description'] ?? '';
            $row['Vendor']                  = $product['brand']          ?? '';
            $row['Product category']        = $product['category']       ?? '';
            $row['Type']                    = $product['sub_category']   ?? '';
            $row['Tags']                    = $product['tags']           ?? '';
            $row['Published on online store'] = 'TRUE';
            $row['Status']                  = 'Active';
            $row['Gift card']               = 'FALSE';
            $row['SEO title']               = mb_substr($product['product_title'] ?? '', 0, 70);
            $row['SEO description']         = mb_substr(strip_tags($product['short_description'] ?? $product['full_description'] ?? ''), 0, 320);
            $row['Google Shopping / Google product category'] = $product['category'] ?? '';
            $row['Google Shopping / Condition']               = 'New';
        }

        return $row;
    }

    private function writeRow(array $row): void
    {
        // Ensure columns are in the correct order
        $ordered = [];
        foreach (self::$COLUMNS as $col) {
            $ordered[] = $row[$col] ?? '';
        }
        fputcsv($this->handle, $ordered);
    }
}