<?php

namespace roogul\seo\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\View;
use roogul\seo\fields\SeoField;
use roogul\seo\models\GlobalsModel;
use roogul\seo\models\SeoData;
use roogul\seo\Plugin;
use yii\base\Component;

/**
 * Resolves the meta for the current request and renders the tags.
 *
 * Priority for each value:
 *   1. craft.seoByRoogul.set() from templates
 *   2. the element's SEO field
 *   3. the SEO field's fallback fields
 *   4. globals (site, then primary site, then built-in defaults)
 */
class Meta extends Component
{
    public const DESCRIPTION_LENGTH = 160;

    /** Whether the tags have been output for this request. */
    public bool $rendered = false;

    /** Template overrides from craft.seoByRoogul.set(). */
    private array $_overrides = [];

    /** Extra JSON-LD nodes from craft.seoByRoogul.schema(). */
    private array $_schema = [];

    private ?array $_resolved = null;

    // Template API
    // -------------------------------------------------------------------------

    /**
     * Supported keys: element, title, rawTitle, description, image (Asset or
     * URL), canonical, noindex, nofollow, type (og:type).
     */
    public function set(array $values): void
    {
        $this->_overrides = array_merge($this->_overrides, $values);
        $this->_resolved = null;
    }

    public function addSchema(array $node): void
    {
        $this->_schema[] = $node;
        $this->_resolved = null;
    }

    // Resolution
    // -------------------------------------------------------------------------

