<?php

namespace App\Services\Scrapers;

use App\Services\HttpClient;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce Scraper
 *
 * Strategy:
 * 1. Try WooCommerce REST API (/wp-json/wc/v3/products) — fastest, cleanest
 * 2. Fall back to HTML crawl via category pages if API requires auth
 */
class WooCommerceScraper extends BaseScraper
{
    private bool  $useApi    = false;
    private array $catMap    = [];

    public function init(string $baseUrl, array $config): void
    {
        parent::init($baseUrl, $config);
        $this->probeApi();
    }

    public function streamProducts(): Generator
    {
        if ($this->useApi) {
            yield from $this->streamViaApi();
        } else {
            yield from $this->streamViaHtml();
        }
    }

    // ──────────────────────────────────────────────
    // API path
    // ──────────────────────────────────────────────

    private function probeApi(): void
    {
        $url  = "{$this->baseUrl}/wp-json/wc/v3/products?per_page=1";
        $body = HttpClient::get($url, $this->config);
        if ($body && str_starts_with(ltrim($body), '[')) {
            $this->useApi = true;
            Log::info('[WooCommerceScraper] Using REST API');
        } else {
            Log::info('[WooCommerceScraper] API unavailable, falling back to HTML');
        }
    }

    private function streamViaApi(): Generator
    {
        // Load category map first
        $this->loadApiCategories();

        $page    = 1;
        $perPage = 100;

        while (true) {
            $url  = "{$this->baseUrl}/wp-json/wc/v3/products?per_page={$perPage}&page={$page}&status=publish";
            $json = $this->get($url);
            if (!$json) break;

            $products = json_decode($json, true);
            if (empty($products) || !is_array($products)) break;

            foreach ($products as $p) {
                yield $this->mapApiProduct($p);
            }

            if (count($products) < $perPage) break;
            $page++;
        }
    }

    private function loadApiCategories(): void
    {
        $json = $this->get("{$this->baseUrl}/wp-json/wc/v3/products/categories?per_page=100");
        if (!$json) return;

        foreach (json_decode($json, true) ?? [] as $cat) {
            $this->catMap[$cat['id']] = [
                'name'   => $cat['name'],
                'parent' => $cat['parent'] ?? 0,
            ];
        }
    }

    private function resolveCategoryNames(array $cats): array
    {
        $names  = [];
        $parent = '';
        foreach ($cats as $cat) {
            $id = $cat['id'] ?? 0;
            if (isset($this->catMap[$id])) {
                $info = $this->catMap[$id];
                if ($info['parent'] > 0 && isset($this->catMap[$info['parent']])) {
                    $parent = $this->catMap[$info['parent']]['name'];
                }
                $names[] = $info['name'];
            } else {
                $names[] = $cat['name'] ?? '';
            }
        }
        return ['category' => $parent ?: ($names[0] ?? ''), 'sub' => end($names) ?: ''];
    }

    private function mapApiProduct(array $p): array
    {
        $catInfo = $this->resolveCategoryNames($p['categories'] ?? []);

        $images     = array_map(fn($i) => $this->upgradeImageResolution($i['src'] ?? ''), $p['images'] ?? []);
        $imageAlts  = array_map(fn($i) => $i['alt'] ?? '', $p['images'] ?? []);

        // Variants from attributes
        $variants = [];
        if (!empty($p['attributes'])) {
            foreach ($p['attributes'] as $attr) {
                foreach ($attr['options'] ?? [] as $option) {
                    $variants[] = [$attr['name'] => $option];
                }
            }
        }

        $specs = $this->buildSpecs($p);

        return [
            'title'         => html_entity_decode($p['name'] ?? ''),
            'description'   => $this->htmlToText($p['description'] ?? ''),
            'short_desc'    => $this->htmlToText($p['short_description'] ?? ''),
            'sku'           => $p['sku']   ?? '',
            'price'         => $p['price'] ?? '',
            'compare_price' => $p['regular_price'] !== ($p['sale_price'] ?? '') ? ($p['regular_price'] ?? '') : '',
            'category'      => $catInfo['category'],
            'sub_category'  => $catInfo['sub'],
            'brand'         => $p['brands'][0]['name'] ?? '',
            'variants'      => $variants,
            'stock_status'  => $p['stock_status'] === 'instock' ? 'In Stock' : 'Out of Stock',
            'specifications'=> $specs,
            'moq'           => '',
            'url'           => $p['permalink'] ?? '',
            'images'        => array_filter($images),
            'image_alts'    => $imageAlts,
            'tags'          => array_map(fn($t) => $t['name'] ?? '', $p['tags'] ?? []),
        ];
    }

    private function buildSpecs(array $p): string
    {
        $specs = [];
        foreach ($p['attributes'] ?? [] as $attr) {
            $name = $attr['name'] ?? '';
            $vals = implode(', ', $attr['options'] ?? []);
            $specs[] = "{$name}: {$vals}";
        }
        // Also check meta_data for fabric/material
        foreach ($p['meta_data'] ?? [] as $meta) {
            $key = strtolower($meta['key'] ?? '');
            if (preg_match('/fabric|material|composition|content/', $key)) {
                $specs[] = "{$meta['key']}: {$meta['value']}";
            }
        }
        return implode(' | ', $specs);
    }

