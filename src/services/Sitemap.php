<?php

namespace roogul\seo\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\models\Section;
use roogul\seo\models\SeoData;
use roogul\seo\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * XML sitemaps: an index per site, plus one paginated sitemap per source
 * (a section, all singles, a category group, or a Commerce product type).
 */
class Sitemap extends Component
{
    public const CACHE_TAG = 'seo-by-roogul:sitemap';
    public const PAGE_SIZE = 1000;

    /** Rebuild at least hourly so scheduled and expiring entries are picked up. */
    public const CACHE_DURATION = 3600;

    /**
     * Every source that has URLs in at least one site, for the settings page.
     *
     * @return array<string, array{key:string,type:string,handle:string,label:string}>
     */
    public function getAllSources(): array
    {
        $sources = [];

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            foreach ($this->sourcesForSite($siteId) as $key => $source) {
                $sources[$key] ??= $source;
            }
        }

        return $sources;
    }

    /**
     * Sources with URLs in the given site, regardless of whether they're included.
     *
     * @return array<string, array{key:string,type:string,handle:string,label:string}>
     */
    public function sourcesForSite(int $siteId): array
    {
        $sources = [];
        $hasSingles = false;

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSettings = $section->getSiteSettings()[$siteId] ?? null;

            if (!$siteSettings || !$siteSettings->hasUrls) {
                continue;
            }

            if ($section->type === Section::TYPE_SINGLE) {
                $hasSingles = true;
                continue;
            }

            $sources["entries-{$section->handle}"] = [
                'key' => "entries-{$section->handle}",
                'type' => 'entries',
                'handle' => $section->handle,
                'label' => $section->name,
            ];
        }

        if ($hasSingles) {
            $sources = ['singles-all' => [
                'key' => 'singles-all',
                'type' => 'singles',
                'handle' => 'all',
                'label' => Craft::t('seo-by-roogul', 'Singles'),
            ]] + $sources;
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $siteSettings = $group->getSiteSettings()[$siteId] ?? null;

            if (!$siteSettings || !$siteSettings->hasUrls) {
                continue;
            }

            $sources["categories-{$group->handle}"] = [
                'key' => "categories-{$group->handle}",
                'type' => 'categories',
                'handle' => $group->handle,
                'label' => $group->name . ' (' . Craft::t('seo-by-roogul', 'categories') . ')',
            ];
        }

        if ($this->commerceInstalled()) {
            foreach (\craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                $siteSettings = $type->getSiteSettings()[$siteId] ?? null;

                if (!$siteSettings || !$siteSettings->hasUrls) {
                    continue;
                }

                $sources["products-{$type->handle}"] = [
                    'key' => "products-{$type->handle}",
                    'type' => 'products',
                    'handle' => $type->handle,
                    'label' => $type->name . ' (' . Craft::t('seo-by-roogul', 'products') . ')',
                ];
            }
        }

        return $sources;
    }

    public function getIndexXml(int $siteId): string
    {
        return $this->cached("index:$siteId", function() use ($siteId) {
            $settings = Plugin::getInstance()->getSettings();
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $meta = Plugin::getInstance()->getMeta();
            $xml = [];

            foreach ($this->sourcesForSite($siteId) as $key => $source) {
                if (!$settings->isSourceIncluded($key)) {
                    continue;
                }

                $query = $this->query($source['type'], $source['handle'], $siteId);

                if (!$query) {
                    continue;
                }

                $count = (int)(clone $query)->count();
                $pages = (int)ceil($count / self::PAGE_SIZE);

                if ($pages === 0) {
                    continue;
                }

                $latest = (clone $query)->orderBy(['elements.dateUpdated' => SORT_DESC])->one();
                $lastmod = $latest?->dateUpdated?->format(DATE_W3C);

                for ($page = 1; $page <= $pages; $page++) {
                    $loc = $meta->absoluteUrl(\craft\helpers\UrlHelper::siteUrl("sitemap-{$key}-{$page}.xml", null, null, $site->id));
                    $xml[] = '<sitemap><loc>' . $this->e($loc) . '</loc>' .
                        ($lastmod ? '<lastmod>' . $lastmod . '</lastmod>' : '') .
                        '</sitemap>';
                }
            }

            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" .
                implode("\n", $xml) . "\n" .
                '</sitemapindex>';
        });
    }

    public function getPageXml(int $siteId, string $type, string $handle, int $page): ?string
    {
        $key = "$type-$handle";

        if (
            !Plugin::getInstance()->getSettings()->isSourceIncluded($key) ||
            !isset($this->sourcesForSite($siteId)[$key]) ||
            $page < 1
        ) {
            return null;
        }

        return $this->cached("page:$siteId:$key:$page", function() use ($siteId, $type, $handle, $page) {
            $query = $this->query($type, $handle, $siteId);

            if (!$query) {
                return null;
            }

            $elements = $query
                ->offset(($page - 1) * self::PAGE_SIZE)
                ->limit(self::PAGE_SIZE)
                ->all();

            if (!$elements && $page > 1) {
                return null;
            }

            $meta = Plugin::getInstance()->getMeta();
            $urls = [];

            foreach ($elements as $element) {
                $url = $element->getUrl();

                if (!$url || $this->isExcluded($element, $url)) {
                    continue;
                }

                $urls[] = '<url><loc>' . $this->e($meta->absoluteUrl($url)) . '</loc>' .
                    ($element->dateUpdated ? '<lastmod>' . $element->dateUpdated->format(DATE_W3C) . '</lastmod>' : '') .
                    '</url>';
            }

            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" .
                implode("\n", $urls) . "\n" .
                '</urlset>';
        });
    }

    public function invalidate(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    private function query(string $type, string $handle, int $siteId): ?ElementQueryInterface
    {
        $query = match ($type) {
            'entries' => Entry::find()->section($handle),
            'singles' => Entry::find()->section(array_map(
                static fn(Section $s) => $s->handle,
                array_filter(Craft::$app->getEntries()->getAllSections(), static fn(Section $s) => $s->type === Section::TYPE_SINGLE)
            )),
            'categories' => Category::find()->group($handle),
            'products' => $this->commerceInstalled() ? \craft\commerce\elements\Product::find()->type($handle) : null,
            default => null,
        };

        return $query?->siteId($siteId)->uri(':notempty:');
    }

    /**
     * Skip entries marked noindex, or canonicalised to another URL.
     */
    private function isExcluded(ElementInterface $element, string $url): bool
    {
        $field = Plugin::getInstance()->getMeta()->findSeoField($element);

        if (!$field) {
            return false;
        }

        $data = SeoData::fromValue($element->getFieldValue($field->handle));

        if ($data->noindex) {
            return true;
        }

        if ($data->canonical !== '') {
            $meta = Plugin::getInstance()->getMeta();
            return rtrim($meta->absoluteUrl($data->canonical), '/') !== rtrim($meta->absoluteUrl($url), '/');
        }

        return false;
    }

    private function cached(string $key, callable $generate): ?string
    {
        $cache = Craft::$app->getCache();
        $key = 'seo-by-roogul:sitemap:' . $key;
        $xml = $cache->get($key);

        if ($xml !== false) {
            return $xml;
        }

        $xml = $generate();

        if ($xml !== null) {
            $cache->set($key, $xml, self::CACHE_DURATION, new TagDependency(['tags' => [self::CACHE_TAG]]));
        }

        return $xml;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function commerceInstalled(): bool
    {
        return class_exists(\craft\commerce\Plugin::class) &&
            Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }
}
