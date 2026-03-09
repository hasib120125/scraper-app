<?php

namespace App\Services\Scrapers;

use App\Services\HttpClient;
use Generator;
use Illuminate\Support\Facades\Log;

abstract class BaseScraper
{
    protected string $baseUrl;
    protected array  $config;
    protected array  $visitedUrls = [];

    public function init(string $baseUrl, array $config): void
    {
        $this->baseUrl = $baseUrl;
        $this->config  = $config;
    }

    /**
     * Must yield product arrays one at a time (memory efficient).
     * Yield ['_error' => '...'] to report a non-fatal error.
     */
    abstract public function streamProducts(): Generator;

    /**
     * Return list of category URLs to crawl.
     */
    abstract protected function discoverCategories(): array;

    /**
     * Fetch all product URLs within a category page URL (handles pagination).
     */
    abstract protected function getProductUrlsFromCategory(string $categoryUrl): array;

    /**
     * Parse a single product page and return a normalized array.
     */
    abstract protected function parseProductPage(string $url, string $html): array;

    // ──────────────────────────────────────────────
    // Shared helpers available to all scrapers
    // ──────────────────────────────────────────────

    protected function get(string $url): ?string
    {
        if (isset($this->visitedUrls[$url])) return null;
        $this->visitedUrls[$url] = true;

        $html = HttpClient::get($url, $this->config);
        usleep($this->config['delay_ms'] * 1000);
        return $html;
    }

    protected function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'http')) return $href;
        if (str_starts_with($href, '//'))   return 'https:' . $href;
        if (str_starts_with($href, '/'))    return $this->baseUrl . $href;
        return $this->baseUrl . '/' . $href;
    }

    /**
     * Follow pagination: gather all paginated URLs for a category.
     * Looks for rel="next" or common ?page=N / /page/N patterns.
     */
    protected function getPaginatedUrls(string $firstPage, string $html): array
    {
        $pages = [$firstPage];

        // rel="next" links
        preg_match_all('/<a[^>]+rel=["\']next["\'][^>]*href=["\']([^"\']+)["\']|href=["\']([^"\']+)["\'][^>]*rel=["\']next["\']/i', $html, $m);
        $nextLinks = array_filter(array_merge($m[1], $m[2]));

        foreach ($nextLinks as $link) {
            $abs = $this->absoluteUrl($link);
            if (!in_array($abs, $pages)) {
                $pages[] = $abs;
            }
        }

        // Numeric pagination: detect max page and build URLs
        if (count($pages) === 1) {
            preg_match_all('/[?&\/]page[=\/](\d+)/i', $html, $pm);
            if (!empty($pm[1])) {
                $maxPage = max(array_map('intval', $pm[1]));
                for ($p = 2; $p <= $maxPage; $p++) {
                    $pages[] = $this->buildPageUrl($firstPage, $p);
                }
            }
        }

        return array_unique($pages);
    }

    protected function buildPageUrl(string $base, int $page): string
    {
        // Prefer query string format
        $sep = str_contains($base, '?') ? '&' : '?';
        return "{$base}{$sep}page={$page}";
    }

    /**
     * Extract image URLs + alt texts from HTML.
     */
    protected function extractImages(string $html, string $context = ''): array
    {
        $urls = [];
        $alts = [];

        // Standard <img> tags
        preg_match_all('/<img[^>]+>/i', $html, $imgTags);
        foreach ($imgTags[0] as $tag) {
            // src / data-src / data-lazy-src / data-original
            preg_match('/(?:data-src|data-lazy-src|data-original|src)\s*=\s*["\']([^"\']+)["\']/', $tag, $srcM);
            preg_match('/alt\s*=\s*["\']([^"\']*)["\']/', $tag, $altM);

            if (!empty($srcM[1])) {
                $src = $this->absoluteUrl($srcM[1]);
                // Filter out tiny icons / base64
                if (!str_contains($src, 'data:') && $this->looksLikeProductImage($src)) {
                    $urls[] = $this->upgradeImageResolution($src);
                    $alts[] = trim($altM[1] ?? '');
                }
            }
        }

        // srcset — pick highest resolution
        preg_match_all('/srcset\s*=\s*["\']([^"\']+)["\']/', $html, $ssM);
        foreach ($ssM[1] as $srcset) {
            $best = $this->pickBestSrcset($srcset);
            if ($best && !in_array($best, $urls)) {
                $urls[] = $best;
                $alts[] = '';
            }
        }

        return ['urls' => array_values(array_unique($urls)), 'alts' => $alts];
    }

    protected function looksLikeProductImage(string $url): bool
    {
        $lower = strtolower($url);
        // Skip logos, banners, icons
        foreach (['logo', 'banner', 'icon', 'sprite', 'placeholder', 'loader', 'pixel.gif', '1x1', 'blank'] as $skip) {
            if (str_contains($lower, $skip)) return false;
        }
        // Must be an image extension
        return (bool) preg_match('/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $lower);
    }

    protected function upgradeImageResolution(string $url): string
    {
        // Shopify: swap _small, _medium, _large with _1024x1024 or remove size suffix
        $url = preg_replace('/_(pico|icon|thumb|small|compact|medium|large|grande|1024x1024|2048x2048)(\.[a-z]+)/i', '$2', $url);
        // WooCommerce: remove -300x300 type suffixes
        $url = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $url);
        return $url;
    }

    protected function pickBestSrcset(string $srcset): ?string
    {
        $entries = array_map('trim', explode(',', $srcset));
        $best    = null;
        $bestW   = 0;

        foreach ($entries as $entry) {
            $parts = preg_split('/\s+/', trim($entry));
            $src   = $parts[0] ?? '';
            $w     = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($w > $bestW) {
                $bestW = $w;
                $best  = $src;
            }
        }

        return $best ? $this->absoluteUrl($best) : null;
    }

    /**
     * Parse structured data (JSON-LD / microdata) for product info.
     */
    protected function parseJsonLd(string $html): array
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $m);
        foreach ($m[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!$data) continue;

            // Handle @graph
            if (isset($data['@graph'])) {
                foreach ($data['@graph'] as $node) {
                    if (($node['@type'] ?? '') === 'Product') {
                        return $this->mapJsonLdProduct($node);
                    }
                }
            }
            if (($data['@type'] ?? '') === 'Product') {
                return $this->mapJsonLdProduct($data);
            }
        }
        return [];
    }

    protected function mapJsonLdProduct(array $data): array
    {
        $offers = $data['offers'] ?? ($data['offer'] ?? []);
        if (isset($offers[0])) $offers = $offers[0];

        $price   = $offers['price']        ?? ($offers['lowPrice'] ?? '');
        $sku     = $data['sku']            ?? ($offers['sku'] ?? '');
        $brand   = is_array($data['brand'] ?? '') ? ($data['brand']['name'] ?? '') : ($data['brand'] ?? '');

        $images = [];
        if (isset($data['image'])) {
            $images = is_array($data['image']) ? $data['image'] : [$data['image']];
        }

        return [
            'title'        => $data['name']        ?? '',
            'description'  => $data['description'] ?? '',
            'sku'          => $sku,
            'price'        => (string) $price,
            'brand'        => $brand,
            'stock_status' => $offers['availability'] ?? '',
            'images'       => array_map(fn($i) => is_array($i) ? ($i['url'] ?? '') : $i, $images),
            'image_alts'   => [],
        ];
    }

    /**
     * Strip all HTML tags but preserve line breaks as newlines.
     */
    protected function htmlToText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}