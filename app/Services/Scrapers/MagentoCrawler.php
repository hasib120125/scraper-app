<?php

namespace App\Services\Scrapers;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Magento Scraper
 * Supports Magento 2 REST API + HTML fallback.
 */
class MagentoCrawler extends BaseScraper
{
    private bool $useApi = false;

    public function init(string $baseUrl, array $config): void
    {
        parent::init($baseUrl, $config);
        $probe = $this->get("{$baseUrl}/rest/V1/categories");
        $this->useApi = $probe && str_contains($probe, '"id"');
        Log::info('[MagentoCrawler] API: ' . ($this->useApi ? 'yes' : 'no'));
    }

    public function streamProducts(): Generator
    {
        if ($this->useApi) {
            yield from $this->streamViaApi();
        } else {
            yield from $this->streamViaHtml();
        }
    }

    private function streamViaApi(): Generator
    {
        $page    = 1;
        $perPage = 50;

        while (true) {
            $url = "{$this->baseUrl}/rest/V1/products?"
                 . "searchCriteria[currentPage]={$page}"
                 . "&searchCriteria[pageSize]={$perPage}"
                 . "&searchCriteria[filter_groups][0][filters][0][field]=status"
                 . "&searchCriteria[filter_groups][0][filters][0][value]=1";

            $json = $this->get($url);
            if (!$json) break;

            $data = json_decode($json, true);
            if (empty($data['items'])) break;

            foreach ($data['items'] as $item) {
                yield $this->mapMagentoProduct($item);
            }

            if (count($data['items']) < $perPage) break;
            $page++;
        }
    }

    private function mapMagentoProduct(array $p): array
    {
        $customAttrs = [];
        foreach ($p['custom_attributes'] ?? [] as $attr) {
            $customAttrs[$attr['attribute_code']] = $attr['value'];
        }

        $images    = [];
        $imageAlts = [];
        foreach ($p['media_gallery_entries'] ?? [] as $media) {
            if ($media['media_type'] === 'image') {
                $images[]    = $this->upgradeImageResolution("{$this->baseUrl}/pub/media/catalog/product{$media['file']}");
                $imageAlts[] = $media['label'] ?? '';
            }
        }

        return [
            'title'         => $p['name']         ?? '',
            'description'   => $this->htmlToText($customAttrs['description'] ?? ''),
            'short_desc'    => $this->htmlToText($customAttrs['short_description'] ?? ''),
            'sku'           => $p['sku']           ?? '',
            'price'         => (string) ($p['price'] ?? ''),
            'compare_price' => '',
            'category'      => $customAttrs['category_ids'] ?? '',
            'sub_category'  => '',
            'brand'         => $customAttrs['manufacturer'] ?? '',
            'variants'      => [],
            'stock_status'  => ($p['status'] ?? 0) == 1 ? 'In Stock' : 'Out of Stock',
            'specifications'=> $this->buildSpecs($customAttrs),
            'moq'           => '',
            'url'           => "{$this->baseUrl}/{$p['custom_attributes'][0]['value']}.html",
            'images'        => $images,
            'image_alts'    => $imageAlts,
            'tags'          => [],
        ];
    }

    private function buildSpecs(array $attrs): string
    {
        $specs = [];
        $fabricKeys = ['fabric', 'material', 'composition', 'fiber_content', 'fabric_type'];
        foreach ($fabricKeys as $key) {
            if (!empty($attrs[$key])) {
                $specs[] = "{$key}: {$attrs[$key]}";
            }
        }
        return implode(' | ', $specs);
    }

    private function streamViaHtml(): Generator
    {
        $generic = new GenericScraper();
        $generic->init($this->baseUrl, $this->config);
        yield from $generic->streamProducts();
    }

    protected function discoverCategories(): array { return []; }
    protected function getProductUrlsFromCategory(string $u): array { return []; }
    protected function parseProductPage(string $u, string $h): array { return []; }
}