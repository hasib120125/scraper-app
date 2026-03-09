<?php

namespace App\Services\Scrapers;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Generic HTML Scraper
 *
 * Works on any e-commerce site using heuristic selectors.
 * Covers: custom sites, BigCommerce, OpenCart, PrestaShop, etc.
 */
class GenericScraper extends BaseScraper
{
    // Ordered list of CSS-like selector heuristics (regex-based)
    private const TITLE_PATTERNS = [
        '/<h1[^>]*(?:class|id)=["\'][^"\']*(?:product[_-]?title|product[_-]?name|entry[_-]?title)[^"\']*["\'][^>]*>(.*?)<\/h1>/si',
        '/<h1[^>]*itemprop=["\']name["\'][^>]*>(.*?)<\/h1>/si',
        '/<h1[^>]*>(.*?)<\/h1>/si',
    ];

    private const PRICE_PATTERNS = [
        '/<(?:span|div|p)[^>]*(?:class|id)=["\'][^"\']*(?:price|product[_-]price|sale[_-]price|current[_-]price)[^"\']*["\'][^>]*>(.*?)<\/(?:span|div|p)>/si',
        '/itemprop=["\']price["\'][^>]*content=["\']([0-9.,]+)["\']/',
        '/itemprop=["\']price["\'][^>]*>(.*?)<\//',
    ];

    private const DESC_PATTERNS = [
        '/<(?:div|section)[^>]*(?:class|id)=["\'][^"\']*(?:product[_-]?description|product[_-]?details|woocommerce-product|pdp[_-]description|full[_-]desc)[^"\']*["\'][^>]*>(.*?)<\/(?:div|section)>/si',
        '/<div[^>]*itemprop=["\']description["\'][^>]*>(.*?)<\/div>/si',
    ];

    private const SHORT_DESC_PATTERNS = [
        '/<(?:div|p)[^>]*(?:class|id)=["\'][^"\']*(?:short[_-]?desc|product[_-]?summary|product[_-]?excerpt)[^"\']*["\'][^>]*>(.*?)<\/(?:div|p)>/si',
    ];

    private const SKU_PATTERNS = [
        '/(?:SKU|Product\s+Code|Item\s+No|Style\s+No|Model\s+No)[:\s#]+([A-Za-z0-9\-_]+)/i',
        '/(?:class|id)=["\'][^"\']*sku[^"\']*["\'][^>]*>(.*?)<\//si',
        '/itemprop=["\']sku["\'][^>]*content=["\']([^"\']+)["\']/i',
        '/itemprop=["\']sku["\'][^>]*>(.*?)<\//si',
    ];

    public function streamProducts(): Generator
    {
        $categories = $this->discoverCategories();

        if (empty($categories)) {
            Log::warning('[GenericScraper] No categories found; attempting full-site product hunt');
            $categories = [['url' => $this->baseUrl, 'title' => 'All', 'sub' => '']];
        }

        $scrapedUrls = [];

        foreach ($categories as $cat) {
            Log::info("[GenericScraper] Category: {$cat['title']}");
            $productUrls = $this->getProductUrlsFromCategory($cat['url']);

            foreach ($productUrls as $pUrl) {
                if (isset($scrapedUrls[$pUrl])) continue;
                $scrapedUrls[$pUrl] = true;

                $html = $this->get($pUrl);
                if (!$html) continue;

                $product = $this->parseProductPage($pUrl, $html);
                if (empty($product['title'])) {
                    yield ['_error' => "No title found at: {$pUrl}"];
                    continue;
                }

                $product['category']     = $product['category']     ?: $cat['title'];
                $product['sub_category'] = $product['sub_category'] ?: ($cat['sub'] ?? '');
                yield $product;
            }
        }
    }

    protected function discoverCategories(): array
    {
        // 1. Try XML sitemap
        $cats = $this->discoverFromSitemap();
        if (!empty($cats)) return $cats;

        // 2. Parse navigation
        $html = $this->get($this->baseUrl);
        if (!$html) return [];

        return $this->discoverFromNav($html);
    }

    private function discoverFromSitemap(): array
    {
        $sitemapUrls = [
            "{$this->baseUrl}/sitemap.xml",
            "{$this->baseUrl}/sitemap_index.xml",
            "{$this->baseUrl}/sitemap-categories.xml",
        ];

        foreach ($sitemapUrls as $sitemapUrl) {
            $xml = $this->get($sitemapUrl);
            if (!$xml) continue;

            // Handle sitemap index (contains other sitemaps)
            if (str_contains($xml, '<sitemapindex')) {
                preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $m);
                foreach ($m[1] as $sub) {
                    if (preg_match('/categor|collection/i', $sub)) {
                        $subXml = $this->get(trim($sub));
                        if ($subXml) $xml .= $subXml;
                    }
                }
            }

            preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $locs);
            $cats = [];