    // ──────────────────────────────────────────────
    // HTML crawl fallback
    // ──────────────────────────────────────────────

    private function streamViaHtml(): Generator
    {
        $categories = $this->discoverCategories();

        foreach ($categories as $cat) {
            Log::info("[WooCommerceScraper] Crawling category: {$cat['title']}");
            $urls = $this->getProductUrlsFromCategory($cat['url']);

            foreach ($urls as $productUrl) {
                $html = $this->get($productUrl);
                if (!$html) continue;

                $product = $this->parseProductPage($productUrl, $html);
                if (!empty($product)) {
                    $product['category']     = $cat['title'];
                    $product['sub_category'] = $cat['sub'] ?? '';
                    yield $product;
                }
            }
        }
    }

    protected function discoverCategories(): array
    {
        // Try sitemap
        $cats = $this->parseSitemapForCategories();
        if (!empty($cats)) return $cats;

        // Parse main nav
        $html = $this->get($this->baseUrl);
        if (!$html) return [];

        return $this->extractNavCategories($html, 'product-category');
    }

    private function parseSitemapForCategories(): array
    {
        $sitemap = $this->get("{$this->baseUrl}/product-sitemap.xml") ??
                   $this->get("{$this->baseUrl}/wp-sitemap.xml");
        if (!$sitemap) return [];

        $cats = [];
        preg_match_all('/<loc>(https?[^<]+product-category[^<]+)<\/loc>/i', $sitemap, $m);
        foreach (array_unique($m[1] ?? []) as $url) {
            $cats[] = ['url' => $url, 'title' => $this->slugToTitle(basename($url)), 'sub' => ''];
        }
        return $cats;
    }

    private function extractNavCategories(string $html, string $urlKeyword = ''): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $html, $m);
        $cats = [];
        for ($i = 0; $i < count($m[1]); $i++) {
            $url   = $m[1][$i];
            $label = trim(strip_tags($m[2][$i]));
            if ($urlKeyword && !str_contains($url, $urlKeyword)) continue;
            if (strlen($label) > 2 && strlen($label) < 60) {
                $cats[] = ['url' => $this->absoluteUrl($url), 'title' => $label, 'sub' => ''];
            }
        }
        return $cats;
    }

    protected function getProductUrlsFromCategory(string $categoryUrl): array
    {
        $allUrls = [];
        $html    = $this->get($categoryUrl);
        if (!$html) return [];

        $pages = $this->getPaginatedUrls($categoryUrl, $html);

        foreach ($pages as $pageUrl) {
            $pageHtml = ($pageUrl === $categoryUrl) ? $html : ($this->get($pageUrl) ?? '');
            preg_match_all('/<a[^>]+href=["\']([^"\']+\/(?:product|shop)\/[^"\']+)["\'][^>]*>/i', $pageHtml, $m);
            foreach (array_unique($m[1]) as $url) {
                $allUrls[] = $this->absoluteUrl($url);
            }
        }

        return array_unique($allUrls);
    }

    protected function parseProductPage(string $url, string $html): array
    {
        // 1. Try JSON-LD first
        $product = $this->parseJsonLd($html);

        // 2. Supplement with WooCommerce-specific HTML
        if (empty($product['title'])) {
            preg_match('/<h1[^>]*class=["\'][^"\']*product[_-]title[^"\']*["\'][^>]*>(.*?)<\/h1>/si', $html, $m);
            $product['title'] = strip_tags($m[1] ?? '');
        }

        if (empty($product['price'])) {
            preg_match('/<span[^>]*class=["\'][^"\']*price[^"\']*["\'][^>]*>(.*?)<\/span>/si', $html, $m);
            $product['price'] = strip_tags($m[1] ?? '');
        }

        if (empty($product['description'])) {
            preg_match('/<div[^>]*class=["\'][^"\']*woocommerce-product-details__short-description[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $m);
            $product['short_desc'] = $this->htmlToText($m[1] ?? '');

            preg_match('/id=["\']tab-description["\'][^>]*>(.*?)<\/div>/si', $html, $m2);
            $product['description'] = $this->htmlToText($m2[1] ?? '');
        }

        // Images
        $imgData = $this->extractImages($html);
        if (empty($product['images'])) {
            $product['images']     = $imgData['urls'];
            $product['image_alts'] = $imgData['alts'];
        }

        // SKU
        if (empty($product['sku'])) {
            preg_match('/class=["\'][^"\']*sku[^"\']*["\'][^>]*>(.*?)<\/span>/si', $html, $m);
            $product['sku'] = trim(strip_tags($m[1] ?? ''));
        }

        // Stock
        if (empty($product['stock_status'])) {
            $product['stock_status'] = str_contains(strtolower($html), 'in-stock') ? 'In Stock' : 'Out of Stock';
        }

        $product['url']  = $url;
        $product['moq']  = '';
        $product['tags'] = $product['tags'] ?? [];

        return $product;
    }

    private function slugToTitle(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}