    public function resolve(): array
    {
        if ($this->_resolved !== null) {
            return $this->_resolved;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $globals = $plugin->getGlobals()->resolve($site->id);
        $o = $this->_overrides;

        $element = $o['element'] ?? Craft::$app->getUrlManager()->getMatchedElement() ?: null;
        $field = $element ? $this->findSeoField($element) : null;
        $data = $field ? SeoData::fromValue($element->getFieldValue($field->handle)) : new SeoData();

        // Title
        $title = $o['title'] ?? null;
        $rawTitle = (bool)($o['rawTitle'] ?? false);

        if ($title === null || $title === '') {
            $title = $data->title;
            $rawTitle = $rawTitle || $data->rawTitle;
        }

        // The homepage uses just the site name unless an SEO title is set.
        // A set title gets the template like any other page.
        if (!$this->isHomepage($element)) {
            if ($title === '' && $element && $field) {
                $title = $this->textFromSources($element, $field->getSourceHandles('title'));
            }

            if ($title === '' && $element) {
                $title = (string)($element->title ?? '');
            }
        }

        $pageTitle = $title !== '' ? $title : $globals->siteName;
        $fullTitle = $this->applyTemplate($pageTitle, $globals, $rawTitle || $title === '' || $pageTitle === $globals->siteName);

        // Description
        $description = (string)($o['description'] ?? '');

        if ($description === '') {
            $description = $data->description;
        }

        if ($description === '' && $element && $field) {
            $description = $this->textFromSources($element, $field->getSourceHandles('description'));
        }

        if ($description === '') {
            $description = $globals->description;
        }

        $description = $this->cleanText($description, self::DESCRIPTION_LENGTH);

        // Image
        $image = $o['image'] ?? null;

        if (!$image) {
            $image = $data->getImage();
        }

        if (!$image && $element && $field) {
            $image = $this->assetFromSources($element, $field->getSourceHandles('image'));
        }

        if (!$image) {
            $image = $globals->getImage();
        }

        $imageMeta = $this->imageMeta($image, $settings->imageWidth, $settings->imageHeight);

        // Canonical
        $canonical = (string)($o['canonical'] ?? '');

        if ($canonical === '') {
            $canonical = $data->canonical;
        }

        if ($canonical === '') {
            $canonical = $this->currentUrl($element);
        }

        $canonical = $this->absoluteUrl($canonical);

        // Robots
        $response = Craft::$app->getResponse();
        $isError = $response instanceof \yii\web\Response && $response->getStatusCode() >= 400;
        $production = $settings->isProduction();

        $noindex = !$production || $isError || $globals->noindex || $data->noindex || !empty($o['noindex']);
        $nofollow = !$production || $data->nofollow || !empty($o['nofollow']);

        $robots = [
            $noindex ? 'noindex' : 'index',
            $nofollow ? 'nofollow' : 'follow',
        ];

        if (!$noindex) {
            $robots[] = 'max-image-preview:large';
        }

        $alternates = $element ? $this->alternates($element, $site) : [];

        $this->_resolved = [
            'element' => $element,
            'site' => $site,
            'globals' => $globals,
            'title' => $fullTitle,
            'pageTitle' => $pageTitle,
            'description' => $description,
            'image' => $imageMeta,
            'canonical' => $canonical,
            'robots' => implode(', ', $robots),
            'noindex' => $noindex,
            'alternates' => $alternates,
            'type' => $o['type'] ?? ($element instanceof Entry && !$this->isHomepage($element) ? 'article' : 'website'),
        ];

        $this->_resolved['schema'] = $this->schemaGraph($this->_resolved);

        return $this->_resolved;
    }

    /**
     * Fallback values for the SEO field's own UI (shown as placeholders).
     */
    public function fallbacksFor(ElementInterface $element, SeoField $field): array
    {
        $globals = Plugin::getInstance()->getGlobals()->resolve($element->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);

        $title = $this->textFromSources($element, $field->getSourceHandles('title'));

        if ($title === '') {
            $title = (string)($element->title ?? '');
        }

        $description = $this->textFromSources($element, $field->getSourceHandles('description'));
        $descriptionSource = $description !== '' ? 'field' : 'global';

        if ($description === '') {
            $description = $globals->description;
        }

        $image = $this->assetFromSources($element, $field->getSourceHandles('image')) ?? $globals->getImage();

        return [
            'title' => $title,
            'description' => $this->cleanText($description, self::DESCRIPTION_LENGTH),
            'descriptionSource' => $descriptionSource,
            'image' => $image,
            'siteName' => $globals->siteName,
            'titleTemplate' => $globals->titleTemplate,
            'url' => $element->getUrl() ?? UrlHelper::siteUrl('', null, null, $element->siteId),
            'isHomepage' => $this->isHomepage($element),
        ];
    }

    public function findSeoField(ElementInterface $element): ?SeoField
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SeoField) {
                return $field;
            }
        }

        return null;
    }

    // Output
    // -------------------------------------------------------------------------

    /**
     * Adds everything to the view so it's output by {{ head() }}.
     */
    public function registerTags(View $view): void
    {
        $meta = $this->resolve();
        $view->title = $meta['title'];
        $view->metaTags = array_merge($this->metaTags($meta), $view->metaTags);
        $this->rendered = true;

        if (!Plugin::getInstance()->getSettings()->isProduction()) {
            Craft::$app->getResponse()->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    /**
     * Everything as an HTML string, including <title>. For manual placement.
     */
    public function render(): string
    {
        $meta = $this->resolve();
        $this->rendered = true;

        return Html::tag('title', Html::encode($meta['title'])) . "\n" . implode("\n", $this->metaTags($meta));
    }

    /**
     * @return array<string, string>
     */
    private function metaTags(array $meta): array
    {
        /** @var GlobalsModel $globals */
        $globals = $meta['globals'];
        /** @var Site $site */
        $site = $meta['site'];
        $tags = [];

        $add = static function(string $key, string $html) use (&$tags) {
            $tags['seobr-' . $key] = $html;
        };

        if ($meta['description'] !== '') {
            $add('description', Html::tag('meta', '', ['name' => 'description', 'content' => $meta['description']]));
        }

        $add('robots', Html::tag('meta', '', ['name' => 'robots', 'content' => $meta['robots']]));

        if (!$meta['noindex']) {
            $add('canonical', Html::tag('link', '', ['rel' => 'canonical', 'href' => $meta['canonical']]));

            foreach ($meta['alternates'] as $i => $alt) {
                $add('alternate-' . $i, Html::tag('link', '', ['rel' => 'alternate', 'hreflang' => $alt['hreflang'], 'href' => $alt['url']]));
            }
        }

        // Open Graph
        $og = [
            'og:type' => $meta['type'],
            'og:site_name' => $globals->siteName,
            'og:title' => $meta['pageTitle'],
            'og:description' => $meta['description'],
            'og:url' => $meta['canonical'],
            'og:locale' => str_replace('-', '_', $site->getLanguage()),
        ];

        if ($meta['image']) {
            $og['og:image'] = $meta['image']['url'];
            $og['og:image:width'] = $meta['image']['width'];
            $og['og:image:height'] = $meta['image']['height'];
            $og['og:image:alt'] = $meta['image']['alt'];
        }

        foreach ($og as $property => $content) {
            if ($content !== null && $content !== '') {
                $add($property, Html::tag('meta', '', ['property' => $property, 'content' => (string)$content]));
            }
        }

        // X / Twitter (falls back to Open Graph for everything else)
        $add('twitter:card', Html::tag('meta', '', ['name' => 'twitter:card', 'content' => $meta['image'] ? 'summary_large_image' : 'summary']));

        if ($globals->twitterHandle !== '') {
            $add('twitter:site', Html::tag('meta', '', ['name' => 'twitter:site', 'content' => '@' . $globals->twitterHandle]));
        }

        // JSON-LD
        if ($meta['schema']) {
            $json = Json::encode(
                ['@context' => 'https://schema.org', '@graph' => $meta['schema']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            );
            $add('schema', Html::tag('script', $json, ['type' => 'application/ld+json']));
        }

        return $tags;
    }

    private function schemaGraph(array $meta): array
    {
        /** @var GlobalsModel $globals */
        $globals = $meta['globals'];
        /** @var Site $site */
        $site = $meta['site'];
        $element = $meta['element'];

        $home = $this->absoluteUrl(UrlHelper::siteUrl('', null, null, $site->id));
        $websiteId = rtrim($home, '/') . '/#website';
        $identityId = rtrim($home, '/') . '/#identity';

        $identity = [
            '@type' => $globals->entityType === GlobalsModel::ENTITY_PERSON ? 'Person' : 'Organization',
            '@id' => $identityId,
            'name' => $globals->entityName,
            'url' => $home,
        ];

        if ($logo = $globals->getEntityLogo()) {
            $logoUrl = $this->absoluteUrl((string)$logo->getUrl(null, true));
            $identity[$identity['@type'] === 'Person' ? 'image' : 'logo'] = [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ];
        }

        if ($sameAs = $globals->getSameAsUrls()) {
            $identity['sameAs'] = $sameAs;
        }

        $webPage = [
            '@type' => 'WebPage',
            '@id' => $meta['canonical'] . '#webpage',
            'url' => $meta['canonical'],
            'name' => $meta['pageTitle'],
            'isPartOf' => ['@id' => $websiteId],
            'inLanguage' => $site->getLanguage(),
        ];

        if ($meta['description'] !== '') {
            $webPage['description'] = $meta['description'];
        }

        if ($meta['image']) {
            $webPage['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $meta['image']['url']];
        }

        if ($element instanceof Entry) {
            if ($element->postDate) {
                $webPage['datePublished'] = $element->postDate->format(DATE_ATOM);
            }
            if ($element->dateUpdated) {
                $webPage['dateModified'] = $element->dateUpdated->format(DATE_ATOM);
            }
        }

        $graph = [
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'url' => $home,
                'name' => $globals->siteName,
                'publisher' => ['@id' => $identityId],
                'inLanguage' => $site->getLanguage(),
            ],
            $identity,
            $webPage,
        ];

        if ($element && !$this->isHomepage($element) && ($crumbs = $this->breadcrumbs($element, $home, $globals))) {
            $graph[] = $crumbs;
            $graph[2]['breadcrumb'] = ['@id' => $crumbs['@id']];
        }

        return array_merge($graph, $this->_schema);
    }

    private function breadcrumbs(ElementInterface $element, string $home, GlobalsModel $globals): ?array
    {
        $items = [['name' => $globals->siteName, 'url' => $home]];

        if ($element instanceof Element && $element->structureId) {
            foreach ($element->getAncestors()->all() as $ancestor) {
                if ($url = $ancestor->getUrl()) {
                    $items[] = ['name' => (string)$ancestor->title, 'url' => $this->absoluteUrl($url)];
                }
            }
        }

        $url = $element->getUrl();

        if (!$url) {
            return null;
        }

        $items[] = ['name' => (string)($element->title ?? ''), 'url' => $this->absoluteUrl($url)];

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $this->absoluteUrl($url) . '#breadcrumb',
            'itemListElement' => array_map(static fn($item, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ], $items, array_keys($items)),
        ];
    }

    // Helpers
    // -------------------------------------------------------------------------

    private function applyTemplate(string $title, GlobalsModel $globals, bool $raw): string
    {
        if ($raw || !str_contains($globals->titleTemplate, '{title}')) {
            return $title;
        }

        return strtr($globals->titleTemplate, [
            '{title}' => $title,
            '{siteName}' => $globals->siteName,
        ]);
    }

    private function isHomepage(?ElementInterface $element): bool
    {
        return $element && $element->uri === Element::HOMEPAGE_URI;
    }

    /**
     * First non-empty text value from a list of field handles. "title" means
     * the element's title.
     */
    private function textFromSources(ElementInterface $element, array $handles): string
    {
        foreach ($handles as $handle) {
            if ($handle === 'title') {
                $text = (string)($element->title ?? '');
            } else {
                $value = $this->fieldValue($element, $handle);

                if ($value === null || is_array($value) || $value instanceof ElementQueryInterface || $value instanceof \Illuminate\Support\Collection) {
                    continue;
                }

                if (is_object($value) && !method_exists($value, '__toString')) {
                    continue;
                }

                $text = (string)$value;
            }

            $text = $this->cleanText($text);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function assetFromSources(ElementInterface $element, array $handles): ?Asset
    {
        foreach ($handles as $handle) {
            $value = $this->fieldValue($element, $handle);
            $asset = null;

            if ($value instanceof ElementQueryInterface) {
                $asset = (clone $value)->one();
            } elseif ($value instanceof ElementCollection || $value instanceof \Illuminate\Support\Collection) {
                $asset = $value->first();
            } elseif (is_array($value)) {
                $asset = reset($value) ?: null;
            }

            if ($asset instanceof Asset && $asset->kind === Asset::KIND_IMAGE) {
                return $asset;
            }
        }

        return null;
    }

    private function fieldValue(ElementInterface $element, string $handle): mixed
    {
        if (!$element->getFieldLayout()?->getFieldByHandle($handle)) {
            return null;
        }

        try {
            return $element->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }
    }

    public function cleanText(string $text, ?int $limit = null): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if ($limit && mb_strlen($text) > $limit) {
            $cut = mb_substr($text, 0, $limit - 1);
            $space = mb_strrpos($cut, ' ');

            if ($space !== false && $space > $limit * 0.6) {
                $cut = mb_substr($cut, 0, $space);
            }

            $text = rtrim($cut, " ,.;:–—-") . '…';
        }

        return $text;
    }

    private function imageMeta(mixed $image, int $width, int $height): ?array
    {
        if (is_string($image) && $image !== '') {
            return ['url' => $this->absoluteUrl($image), 'width' => null, 'height' => null, 'alt' => null];
        }

        if (!$image instanceof Asset) {
            return null;
        }

        $transform = ['width' => $width, 'height' => $height, 'mode' => 'crop'];
        $url = $image->getUrl($transform, true);

        if (!$url) {
            return null;
        }

        return [
            'url' => $this->absoluteUrl($url),
            'width' => $width,
            'height' => $height,
            'alt' => $image->alt ?: $image->title,
        ];
    }

    private function currentUrl(?ElementInterface $element): string
    {
        $request = Craft::$app->getRequest();

        $pageNum = $request->getPageNum();

        if ($element && ($url = $element->getUrl()) && $pageNum <= 1) {
            return $url;
        }

        $url = strtok($request->getAbsoluteUrl(), '?');
        $pageTrigger = Craft::$app->getConfig()->getGeneral()->getPageTrigger();

        // Keep the page number when pagination uses a query param (e.g. ?page=2)
        if ($pageNum > 1 && str_starts_with($pageTrigger, '?')) {
            $url = UrlHelper::urlWithParams($url, [substr($pageTrigger, 1) => $pageNum]);
        }

        return $url;
    }

    public function absoluteUrl(string $url): string
    {
        if ($url === '' || UrlHelper::isAbsoluteUrl($url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, '/')) {
            $base = UrlHelper::siteUrl('');
            return UrlHelper::hostInfo(UrlHelper::isAbsoluteUrl($base) ? $base : Craft::$app->getRequest()->getHostInfo()) . $url;
        }

        return UrlHelper::siteUrl($url);
    }

    /**
     * hreflang links for the same element in the other sites of its group.
     */
    private function alternates(ElementInterface $element, Site $site): array
    {
        $sites = Craft::$app->getSites();
        $groupSites = $sites->getSitesByGroupId($site->groupId);

        if (count($groupSites) < 2) {
            return [];
        }

        $groupSiteIds = array_map(static fn(Site $s) => $s->id, $groupSites);

        $localized = $element::find()
            ->id($element->getCanonicalId())
            ->siteId($groupSiteIds)
            ->all();

        $alternates = [];
        $default = null;

        foreach ($localized as $local) {
            $url = $local->getUrl();
            $localSite = $sites->getSiteById($local->siteId);

            if (!$url || !$localSite) {
                continue;
            }

            $alternates[] = ['hreflang' => $localSite->getLanguage(), 'url' => $this->absoluteUrl($url)];

            if ($localSite->primary || $default === null) {
                $default = $this->absoluteUrl($url);
            }
        }

        if (count($alternates) < 2) {
            return [];
        }

        $alternates[] = ['hreflang' => 'x-default', 'url' => $default];

        return $alternates;
    }
}