            foreach ($locs[1] as $loc) {
                $loc = trim($loc);
                if (preg_match('/(?:categor|collection|department|shop\/)/i', $loc)) {
                    $cats[] = [
                        'url'   => $loc,
                        'title' => $this->urlToTitle($loc),
                        'sub'   => '',
                    ];
                }
            }

            if (!empty($cats)) return $cats;
        }

        return [];
    }

    private function discoverFromNav(string $html): array
    {
        // Extract nav/menu links
        preg_match('/<(?:nav|ul|div)[^>]*(?:class|id)=["\'][^"\']*(?:nav|menu|navigation)[^"\']*["\'][^>]*>(.*?)<\/(?:nav|ul|div)>/si', $html, $navMatch);
        $navHtml = $navMatch[1] ?? $html;

        preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>([^<]{2,50})<\/a>/i', $navHtml, $m);

        $cats = [];
        for ($i = 0; $i < count($m[1]); $i++) {
            $href  = $m[1][$i];
            $label = trim(html_entity_decode(strip_tags($m[2][$i])));

            // Skip non-category links
            if (str_contains($href, '#') || preg_match('/\.(jpg|pdf|css|js)/i', $href)) continue;
            if (preg_match('/(?:login|register|account|cart|checkout|contact|about|blog|faq|search)/i', $href)) continue;
            if (preg_match('/(?:login|register|account|cart|checkout|contact|about|blog|faq|search)/i', $label)) continue;

            $abs = $this->absoluteUrl($href);
            if (!str_starts_with($abs, $this->baseUrl)) continue;

            $cats[] = ['url' => $abs, 'title' => $label, 'sub' => ''];
        }

        // De-dupe by URL
        $seen = [];
        return array_filter($cats, function ($c) use (&$seen) {
            if (isset($seen[$c['url']])) return false;
            return $seen[$c['url']] = true;
        });
    }

    protected function getProductUrlsFromCategory(string $categoryUrl): array
    {
        $allUrls = [];
        $html    = $this->get($categoryUrl);
        if (!$html) return [];

        $pages = $this->getPaginatedUrls($categoryUrl, $html);

        foreach ($pages as $pageUrl) {
            $pageHtml = ($pageUrl === $categoryUrl) ? $html : ($this->get($pageUrl) ?? '');
            $found    = $this->extractProductLinksFromListing($pageHtml);
            $allUrls  = array_merge($allUrls, $found);
        }

        return array_unique($allUrls);
    }

    private function extractProductLinksFromListing(string $html): array
    {
        $urls = [];

        // Common product card containers
        preg_match_all(
            '/<(?:article|div|li)[^>]*(?:class|id)=["\'][^"\']*(?:product[_-]?item|product[_-]?card|product[_-]?thumb|product[_-]?loop|item[_-]?product)[^"\']*["\'][^>]*>.*?href=["\']([^"\']+)["\'].*?<\/(?:article|div|li)>/si',
            $html,
            $m
        );
        foreach ($m[1] as $url) {
            $urls[] = $this->absoluteUrl($url);
        }

        // Fallback: any link with /product/ or /p/ in the path
        if (empty($urls)) {
            preg_match_all('/<a[^>]+href=["\']([^"\']+\/(?:product|products|item|p)\/[^"\']+)["\'][^>]*>/i', $html, $m2);
            foreach ($m2[1] as $url) {
                $urls[] = $this->absoluteUrl($url);
            }
        }

        return array_unique($urls);
    }

    protected function parseProductPage(string $url, string $html): array
    {
        // Start with structured data
        $product = $this->parseJsonLd($html);

        // Title
        if (empty($product['title'])) {
            foreach (self::TITLE_PATTERNS as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $product['title'] = strip_tags($m[1]);
                    break;
                }
            }
        }

        // Price
        if (empty($product['price'])) {
            foreach (self::PRICE_PATTERNS as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $product['price'] = strip_tags($m[1]);
                    break;
                }
            }
        }

        // Description
        if (empty($product['description'])) {
            foreach (self::DESC_PATTERNS as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $product['description'] = $this->htmlToText($m[1]);
                    break;
                }
            }
        }

        // Short description
        if (empty($product['short_desc'])) {
            foreach (self::SHORT_DESC_PATTERNS as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $product['short_desc'] = $this->htmlToText($m[1]);
                    break;
                }
            }
        }

        // SKU
        if (empty($product['sku'])) {
            foreach (self::SKU_PATTERNS as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $product['sku'] = trim(strip_tags($m[1]));
                    break;
                }
            }
        }

        // Images
        if (empty($product['images'])) {
            // Try to narrow to product gallery container first
            preg_match('/<(?:div|figure|section)[^>]*(?:class|id)=["\'][^"\']*(?:product[_-]?gallery|product[_-]?images|product[_-]?photos|woocommerce-product-gallery)[^"\']*["\'][^>]*>(.*?)<\/(?:div|figure|section)>/si', $html, $galleryMatch);
            $searchArea = $galleryMatch[1] ?? $html;
            $imgData    = $this->extractImages($searchArea);
            $product['images']     = $imgData['urls'];
            $product['image_alts'] = $imgData['alts'];
        }

        // Brand
        if (empty($product['brand'])) {
            preg_match('/(?:brand|manufacturer|vendor)["\s>:]+([A-Za-z0-9\s&\-\.]{2,40})/i', strip_tags($html), $bm);
            $product['brand'] = trim($bm[1] ?? '');
        }

        // Stock
        if (empty($product['stock_status'])) {
            if (preg_match('/out[_\- ]of[_\- ]stock|unavailable|sold[_\- ]out/i', $html)) {
                $product['stock_status'] = 'Out of Stock';
            } elseif (preg_match('/in[_\- ]stock|add[_\- ]to[_\- ]cart|available/i', $html)) {
                $product['stock_status'] = 'In Stock';
            }
        }

        // Variants from select/radio inputs
        if (empty($product['variants'])) {
            $product['variants'] = $this->extractVariants($html);
        }

        // Specifications / Fabric
        $product['specifications'] = $this->extractSpecifications($html, $product['description'] ?? '');

        // MOQ
        preg_match('/(?:minimum|min)[_\- ]?order(?:[_\- ]?qty|[_\- ]?quantity)?[:\s]+(\d+)/i', strip_tags($html), $moqM);
        $product['moq'] = $moqM[1] ?? '';

        // Tags (meta keywords fallback)
        if (empty($product['tags'])) {
            preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $km);
            $product['tags'] = !empty($km[1]) ? array_map('trim', explode(',', $km[1])) : [];
        }

        $product['url']       = $url;
        $product['category']  = $product['category']  ?? '';
        $product['sub_category'] = $product['sub_category'] ?? '';

        return $product;
    }

    private function extractVariants(string $html): array
    {
        $variants = [];

        // <select> elements
        preg_match_all('/<select[^>]*name=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/si', $html, $selects);
        for ($i = 0; $i < count($selects[1]); $i++) {
            $name = $selects[1][$i];
            preg_match_all('/<option[^>]*value=["\']([^"\']+)["\'][^>]*>([^<]+)<\/option>/i', $selects[2][$i], $opts);
            if (!empty($opts[2])) {
                foreach ($opts[2] as $opt) {
                    $variants[] = [trim($name) => trim($opt)];
                }
            }
        }

        // Radio buttons (color/size swatches)
        preg_match_all('/(?:name|data-option-name)=["\']([^"\']*(?:color|colour|size|variant)[^"\']*)["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $radios);
        for ($i = 0; $i < count($radios[1]); $i++) {
            $variants[] = [trim($radios[1][$i]) => trim($radios[2][$i])];
        }

        return $variants;
    }

    private function extractSpecifications(string $html, string $description): string
    {
        $specs = [];

        // Look for spec tables
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/si', $row, $cells);
            $cellTexts = array_map(fn($c) => trim(strip_tags($c)), $cells[1] ?? []);
            if (count($cellTexts) >= 2) {
                $key = $cellTexts[0];
                $val = $cellTexts[1];
                if (preg_match('/fabric|material|content|composition|weight|gsm|care|width/i', $key)) {
                    $specs[] = "{$key}: {$val}";
                }
            }
        }

        // Extract from description text
        $lines = explode("\n", $description);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/(?:fabric|material|composition|content|care|gsm|weight|width|stretch)[:\-\s]/i', $line) && strlen($line) < 200) {
                $specs[] = $line;
            }
        }

        return implode(' | ', array_unique($specs));
    }

    private function urlToTitle(string $url): string
    {
        $path  = parse_url($url, PHP_URL_PATH);
        $parts = array_filter(explode('/', $path));
        $last  = end($parts) ?: '';
        return ucwords(str_replace(['-', '_'], ' ', $last));
    }
}