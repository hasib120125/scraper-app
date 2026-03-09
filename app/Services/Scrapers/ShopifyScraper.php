<?php

namespace App\Services\Scrapers;

use App\Services\HttpClient;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Shopify Scraper
 *
 * Shopify exposes a public JSON API at /products.json and /collections.json
 * — no HTML parsing needed. Much faster and more reliable.
 */
class ShopifyScraper extends BaseScraper
{
    private array $categories = [];

    public function streamProducts(): Generator
    {
        $categories = $this->discoverCategories();

        foreach ($categories as $cat) {
            Log::info("[ShopifyScraper] Scraping collection: {$cat['title']}");

            $page  = 1;
            $limit = 250;   // Shopify max per page

            while (true) {
                $apiUrl = "{$this->baseUrl}/collections/{$cat['handle']}/products.json?limit={$limit}&page={$page}";
                $json   = $this->get($apiUrl);

                if (!$json) break;

                $data = json_decode($json, true);
                if (empty($data['products'])) break;

                foreach ($data['products'] as $raw) {
                    yield $this->mapShopifyProduct($raw, $cat);
                }

                if (count($data['products']) < $limit) break;
                $page++;
            }
        }
    }

    protected function discoverCategories(): array
    {
        $json = $this->get("{$this->baseUrl}/collections.json?limit=250");
        if (!$json) return [['handle' => 'all', 'title' => 'All Products', 'parent' => '']];

        $data = json_decode($json, true);
        $cats = [];

        foreach ($data['collections'] ?? [] as $c) {
            $cats[] = [
                'handle' => $c['handle'],
                'title'  => $c['title'],
                'parent' => '',
            ];
        }

        // Store for sub-category lookup
        $this->categories = $cats;

        // Always include "all" as fallback
        if (!collect($cats)->contains('handle', 'all')) {
            array_unshift($cats, ['handle' => 'all', 'title' => 'All Products', 'parent' => '']);
        }

        return $cats;
    }

    protected function getProductUrlsFromCategory(string $categoryUrl): array
    {
        return []; // Not used – Shopify uses JSON API
    }

    protected function parseProductPage(string $url, string $html): array
    {
        return []; // Not used – Shopify uses JSON API
    }

    private function mapShopifyProduct(array $p, array $cat): array
    {
        // Collect images
        $imageUrls = [];
        $imageAlts = [];
        foreach ($p['images'] ?? [] as $img) {
            $src = $this->upgradeImageResolution($img['src'] ?? '');
            if ($src) {
                $imageUrls[] = $src;
                $imageAlts[] = $img['alt'] ?? '';
            }
        }

        // Variants
        $variantData = [];
        $prices      = [];
        foreach ($p['variants'] ?? [] as $v) {
            $entry = ['sku' => $v['sku'] ?? ''];
            foreach (['option1', 'option2', 'option3'] as $idx => $opt) {
                if (!empty($v[$opt]) && isset($p['options'][$idx])) {
                    $entry[$p['options'][$idx]['name']] = $v[$opt];
                }
            }
            $entry['_price'] = $v['price']          ?? '';
            $entry['_compare_price'] = $v['compare_at_price'] ?? '';
            $entry['_stock']  = $v['available'] ? 'In Stock' : 'Out of Stock';
            $variantData[]   = $entry;
            $prices[]        = (float) ($v['price'] ?? 0);
        }

        $lowestPrice   = !empty($prices) ? min($prices) : 0;
        $comparePrices = array_filter(array_column($variantData, '_compare_price'));
        $comparePrice  = !empty($comparePrices) ? max($comparePrices) : '';

        // Tags
        $tags = $p['tags'] ?? [];
        if (is_string($tags)) $tags = array_map('trim', explode(',', $tags));

        // Body HTML → clean text
        $description = $this->htmlToText($p['body_html'] ?? '');

        return [
            'title'        => $p['title']   ?? '',
            'description'  => $description,
            'short_desc'   => mb_substr($description, 0, 200),
            'sku'          => $p['variants'][0]['sku'] ?? '',
            'price'        => number_format($lowestPrice, 2, '.', ''),
            'compare_price'=> $comparePrice,
            'category'     => $cat['title'],
            'sub_category' => '',
            'brand'        => $p['vendor'] ?? '',
            'variants'     => $variantData,
            'stock_status' => ($p['variants'][0]['available'] ?? false) ? 'In Stock' : 'Out of Stock',
            'specifications'=> $this->extractSpecs($description),
            'moq'           => '',
            'url'           => "{$this->baseUrl}/products/{$p['handle']}",
            'images'        => $imageUrls,
            'image_alts'    => $imageAlts,
            'tags'          => $tags,
        ];
    }

    private function extractSpecs(string $text): string
    {
        // Pull lines that look like fabric/spec info
        $lines = explode("\n", $text);
        $specs = array_filter($lines, function ($line) {
            $lower = strtolower($line);
            return preg_match('/fabric|material|composition|weight|gsm|poly|cotton|spandex|lycra|nylon|content|care|width|stretch/i', $lower);
        });
        return implode('; ', array_map('trim', $specs));
    }
}