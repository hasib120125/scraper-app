<?php

namespace App\Services;

use App\Services\Scrapers\GenericScraper;
use App\Services\Scrapers\WooCommerceScraper;
use App\Services\Scrapers\ShopifyScraper;
use App\Services\Scrapers\MagentoCrawler;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ScraperEngine
{
    protected array $config;
    protected ShopifyCsvExporter $exporter;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'delay_ms'   => 1500,
            'max_retries'=> 3,
            'timeout'    => 30,
            'output_dir' => storage_path('exports'),
        ], $config);

        $this->exporter = new ShopifyCsvExporter($this->config['output_dir']);
    }

    public function scrape(string $siteUrl, ?string $outputFile = null): array
    {
        $siteUrl    = rtrim($siteUrl, '/');
        $outputFile = $outputFile ?? $this->buildOutputFilename($siteUrl);

        Log::info("[ScraperEngine] Starting: {$siteUrl}");

        $scraper = $this->resolveScraper($siteUrl);
        $scraper->init($siteUrl, $this->config);

        $stats = [
            'site'           => $siteUrl,
            'platform'       => class_basename($scraper),
            'total_products' => 0,
            'total_errors'   => 0,
            'output_file'    => $outputFile,
            'started_at'     => now()->toDateTimeString(),
        ];

        $this->exporter->open($outputFile);

        foreach ($scraper->streamProducts() as $raw) {
            if (isset($raw['_error'])) {
                $stats['total_errors']++;
                Log::warning('[ScraperEngine] ' . $raw['_error']);
                continue;
            }

            $normalized = $this->normalizeProduct($raw);
            $this->exporter->writeProduct($normalized);
            $stats['total_products']++;

            if ($stats['total_products'] % 25 === 0) {
                Log::info("[ScraperEngine] {$stats['total_products']} products scraped...");
            }
        }

        $this->exporter->close();
        $stats['finished_at'] = now()->toDateTimeString();
        $stats['output_path'] = $this->exporter->getFilePath();

        Log::info("[ScraperEngine] Done. {$stats['total_products']} products -> {$outputFile}");
        return $stats;
    }

    protected function resolveScraper(string $url): \App\Services\Scrapers\BaseScraper
    {
        $html = HttpClient::get($url, $this->config) ?? '';

        if (str_contains($html, 'cdn.shopify.com') || str_contains($html, 'Shopify.theme')) {
            Log::info('[ScraperEngine] Platform: Shopify');
            return new ShopifyScraper();
        }

        if (str_contains($html, 'woocommerce') || str_contains($html, 'wp-content/plugins/woocommerce')) {
            Log::info('[ScraperEngine] Platform: WooCommerce');
            return new WooCommerceScraper();
        }

        if (str_contains($html, 'Magento') || str_contains($html, 'mage/')) {
            Log::info('[ScraperEngine] Platform: Magento');
            return new MagentoCrawler();
        }

        Log::info('[ScraperEngine] Platform: Generic');
        return new GenericScraper();
    }

    protected function normalizeProduct(array $raw): array
    {
        $title       = $this->clean($raw['title']        ?? '');
        $description = $this->cleanDesc($raw['description'] ?? '');
        $shortDesc   = $this->clean($raw['short_desc']   ?? '');
        $tags        = is_array($raw['tags'] ?? '') ? implode(', ', $raw['tags']) : ($raw['tags'] ?? '');

        $variantStr  = $this->encodeVariants($raw['variants'] ?? []);

        $imageUrls = is_array($raw['images']     ?? '') ? $raw['images']     : explode(' | ', $raw['images']     ?? '');
        $imageAlts = is_array($raw['image_alts'] ?? '') ? $raw['image_alts'] : explode(' | ', $raw['image_alts'] ?? '');

        $stockStatus = strtolower($raw['stock_status'] ?? '');
        $stockQty    = str_contains($stockStatus, 'out') ? '0' : '';

        $specs = $this->clean($raw['specifications'] ?? '');
        $fullDescription = $description . ($description && $specs ? "\n\n" . $specs : ($specs ?: ''));

        return [
            'product_title'     => $title,
            'url_handle'        => Str::slug($title),
            'full_description'  => $fullDescription,
            'short_description' => $shortDesc ?: mb_substr(strip_tags($fullDescription), 0, 200),
            'sku'               => $this->clean($raw['sku']           ?? ''),
            'barcode'           => $this->clean($raw['barcode']       ?? ''),
            'price'             => $this->cleanPrice($raw['price']    ?? ''),
            'compare_at_price'  => $this->cleanPrice($raw['compare_price'] ?? ''),
            'cost_per_item'     => '',
            'category'          => $this->clean($raw['category']      ?? ''),
            'sub_category'      => $this->clean($raw['sub_category']  ?? ''),
            'brand'             => $this->clean($raw['brand']         ?? ''),
            'variants'          => $variantStr,
            'stock_status'      => $raw['stock_status'] ?? '',
            'stock_qty'         => $stockQty,
            'weight_grams'      => $this->clean($raw['weight']        ?? ''),
            'image_urls'        => implode(' | ', array_filter($imageUrls)),
            'image_alt_texts'   => implode(' | ', $imageAlts),
            'tags'              => $tags,
            'moq'               => $this->clean($raw['moq']           ?? ''),
            'product_url'       => $raw['url'] ?? '',
        ];
    }

    protected function clean(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function cleanDesc(string $text): string
    {
        $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $text);
        $text = preg_replace('/<style[^>]*>.*?<\/style>/si',   '', $text);
        $text = preg_replace('/<br\s*\/?>/i',  "\n", $text);
        $text = preg_replace('/<\/p>/i',       "\n", $text);
        $text = preg_replace('/<\/li>/i',      "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    protected function cleanPrice(string $price): string
    {
        $price = preg_replace('/[^0-9.,]/', '', $price);
        if (preg_match('/\d{1,3}(?:,\d{3})+(?:\.\d{2})?$/', $price)) {
            $price = str_replace(',', '', $price);
        }
        return $price;
    }

    protected function encodeVariants(array $variants): string
    {
        if (empty($variants)) return '';
        $options = [];
        foreach ($variants as $variant) {
            foreach ($variant as $key => $value) {
                if (str_starts_with($key, '_') || empty($key) || empty($value)) continue;
                $options[$key][] = $value;
            }
        }
        $parts = [];
        foreach ($options as $name => $values) {
            foreach (array_unique($values) as $val) {
                $parts[] = "{$name}: {$val}";
            }
        }
        return implode(' | ', $parts);
    }

    protected function buildOutputFilename(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9\-]/', '_', strtolower($host));
        return "shopify_{$host}_" . date('Ymd_His') . '.csv';
    }
}