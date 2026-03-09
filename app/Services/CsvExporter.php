<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CsvExporter
{
    private ?string $outputDir;
    private mixed   $handle   = null;
    private string  $filePath = '';
    private bool    $headerWritten = false;

    public static array $COLUMNS = [
        'product_title',
        'full_description',
        'short_description',
        'sku',
        'price',
        'compare_at_price',
        'category',
        'sub_category',
        'brand',
        'variants',
        'stock_status',
        'fabric_specs',
        'min_order_qty',
        'product_url',
        'image_urls',
        'image_alt_texts',
        'tags',
    ];

    public function __construct(?string $outputDir = null)
    {
        $this->outputDir = $outputDir ?? storage_path('exports');
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    public function open(string $filename): void
    {
        $this->filePath      = $this->outputDir . DIRECTORY_SEPARATOR . $filename;
        $this->handle        = fopen($this->filePath, 'w');
        $this->headerWritten = false;

        // UTF-8 BOM so Excel opens correctly
        fwrite($this->handle, "\xEF\xBB\xBF");

        $this->writeHeader();
        Log::info("[CsvExporter] Opened: {$this->filePath}");
    }

    public function writeRow(array $product): void
    {
        if (!$this->handle) {
            throw new \RuntimeException('CsvExporter: call open() before writeRow()');
        }

        $row = [];
        foreach (self::$COLUMNS as $col) {
            $value = $product[$col] ?? '';
            // Normalise newlines — keep them as \n inside quotes (Excel handles it)
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $row[] = $value;
        }

        fputcsv($this->handle, $row, ',', '"', '\\');
    }

    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
            Log::info("[CsvExporter] Closed: {$this->filePath}");
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    private function writeHeader(): void
    {
        fputcsv($this->handle, self::$COLUMNS, ',', '"', '\\');
        $this->headerWritten = true;
    }

    /**
     * One-shot: write an entire products array to a new file.
     */
    public function exportAll(array $products, string $filename): string
    {
        $this->open($filename);
        foreach ($products as $product) {
            $this->writeRow($product);
        }
        $this->close();
        return $this->filePath;
    }
}